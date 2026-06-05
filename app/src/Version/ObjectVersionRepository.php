<?php
declare(strict_types=1);

namespace Davyn\Version;

class ObjectVersionRepository
{
    public function __construct(private readonly \PDO $pdo) {}

    public function saveCalendarVersion(
        int    $objectId,
        int    $calendarId,
        string $uri,
        string $content,
        string $etag,
        string $reason,
    ): void {
        $this->pdo->prepare(
            'INSERT INTO object_versions
             (object_type, object_id, collection_id, object_uri, content, etag, version_created_at, reason)
             VALUES (?,?,?,?,?,?,?,?)'
        )->execute(['calendar', $objectId, $calendarId, $uri, $content, $etag,
                    gmdate('Y-m-d\TH:i:s\Z'), $reason]);
    }

    public function saveAddressBookVersion(
        int    $objectId,
        int    $addressBookId,
        string $uri,
        string $content,
        string $etag,
        string $reason,
    ): void {
        $this->pdo->prepare(
            'INSERT INTO object_versions
             (object_type, object_id, collection_id, object_uri, content, etag, version_created_at, reason)
             VALUES (?,?,?,?,?,?,?,?)'
        )->execute(['addressbook', $objectId, $addressBookId, $uri, $content, $etag,
                    gmdate('Y-m-d\TH:i:s\Z'), $reason]);
    }

    public function listForCalendarObject(int $objectId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, object_uri, etag, version_created_at, reason
             FROM object_versions WHERE object_type = ? AND object_id = ?
             ORDER BY id DESC'
        );
        $stmt->execute(['calendar', $objectId]);
        return $stmt->fetchAll();
    }

    public function listForAddressBookObject(int $objectId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, object_uri, etag, version_created_at, reason
             FROM object_versions WHERE object_type = ? AND object_id = ?
             ORDER BY id DESC'
        );
        $stmt->execute(['addressbook', $objectId]);
        return $stmt->fetchAll();
    }

    public function listByUri(string $type, int $collectionId, string $uri): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, object_id, etag, version_created_at, reason
             FROM object_versions WHERE object_type = ? AND collection_id = ? AND object_uri = ?
             ORDER BY id DESC'
        );
        $stmt->execute([$type, $collectionId, $uri]);
        return $stmt->fetchAll();
    }

    public function countAll(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM object_versions')->fetchColumn();
    }
}
