<?php
declare(strict_types=1);

namespace Davyn\Dav\Sabre;

use Davyn\Dav\CalendarObjectRepository;
use Davyn\Dav\CalendarRepository;
use Davyn\Sharing\SharingService;
use Davyn\User\UserRepository;
use Sabre\CalDAV\Backend\AbstractBackend;
use Sabre\CalDAV\Backend\SyncSupport;
use Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet;
use Sabre\DAV\Exception\BadRequest;
use Sabre\DAV\PropPatch;

class CalDavBackend extends AbstractBackend implements SyncSupport
{
    public function __construct(
        private readonly CalendarRepository       $calendars,
        private readonly CalendarObjectRepository $objects,
        private readonly UserRepository           $users,
        private readonly SharingService           $sharing,
    ) {}

    public function getCalendarsForUser($principalUri): array
    {
        $username = basename($principalUri);
        $user = $this->users->findByUsername($username);
        if ($user === null || !$user->isActive) {
            return [];
        }

        $accessible = $this->sharing->listAccessibleCalendarsForUser($user->id);
        $result     = [];

        foreach ($accessible as $row) {
            $isOwned = ($row['permission'] === 'owner');
            $uri     = $isOwned
                ? $row['uri']
                : 'shared-' . $row['owner_username'] . '-' . $row['uri'];
            $name    = $isOwned
                ? $row['display_name']
                : $row['display_name'] . ' (shared by ' . $row['owner_username'] . ')';

            // Load full calendar row for sync_token, color, description
            $full = $this->calendars->findById((int) $row['id']);

            $result[] = [
                'id'           => (int) $row['id'],
                'uri'          => $uri,
                'principaluri' => $principalUri,
                '{DAV:}displayname'
                    => $name,
                '{urn:ietf:params:xml:ns:caldav}calendar-description'
                    => $full['description'] ?? '',
                '{http://apple.com/ns/ical/}calendar-color'
                    => $full['color'] ?? '',
                '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set'
                    => new SupportedCalendarComponentSet(['VEVENT', 'VTODO']),
                '{http://sabredav.org/ns}sync-token'
                    => (string) ($full['sync_token'] ?? 1),
            ];
        }

        return $result;
    }

    public function createCalendar($principalUri, $calendarUri, array $properties): string
    {
        throw new \Sabre\DAV\Exception\NotImplemented('createCalendar is not implemented');
    }

    public function updateCalendar($calendarId, PropPatch $propPatch): void {}

    public function deleteCalendar($calendarId): void
    {
        throw new \Sabre\DAV\Exception\NotImplemented('deleteCalendar is not implemented');
    }

    public function getCalendarObjects($calendarId): array
    {
        return array_map(
            fn(array $row) => $this->rowToObject($row),
            $this->objects->listObjects((int) $calendarId)
        );
    }

    public function getCalendarObject($calendarId, $objectUri): ?array
    {
        $row = $this->objects->getObject((int) $calendarId, $objectUri);
        return $row !== null ? $this->rowToObject($row) : null;
    }

    public function getMultipleCalendarObjects($calendarId, array $uris): array
    {
        return array_values(array_filter(
            array_map(
                fn(string $uri) => $this->getCalendarObject($calendarId, $uri),
                $uris
            )
        ));
    }

    public function createCalendarObject($calendarId, $objectUri, $calendarData): string
    {
        $this->assertWriteAllowed((int) $calendarId);
        $this->assertValidIcs($calendarData);
        return $this->objects->putObject((int) $calendarId, $objectUri, $calendarData);
    }

    public function updateCalendarObject($calendarId, $objectUri, $calendarData): string
    {
        $this->assertWriteAllowed((int) $calendarId);
        $this->assertValidIcs($calendarData);
        return $this->objects->putObject((int) $calendarId, $objectUri, $calendarData);
    }

    public function deleteCalendarObject($calendarId, $objectUri): void
    {
        $this->assertWriteAllowed((int) $calendarId);
        $this->objects->deleteObject((int) $calendarId, $objectUri);
    }

    private function assertWriteAllowed(int $calendarId): void
    {
        $username = $_SERVER['PHP_AUTH_USER'] ?? null;
        if ($username === null) {
            throw new \Sabre\DAV\Exception\Forbidden('Authentication required');
        }
        $user = $this->users->findByUsername($username);
        if ($user === null || !$user->isActive) {
            throw new \Sabre\DAV\Exception\Forbidden('User not found or inactive');
        }
        $perm = $this->sharing->getCalendarPermission($user->id, $calendarId);

        // Admins always have write access, but an admin writing a calendar they
        // neither own nor were granted is an override worth recording (not silent).
        if ($user->role === 'admin') {
            if ($perm === 'read_only' || $perm === 'none') {
                error_log(sprintf(
                    '[davyn] admin "%s" wrote calendar #%d without an ownership/share grant (admin override)',
                    $username,
                    $calendarId,
                ));
            }
            return;
        }
        if ($perm === 'read_only' || $perm === 'none') {
            throw new \Sabre\DAV\Exception\Forbidden('Read-only access: write operations are not permitted');
        }
    }

    public function getChangesForCalendar($calendarId, $syncToken, $syncLevel, $limit = null): array
    {
        $currentToken = $this->objects->getCurrentSyncToken((int) $calendarId);

        if ((int) $syncToken === $currentToken) {
            return [
                'syncToken' => (string) $currentToken,
                'added'     => [],
                'modified'  => [],
                'deleted'   => [],
            ];
        }

        $rows = $this->objects->getChanges((int) $calendarId, (int) $syncToken);

        $byUri = [];
        foreach ($rows as $row) {
            $byUri[$row['object_uri']] = $row['change_type'];
        }

        $added = $modified = $deleted = [];
        foreach ($byUri as $uri => $type) {
            match ($type) {
                'created' => $added[]    = $uri,
                'updated' => $modified[] = $uri,
                'deleted' => $deleted[]  = $uri,
            };
        }

        return [
            'syncToken' => (string) $currentToken,
            'added'     => $added,
            'modified'  => $modified,
            'deleted'   => $deleted,
        ];
    }

    private function rowToObject(array $row): array
    {
        return [
            'id'           => (int) $row['id'],
            'uri'          => $row['uri'],
            'etag'         => $row['etag'],
            'size'         => (int) $row['size'],
            'lastmodified' => strtotime($row['updated_at']),
            'calendardata' => $row['ics'],
            'component'    => $row['component_type'] ?? '',
        ];
    }

    private function assertValidIcs(string $data): void
    {
        if (!str_contains($data, 'BEGIN:VCALENDAR') || !str_contains($data, 'END:VCALENDAR')) {
            throw new BadRequest('Content-Type must be valid iCalendar data (missing BEGIN:VCALENDAR / END:VCALENDAR)');
        }
    }
}
