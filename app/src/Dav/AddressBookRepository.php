<?php
declare(strict_types=1);

namespace Davyn\Dav;

use InvalidArgumentException;
use PDO;

class AddressBookRepository
{
    private const URI_PATTERN = '/^[a-zA-Z0-9\-_.]+$/';

    public function __construct(private readonly PDO $pdo) {}

    public function createDefaultAddressBookForUser(int $userId): void
    {
        $stmt = $this->pdo->prepare('
            INSERT OR IGNORE INTO addressbooks
                (user_id, uri, display_name, sync_token, created_at, updated_at)
            VALUES
                (:user_id, :uri, :display_name, 1, :created_at, :updated_at)
        ');

        $now = $this->now();
        $stmt->execute([
            'user_id'      => $userId,
            'uri'          => 'default',
            'display_name' => 'Contacts',
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
    }

    public function createAddressBookForUser(
        string $username,
        string $uri,
        string $displayName,
        ?string $description = null,
    ): void {
        $this->validateUri($uri);
        if ($displayName === '') {
            throw new InvalidArgumentException('display_name must not be empty.');
        }

        $userId = $this->requireUserId($username);
        $now    = $this->now();

        try {
            $this->pdo->prepare('
                INSERT INTO addressbooks (user_id, uri, display_name, description, sync_token, created_at, updated_at)
                VALUES (?, ?, ?, ?, 1, ?, ?)
            ')->execute([$userId, $uri, $displayName, $description, $now, $now]);
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'UNIQUE constraint failed')) {
                throw new InvalidArgumentException("Addressbook URI '$uri' already exists for user '$username'.");
            }
            throw $e;
        }
    }

    public function updateAddressBook(string $username, string $uri, array $fields): void
    {
        if (array_key_exists('display_name', $fields) && trim((string) $fields['display_name']) === '') {
            throw new InvalidArgumentException('display_name must not be empty.');
        }

        $userId  = $this->requireUserId($username);
        $allowed = ['display_name', 'description'];
        $set     = [];
        $params  = [];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $fields)) {
                $set[]    = "$field = ?";
                $params[] = $fields[$field];
            }
        }

        if (empty($set)) {
            return;
        }

        $params[] = $this->now();
        $params[] = $userId;
        $params[] = $uri;

        $stmt = $this->pdo->prepare(
            'UPDATE addressbooks SET ' . implode(', ', $set) . ', updated_at = ? WHERE user_id = ? AND uri = ?'
        );
        $stmt->execute($params);

        if ($stmt->rowCount() === 0) {
            throw new InvalidArgumentException("Addressbook '$uri' not found for user '$username'.");
        }
    }

    public function listAddressBooksForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM addressbooks WHERE user_id = ? ORDER BY id'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM addressbooks WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * Permanently delete an address book and everything attached to it: contacts,
     * the change log, object versions, and shares. Child rows are removed explicitly
     * (SQLite foreign keys are not enforced on this connection), all in one transaction.
     */
    public function deleteAddressBookById(int $addressBookId): void
    {
        if ($this->findById($addressBookId) === null) {
            return;
        }

        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('DELETE FROM addressbook_objects WHERE addressbook_id = ?')->execute([$addressBookId]);
            $this->pdo->prepare('DELETE FROM addressbook_changes WHERE addressbook_id = ?')->execute([$addressBookId]);
            $this->pdo->prepare("DELETE FROM object_versions WHERE object_type = 'addressbook' AND collection_id = ?")->execute([$addressBookId]);
            $this->pdo->prepare("DELETE FROM collection_shares WHERE collection_type = 'addressbook' AND collection_id = ?")->execute([$addressBookId]);
            $this->pdo->prepare('DELETE FROM addressbooks WHERE id = ?')->execute([$addressBookId]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function validateUri(string $uri): void
    {
        if ($uri === '') {
            throw new InvalidArgumentException('URI must not be empty.');
        }
        if (!preg_match(self::URI_PATTERN, $uri)) {
            throw new InvalidArgumentException("URI '$uri' contains invalid characters. Use only a-z, A-Z, 0-9, -, _, .");
        }
    }

    private function requireUserId(string $username): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM users WHERE username = ?');
        $stmt->execute([trim($username)]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            throw new InvalidArgumentException("User '$username' not found.");
        }
        return (int) $id;
    }

    private function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
