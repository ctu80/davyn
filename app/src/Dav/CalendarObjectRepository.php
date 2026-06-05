<?php
declare(strict_types=1);

namespace Davyn\Dav;

use Davyn\Version\ObjectVersionRepository;
use PDO;

class CalendarObjectRepository
{
    private ?ObjectVersionRepository $versions = null;
    private int $maxIcsSize      = 0;
    private int $maxEventsPerUser = 0;

    public function __construct(private readonly PDO $pdo) {}

    public function setQuotas(int $maxIcsSize, int $maxEventsPerUser): void
    {
        $this->maxIcsSize       = $maxIcsSize;
        $this->maxEventsPerUser = $maxEventsPerUser;
    }

    public function setVersionRepository(ObjectVersionRepository $versions): void
    {
        $this->versions = $versions;
    }

    public function getObject(int $calendarId, string $uri): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM calendar_objects WHERE calendar_id = ? AND uri = ? AND deleted_at IS NULL'
        );
        $stmt->execute([$calendarId, $uri]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function listObjects(int $calendarId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM calendar_objects WHERE calendar_id = ? AND deleted_at IS NULL ORDER BY id'
        );
        $stmt->execute([$calendarId]);
        return $stmt->fetchAll();
    }

    public function isGenerated(int $calendarId): bool
    {
        $stmt = $this->pdo->prepare('SELECT generated_type FROM calendars WHERE id = ?');
        $stmt->execute([$calendarId]);
        $val = $stmt->fetchColumn();
        return $val !== false && $val !== null && $val !== '';
    }

    public function putObject(int $calendarId, string $uri, string $ics): string
    {
        if ($this->isGenerated($calendarId)) {
            throw new \Sabre\DAV\Exception\Forbidden('This calendar is read-only (generated).');
        }

        if ($this->maxIcsSize > 0 && strlen($ics) > $this->maxIcsSize) {
            throw new \Sabre\DAV\Exception\Forbidden(
                'ICS object exceeds size limit (' . $this->maxIcsSize . ' bytes).'
            );
        }

        $isCreate = $this->getObject($calendarId, $uri) === null;
        $now      = $this->now();
        $etag     = '"' . sha1($ics) . '"';
        $size     = strlen($ics);
        $uid      = $this->extractUid($ics);
        $type     = $this->extractComponentType($ics);

        $this->pdo->beginTransaction();
        try {
            // Enforce the per-user event quota inside the transaction, with bound
            // parameters, so the count and the insert are part of one unit of work.
            if ($isCreate && $this->maxEventsPerUser > 0) {
                $userStmt = $this->pdo->prepare('SELECT user_id FROM calendars WHERE id = ?');
                $userStmt->execute([$calendarId]);
                $ownerId = (int) $userStmt->fetchColumn();
                if ($ownerId > 0) {
                    $cntStmt = $this->pdo->prepare(
                        'SELECT COUNT(*) FROM calendar_objects co
                         JOIN calendars c ON c.id = co.calendar_id
                         WHERE c.user_id = ? AND co.deleted_at IS NULL'
                    );
                    $cntStmt->execute([$ownerId]);
                    if ((int) $cntStmt->fetchColumn() >= $this->maxEventsPerUser) {
                        throw new \Sabre\DAV\Exception\Forbidden(
                            'Event limit reached (' . $this->maxEventsPerUser . ' per user).'
                        );
                    }
                }
            }

            $newToken = $this->nextSyncToken($calendarId);

            if ($isCreate) {
                // Upsert on (calendar_id, uri): a soft-deleted row still occupies that
                // unique slot, so re-creating an object with the same URI (deleted then
                // recreated, or moved back into this calendar) must resurrect that row
                // rather than INSERT a duplicate (which would violate the constraint).
                $this->pdo->prepare('
                    INSERT INTO calendar_objects
                        (calendar_id, uri, uid, ics, etag, size, component_type, created_at, updated_at)
                    VALUES
                        (:calendar_id, :uri, :uid, :ics, :etag, :size, :type, :now, :now)
                    ON CONFLICT(calendar_id, uri) DO UPDATE SET
                        uid = excluded.uid, ics = excluded.ics, etag = excluded.etag,
                        size = excluded.size, component_type = excluded.component_type,
                        updated_at = excluded.updated_at, deleted_at = NULL
                ')->execute([
                    'calendar_id' => $calendarId,
                    'uri'         => $uri,
                    'uid'         => $uid,
                    'ics'         => $ics,
                    'etag'        => $etag,
                    'size'        => $size,
                    'type'        => $type,
                    'now'         => $now,
                ]);
            } else {
                // Save version before update
                $existing = $this->getObject($calendarId, $uri);
                if ($existing !== null && $this->versions !== null) {
                    $this->versions->saveCalendarVersion(
                        (int) $existing['id'], $calendarId, $uri,
                        $existing['ics'], $existing['etag'], 'updated'
                    );
                }

                $this->pdo->prepare('
                    UPDATE calendar_objects
                    SET uid = :uid, ics = :ics, etag = :etag, size = :size,
                        component_type = :type, updated_at = :now
                    WHERE calendar_id = :calendar_id AND uri = :uri AND deleted_at IS NULL
                ')->execute([
                    'uid'         => $uid,
                    'ics'         => $ics,
                    'etag'        => $etag,
                    'size'        => $size,
                    'type'        => $type,
                    'now'         => $now,
                    'calendar_id' => $calendarId,
                    'uri'         => $uri,
                ]);
            }

            $this->bumpSyncToken($calendarId, $newToken, $now);
            $this->writeChange($calendarId, $uri, $isCreate ? 'created' : 'updated', $newToken, $now);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $etag;
    }

    /**
     * Write an object into a generated (read-only) calendar.
     *
     * This is the sanctioned internal write path for generated calendars
     * (holidays, birthdays). It deliberately bypasses the isGenerated() guard
     * that blocks external CalDAV/web writes, while keeping the ETag, sync-token
     * and change-log bookkeeping identical to putObject() so CalDAV sync stays
     * correct. Returns the new ETag; idempotent (returns the existing ETag
     * unchanged when the content is identical).
     */
    public function putGeneratedObject(int $calendarId, string $uri, string $ics): string
    {
        $etag = '"' . sha1($ics) . '"';
        $existing = $this->getObject($calendarId, $uri);
        if ($existing !== null && $existing['etag'] === $etag) {
            return $etag; // unchanged — no sync churn
        }

        $isCreate = $existing === null;
        $now      = $this->now();
        $size     = strlen($ics);
        $uid      = $this->extractUid($ics);
        $type     = $this->extractComponentType($ics);

        $this->pdo->beginTransaction();
        try {
            $newToken = $this->nextSyncToken($calendarId);

            if ($isCreate) {
                // Upsert on (calendar_id, uri): a soft-deleted row still occupies that
                // unique slot, so re-creating an object with the same URI (deleted then
                // recreated, or moved back into this calendar) must resurrect that row
                // rather than INSERT a duplicate (which would violate the constraint).
                $this->pdo->prepare('
                    INSERT INTO calendar_objects
                        (calendar_id, uri, uid, ics, etag, size, component_type, created_at, updated_at)
                    VALUES
                        (:calendar_id, :uri, :uid, :ics, :etag, :size, :type, :now, :now)
                    ON CONFLICT(calendar_id, uri) DO UPDATE SET
                        uid = excluded.uid, ics = excluded.ics, etag = excluded.etag,
                        size = excluded.size, component_type = excluded.component_type,
                        updated_at = excluded.updated_at, deleted_at = NULL
                ')->execute([
                    'calendar_id' => $calendarId,
                    'uri'         => $uri,
                    'uid'         => $uid,
                    'ics'         => $ics,
                    'etag'        => $etag,
                    'size'        => $size,
                    'type'        => $type,
                    'now'         => $now,
                ]);
            } else {
                $this->pdo->prepare('
                    UPDATE calendar_objects
                    SET uid = :uid, ics = :ics, etag = :etag, size = :size,
                        component_type = :type, updated_at = :now
                    WHERE calendar_id = :calendar_id AND uri = :uri AND deleted_at IS NULL
                ')->execute([
                    'uid'         => $uid,
                    'ics'         => $ics,
                    'etag'        => $etag,
                    'size'        => $size,
                    'type'        => $type,
                    'now'         => $now,
                    'calendar_id' => $calendarId,
                    'uri'         => $uri,
                ]);
            }

            $this->bumpSyncToken($calendarId, $newToken, $now);
            $this->writeChange($calendarId, $uri, $isCreate ? 'created' : 'updated', $newToken, $now);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $etag;
    }

    /**
     * Soft-delete an object from a generated calendar, with correct sync-token
     * and change-log bookkeeping. No-op if the object does not exist.
     */
    public function deleteGeneratedObject(int $calendarId, string $uri): void
    {
        if ($this->getObject($calendarId, $uri) === null) {
            return;
        }
        $now = $this->now();

        $this->pdo->beginTransaction();
        try {
            $newToken = $this->nextSyncToken($calendarId);
            $this->pdo->prepare(
                'UPDATE calendar_objects SET deleted_at = ?, updated_at = ? WHERE calendar_id = ? AND uri = ? AND deleted_at IS NULL'
            )->execute([$now, $now, $calendarId, $uri]);
            $this->bumpSyncToken($calendarId, $newToken, $now);
            $this->writeChange($calendarId, $uri, 'deleted', $newToken, $now);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function deleteObject(int $calendarId, string $uri): void
    {
        if ($this->isGenerated($calendarId)) {
            throw new \Sabre\DAV\Exception\Forbidden('This calendar is read-only (generated).');
        }
        $now = $this->now();

        $this->pdo->beginTransaction();
        try {
            $newToken = $this->nextSyncToken($calendarId);

            // Save version before soft delete
            $existing = $this->getObject($calendarId, $uri);
            if ($existing !== null && $this->versions !== null) {
                $this->versions->saveCalendarVersion(
                    (int) $existing['id'], $calendarId, $uri,
                    $existing['ics'], $existing['etag'], 'deleted'
                );
            }

            // Soft delete: mark deleted_at rather than removing the row
            $this->pdo->prepare(
                'UPDATE calendar_objects SET deleted_at = ?, updated_at = ? WHERE calendar_id = ? AND uri = ? AND deleted_at IS NULL'
            )->execute([$now, $now, $calendarId, $uri]);

            $this->bumpSyncToken($calendarId, $newToken, $now);
            $this->writeChange($calendarId, $uri, 'deleted', $newToken, $now);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function getChanges(int $calendarId, int $sinceToken): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT object_uri, change_type, sync_token FROM calendar_changes
             WHERE calendar_id = ? AND sync_token > ? ORDER BY id'
        );
        $stmt->execute([$calendarId, $sinceToken]);
        return $stmt->fetchAll();
    }

    public function getCurrentSyncToken(int $calendarId): int
    {
        $stmt = $this->pdo->prepare('SELECT sync_token FROM calendars WHERE id = ?');
        $stmt->execute([$calendarId]);
        return (int) $stmt->fetchColumn();
    }

    private function nextSyncToken(int $calendarId): int
    {
        $stmt = $this->pdo->prepare('SELECT sync_token FROM calendars WHERE id = ?');
        $stmt->execute([$calendarId]);
        return (int) $stmt->fetchColumn() + 1;
    }

    private function bumpSyncToken(int $calendarId, int $newToken, string $now): void
    {
        $this->pdo->prepare('UPDATE calendars SET sync_token = ?, updated_at = ? WHERE id = ?')
            ->execute([$newToken, $now, $calendarId]);
    }

    private function writeChange(int $calendarId, string $uri, string $type, int $token, string $now): void
    {
        $this->pdo->prepare('
            INSERT INTO calendar_changes (calendar_id, object_uri, change_type, sync_token, created_at)
            VALUES (?, ?, ?, ?, ?)
        ')->execute([$calendarId, $uri, $type, $token, $now]);
    }

    private function extractUid(string $ics): ?string
    {
        if (preg_match('/^UID:(.+)$/m', $ics, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    private function extractComponentType(string $ics): ?string
    {
        if (str_contains($ics, 'BEGIN:VEVENT'))   return 'VEVENT';
        if (str_contains($ics, 'BEGIN:VTODO'))    return 'VTODO';
        if (str_contains($ics, 'BEGIN:VJOURNAL')) return 'VJOURNAL';
        return null;
    }

    private function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
