<?php
declare(strict_types=1);

namespace Davyn\Dav\Sabre;

use Davyn\Birthday\BirthdayTrigger;
use Davyn\Dav\AddressBookObjectRepository;
use Davyn\Dav\AddressBookRepository;
use Davyn\Sharing\SharingService;
use Davyn\User\UserRepository;
use Sabre\CardDAV\Backend\AbstractBackend;
use Sabre\CardDAV\Backend\SyncSupport;
use Sabre\DAV\Exception\BadRequest;
use Sabre\DAV\PropPatch;

class CardDavBackend extends AbstractBackend implements SyncSupport
{
    public function __construct(
        private readonly AddressBookRepository       $addressbooks,
        private readonly AddressBookObjectRepository $objects,
        private readonly UserRepository              $users,
        private readonly SharingService              $sharing,
        private readonly \PDO                        $pdo,
    ) {}

    public function getAddressBooksForUser($principalUri): array
    {
        $username = basename($principalUri);
        $user = $this->users->findByUsername($username);
        if ($user === null || !$user->isActive) {
            return [];
        }

        $accessible = $this->sharing->listAccessibleAddressBooksForUser($user->id);
        $result     = [];

        foreach ($accessible as $row) {
            $isOwned = ($row['permission'] === 'owner');
            $uri     = $isOwned
                ? $row['uri']
                : 'shared-' . $row['owner_username'] . '-' . $row['uri'];
            $name    = $isOwned
                ? $row['display_name']
                : $row['display_name'] . ' (shared by ' . $row['owner_username'] . ')';

            $full = $this->addressbooks->findById((int) $row['id']);

            $result[] = [
                'id'           => (int) $row['id'],
                'uri'          => $uri,
                'principaluri' => $principalUri,
                '{DAV:}displayname'
                    => $name,
                '{urn:ietf:params:xml:ns:carddav}addressbook-description'
                    => $full['description'] ?? '',
                '{http://sabredav.org/ns}sync-token'
                    => (string) ($full['sync_token'] ?? 1),
            ];
        }

        return $result;
    }

    public function updateAddressBook($addressBookId, PropPatch $propPatch): void {}

    public function createAddressBook($principalUri, $url, array $properties): string
    {
        throw new \Sabre\DAV\Exception\NotImplemented('createAddressBook is not implemented');
    }

    public function deleteAddressBook($addressBookId): void
    {
        throw new \Sabre\DAV\Exception\NotImplemented('deleteAddressBook is not implemented');
    }

    public function getCards($addressBookId): array
    {
        return array_map(
            fn(array $row) => $this->rowToCard($row),
            $this->objects->listObjects((int) $addressBookId)
        );
    }

    public function getCard($addressBookId, $cardUri)
    {
        $row = $this->objects->getObject((int) $addressBookId, $cardUri);
        return $row !== null ? $this->rowToCard($row) : false;
    }

    public function getMultipleCards($addressBookId, array $uris): array
    {
        return array_values(array_filter(
            array_map(
                fn(string $uri) => $this->getCard($addressBookId, $uri) ?: null,
                $uris
            )
        ));
    }

    public function createCard($addressBookId, $cardUri, $cardData): string
    {
        $this->assertWriteAllowed((int) $addressBookId);
        $this->assertValidVCard($cardData);
        $etag = $this->objects->putObject((int) $addressBookId, $cardUri, $cardData);
        BirthdayTrigger::onContactSaved($this->pdo, (int) $addressBookId, (string) $cardUri, $cardData);
        return $etag;
    }

    public function updateCard($addressBookId, $cardUri, $cardData): string
    {
        $this->assertWriteAllowed((int) $addressBookId);
        $this->assertValidVCard($cardData);
        $etag = $this->objects->putObject((int) $addressBookId, $cardUri, $cardData);
        BirthdayTrigger::onContactSaved($this->pdo, (int) $addressBookId, (string) $cardUri, $cardData);
        return $etag;
    }

    public function deleteCard($addressBookId, $cardUri): bool
    {
        $this->assertWriteAllowed((int) $addressBookId);
        $this->objects->deleteObject((int) $addressBookId, $cardUri);
        BirthdayTrigger::onContactDeleted($this->pdo, (int) $addressBookId, (string) $cardUri);
        return true;
    }

    private function assertWriteAllowed(int $addressBookId): void
    {
        $username = $_SERVER['PHP_AUTH_USER'] ?? null;
        if ($username === null) {
            throw new \Sabre\DAV\Exception\Forbidden('Authentication required');
        }
        $user = $this->users->findByUsername($username);
        if ($user === null || !$user->isActive) {
            throw new \Sabre\DAV\Exception\Forbidden('User not found or inactive');
        }
        $perm = $this->sharing->getAddressBookPermission($user->id, $addressBookId);

        // Admins always have write access, but an admin writing an address book
        // they neither own nor were granted is an override worth recording.
        if ($user->role === 'admin') {
            if ($perm === 'read_only' || $perm === 'none') {
                error_log(sprintf(
                    '[davyn] admin "%s" wrote address book #%d without an ownership/share grant (admin override)',
                    $username,
                    $addressBookId,
                ));
            }
            return;
        }
        if ($perm === 'read_only' || $perm === 'none') {
            throw new \Sabre\DAV\Exception\Forbidden('Read-only access: write operations are not permitted');
        }
    }

    public function getChangesForAddressBook($addressBookId, $syncToken, $syncLevel, $limit = null): array
    {
        $currentToken = $this->objects->getCurrentSyncToken((int) $addressBookId);

        if ((int) $syncToken === $currentToken) {
            return [
                'syncToken' => (string) $currentToken,
                'added'     => [],
                'modified'  => [],
                'deleted'   => [],
            ];
        }

        $rows = $this->objects->getChanges((int) $addressBookId, (int) $syncToken);

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

    private function rowToCard(array $row): array
    {
        return [
            'id'           => (int) $row['id'],
            'uri'          => $row['uri'],
            'etag'         => $row['etag'],
            'size'         => (int) $row['size'],
            'lastmodified' => strtotime($row['updated_at']),
            'carddata'     => $row['vcard'],
        ];
    }

    private function assertValidVCard(string $data): void
    {
        if (!str_contains($data, 'BEGIN:VCARD') || !str_contains($data, 'END:VCARD')) {
            throw new BadRequest('Content must be valid vCard data (missing BEGIN:VCARD / END:VCARD)');
        }
    }
}
