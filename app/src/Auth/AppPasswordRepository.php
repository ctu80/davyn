<?php
declare(strict_types=1);

namespace Davyn\Auth;

use Davyn\Http\ClientIp;
use InvalidArgumentException;
use PDO;

class AppPasswordRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function createForUser(string $username, string $name, string $plainPassword): void
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('App password name must not be empty.');
        }
        if ($plainPassword === '') {
            throw new InvalidArgumentException('App password must not be empty.');
        }
        // Enforce a minimum length so manually-set (admin/CLI) app passwords are
        // not trivially brute-forceable over the DAV endpoint.
        if (mb_strlen($plainPassword) < 12) {
            throw new InvalidArgumentException('App password must be at least 12 characters.');
        }

        $userId = $this->requireUserId($username);
        $hash   = password_hash($plainPassword, PASSWORD_DEFAULT);
        $now    = $this->now();

        try {
            $this->pdo->prepare('
                INSERT INTO app_passwords (user_id, name, password_hash, created_at)
                VALUES (?, ?, ?, ?)
            ')->execute([$userId, $name, $hash, $now]);
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'UNIQUE constraint failed')) {
                throw new InvalidArgumentException(
                    "App password '$name' already exists for user '$username'."
                );
            }
            throw $e;
        }
    }

    public function listForUser(string $username): array
    {
        $userId = $this->requireUserId($username);
        $stmt   = $this->pdo->prepare('
            SELECT name, last_used_at, last_ip, last_user_agent, created_at, revoked_at
            FROM app_passwords
            WHERE user_id = ?
            ORDER BY id
        ');
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public function revokeForUser(string $username, string $name): void
    {
        $userId = $this->requireUserId($username);
        $stmt   = $this->pdo->prepare('
            UPDATE app_passwords SET revoked_at = ? WHERE user_id = ? AND name = ? AND revoked_at IS NULL
        ');
        $stmt->execute([$this->now(), $userId, $name]);
        if ($stmt->rowCount() === 0) {
            throw new InvalidArgumentException(
                "App password '$name' not found or already revoked for user '$username'."
            );
        }
    }

    public function deleteForUser(string $username, string $name): void
    {
        $userId = $this->requireUserId($username);
        $stmt   = $this->pdo->prepare(
            'DELETE FROM app_passwords WHERE user_id = ? AND name = ?'
        );
        $stmt->execute([$userId, $name]);
        if ($stmt->rowCount() === 0) {
            throw new InvalidArgumentException(
                "App password '$name' not found for user '$username'."
            );
        }
    }

    public function verify(string $username, string $plainPassword): bool
    {
        $stmt = $this->pdo->prepare('
            SELECT ap.id, ap.password_hash
            FROM app_passwords ap
            JOIN users u ON u.id = ap.user_id
            WHERE u.username = ? AND u.is_active = 1 AND ap.revoked_at IS NULL
        ');
        $stmt->execute([$username]);
        $rows = $stmt->fetchAll();

        foreach ($rows as $row) {
            if (password_verify($plainPassword, $row['password_hash'])) {
                // Record where/when this DAV device last synced.
                $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
                $this->pdo->prepare(
                    'UPDATE app_passwords SET last_used_at = ?, last_ip = ?, last_user_agent = ? WHERE id = ?'
                )->execute([$this->now(), ClientIp::get(), $ua, $row['id']]);
                return true;
            }
        }

        return false;
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
