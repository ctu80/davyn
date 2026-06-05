<?php
declare(strict_types=1);

namespace Davyn\Dav;

use InvalidArgumentException;
use PDO;

class CalendarRepository
{
    private const URI_PATTERN = '/^[a-zA-Z0-9\-_.]+$/';

    public function __construct(private readonly PDO $pdo) {}

    public function createDefaultCalendarForUser(int $userId): void
    {
        $stmt = $this->pdo->prepare('
            INSERT OR IGNORE INTO calendars
                (user_id, uri, display_name, sync_token, created_at, updated_at)
            VALUES
                (:user_id, :uri, :display_name, 1, :created_at, :updated_at)
        ');

        $now = $this->now();
        $stmt->execute([
            'user_id'      => $userId,
            'uri'          => 'default',
            'display_name' => 'Personal',
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
    }

    public function createCalendarForUser(
        string $username,
        string $uri,
        string $displayName,
        ?string $description = null,
        ?string $color       = null,
        ?string $timezone    = null,
    ): void {
        $this->validateUri($uri);
        if ($displayName === '') {
            throw new InvalidArgumentException('display_name must not be empty.');
        }

        $userId = $this->requireUserId($username);
        $now    = $this->now();

        try {
            $this->pdo->prepare('
                INSERT INTO calendars (user_id, uri, display_name, description, color, timezone, sync_token, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)
            ')->execute([$userId, $uri, $displayName, $description, $color, $timezone, $now, $now]);
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'UNIQUE constraint failed')) {
                throw new InvalidArgumentException("Calendar URI '$uri' already exists for user '$username'.");
            }
            throw $e;
        }
    }

    public function updateCalendar(string $username, string $uri, array $fields): void
    {
        if (array_key_exists('display_name', $fields) && trim((string) $fields['display_name']) === '') {
            throw new InvalidArgumentException('display_name must not be empty.');
        }

        $userId  = $this->requireUserId($username);
        $allowed = ['display_name', 'description', 'color', 'timezone'];
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
            'UPDATE calendars SET ' . implode(', ', $set) . ', updated_at = ? WHERE user_id = ? AND uri = ?'
        );
        $stmt->execute($params);

        if ($stmt->rowCount() === 0) {
            throw new InvalidArgumentException("Calendar '$uri' not found for user '$username'.");
        }
    }

    public function listCalendarsForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM calendars WHERE user_id = ? ORDER BY id'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM calendars WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * Permanently delete a calendar and everything attached to it: events, the
     * change log, object versions, shares, public links, and any holiday-subscription
     * or external-calendar configuration. Child rows are removed explicitly (SQLite
     * foreign keys are not enforced on this connection), all inside one transaction.
     */
    public function deleteCalendarById(int $calendarId): void
    {
        $cal = $this->findById($calendarId);
        if ($cal === null) {
            return;
        }
        $userId = (int) $cal['user_id'];
        $uri    = (string) $cal['uri'];

        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('DELETE FROM calendar_objects WHERE calendar_id = ?')->execute([$calendarId]);
            $this->pdo->prepare('DELETE FROM calendar_changes WHERE calendar_id = ?')->execute([$calendarId]);
            $this->pdo->prepare('DELETE FROM public_calendar_links WHERE calendar_id = ?')->execute([$calendarId]);
            $this->pdo->prepare("DELETE FROM object_versions WHERE object_type = 'calendar' AND collection_id = ?")->execute([$calendarId]);
            $this->pdo->prepare("DELETE FROM collection_shares WHERE collection_type = 'calendar' AND collection_id = ?")->execute([$calendarId]);
            $this->pdo->prepare('DELETE FROM holiday_calendar_subscriptions WHERE calendar_id = ?')->execute([$calendarId]);
            // external_calendars is keyed by (user_id, uri), not calendar_id.
            $this->pdo->prepare('DELETE FROM external_calendars WHERE user_id = ? AND uri = ?')->execute([$userId, $uri]);
            $this->pdo->prepare('DELETE FROM calendars WHERE id = ?')->execute([$calendarId]);
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
