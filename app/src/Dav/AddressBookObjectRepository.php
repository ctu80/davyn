<?php
declare(strict_types=1);

namespace Davyn\Dav;

use Davyn\Version\ObjectVersionRepository;
use PDO;

class AddressBookObjectRepository
{
    private ?ObjectVersionRepository $versions = null;
    private int $maxVcardSize         = 0;
    private int $maxContactsPerUser   = 0;

    public function __construct(private readonly PDO $pdo) {}

    public function setQuotas(int $maxVcardSize, int $maxContactsPerUser): void
    {
        $this->maxVcardSize       = $maxVcardSize;
        $this->maxContactsPerUser = $maxContactsPerUser;
    }

    public function setVersionRepository(ObjectVersionRepository $versions): void
    {
        $this->versions = $versions;
    }

    public function getObject(int $addressBookId, string $uri): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM addressbook_objects WHERE addressbook_id = ? AND uri = ? AND deleted_at IS NULL'
        );
        $stmt->execute([$addressBookId, $uri]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function listObjects(int $addressBookId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM addressbook_objects WHERE addressbook_id = ? AND deleted_at IS NULL ORDER BY id'
        );
        $stmt->execute([$addressBookId]);
        return $stmt->fetchAll();
    }

    public function putObject(int $addressBookId, string $uri, string $vcard): string
    {
        if ($this->maxVcardSize > 0 && strlen($vcard) > $this->maxVcardSize) {
            throw new \Sabre\DAV\Exception\Forbidden(
                'vCard exceeds size limit (' . $this->maxVcardSize . ' bytes).'
            );
        }

        $isCreate = $this->getObject($addressBookId, $uri) === null;
        $now      = $this->now();
        $etag     = '"' . sha1($vcard) . '"';
        $size     = strlen($vcard);
        $uid      = $this->extractUid($vcard);

        $this->pdo->beginTransaction();
        try {
            // Enforce the per-user contact quota inside the transaction, with bound
            // parameters, so the count and the insert are part of one unit of work.
            if ($isCreate && $this->maxContactsPerUser > 0) {
                $userStmt = $this->pdo->prepare('SELECT user_id FROM addressbooks WHERE id = ?');
                $userStmt->execute([$addressBookId]);
                $ownerId = (int) $userStmt->fetchColumn();
                if ($ownerId > 0) {
                    $cntStmt = $this->pdo->prepare(
                        'SELECT COUNT(*) FROM addressbook_objects ao
                         JOIN addressbooks a ON a.id = ao.addressbook_id
                         WHERE a.user_id = ? AND ao.deleted_at IS NULL'
                    );
                    $cntStmt->execute([$ownerId]);
                    if ((int) $cntStmt->fetchColumn() >= $this->maxContactsPerUser) {
                        throw new \Sabre\DAV\Exception\Forbidden(
                            'Contact limit reached (' . $this->maxContactsPerUser . ' per user).'
                        );
                    }
                }
            }

            $newToken = $this->nextSyncToken($addressBookId);

            if ($isCreate) {
                // Upsert on (addressbook_id, uri): a soft-deleted row still occupies that
                // unique slot, so re-creating a contact with the same URI (deleted then
                // recreated, or moved back) must resurrect that row rather than INSERT a
                // duplicate (which would violate the constraint).
                $this->pdo->prepare('
                    INSERT INTO addressbook_objects
                        (addressbook_id, uri, uid, vcard, etag, size, created_at, updated_at)
                    VALUES
                        (:addressbook_id, :uri, :uid, :vcard, :etag, :size, :now, :now)
                    ON CONFLICT(addressbook_id, uri) DO UPDATE SET
                        uid = excluded.uid, vcard = excluded.vcard, etag = excluded.etag,
                        size = excluded.size, updated_at = excluded.updated_at, deleted_at = NULL
                ')->execute([
                    'addressbook_id' => $addressBookId,
                    'uri'            => $uri,
                    'uid'            => $uid,
                    'vcard'          => $vcard,
                    'etag'           => $etag,
                    'size'           => $size,
                    'now'            => $now,
                ]);
            } else {
                // Save version before update
                $existing = $this->getObject($addressBookId, $uri);
                if ($existing !== null && $this->versions !== null) {
                    $this->versions->saveAddressBookVersion(
                        (int) $existing['id'], $addressBookId, $uri,
                        $existing['vcard'], $existing['etag'], 'updated'
                    );
                }

                $this->pdo->prepare('
                    UPDATE addressbook_objects
                    SET uid = :uid, vcard = :vcard, etag = :etag, size = :size, updated_at = :now
                    WHERE addressbook_id = :addressbook_id AND uri = :uri AND deleted_at IS NULL
                ')->execute([
                    'uid'            => $uid,
                    'vcard'          => $vcard,
                    'etag'           => $etag,
                    'size'           => $size,
                    'now'            => $now,
                    'addressbook_id' => $addressBookId,
                    'uri'            => $uri,
                ]);
            }

            $this->bumpSyncToken($addressBookId, $newToken, $now);
            $this->writeChange($addressBookId, $uri, $isCreate ? 'created' : 'updated', $newToken, $now);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $etag;
    }

    public function deleteObject(int $addressBookId, string $uri): void
    {
        $now = $this->now();

        $this->pdo->beginTransaction();
        try {
            $newToken = $this->nextSyncToken($addressBookId);

            // Save version before soft delete
            $existing = $this->getObject($addressBookId, $uri);
            if ($existing !== null && $this->versions !== null) {
                $this->versions->saveAddressBookVersion(
                    (int) $existing['id'], $addressBookId, $uri,
                    $existing['vcard'], $existing['etag'], 'deleted'
                );
            }

            // Soft delete: mark deleted_at rather than removing the row
            $this->pdo->prepare(
                'UPDATE addressbook_objects SET deleted_at = ?, updated_at = ? WHERE addressbook_id = ? AND uri = ? AND deleted_at IS NULL'
            )->execute([$now, $now, $addressBookId, $uri]);

            $this->bumpSyncToken($addressBookId, $newToken, $now);
            $this->writeChange($addressBookId, $uri, 'deleted', $newToken, $now);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function getChanges(int $addressBookId, int $sinceToken): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT object_uri, change_type, sync_token FROM addressbook_changes
             WHERE addressbook_id = ? AND sync_token > ? ORDER BY id'
        );
        $stmt->execute([$addressBookId, $sinceToken]);
        return $stmt->fetchAll();
    }

    public function getCurrentSyncToken(int $addressBookId): int
    {
        $stmt = $this->pdo->prepare('SELECT sync_token FROM addressbooks WHERE id = ?');
        $stmt->execute([$addressBookId]);
        return (int) $stmt->fetchColumn();
    }

    private function nextSyncToken(int $addressBookId): int
    {
        $stmt = $this->pdo->prepare('SELECT sync_token FROM addressbooks WHERE id = ?');
        $stmt->execute([$addressBookId]);
        return (int) $stmt->fetchColumn() + 1;
    }

    private function bumpSyncToken(int $addressBookId, int $newToken, string $now): void
    {
        $this->pdo->prepare('UPDATE addressbooks SET sync_token = ?, updated_at = ? WHERE id = ?')
            ->execute([$newToken, $now, $addressBookId]);
    }

    private function writeChange(int $addressBookId, string $uri, string $type, int $token, string $now): void
    {
        $this->pdo->prepare('
            INSERT INTO addressbook_changes (addressbook_id, object_uri, change_type, sync_token, created_at)
            VALUES (?, ?, ?, ?, ?)
        ')->execute([$addressBookId, $uri, $type, $token, $now]);
    }

    private function extractUid(string $vcard): ?string
    {
        if (preg_match('/^UID:(.+)$/m', $vcard, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    private function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
