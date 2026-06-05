<?php
declare(strict_types=1);

namespace Davyn\User;

use InvalidArgumentException;
use PDO;
use RuntimeException;

class UserRepository
{
    private const VALID_ROLES = ['admin', 'user', 'read_only'];

    public function __construct(private readonly PDO $pdo) {}

    public function createUser(
        string $username,
        string $displayName,
        string $plainPassword,
        string $role,
    ): User {
        $username    = trim($username);
        $displayName = trim($displayName);

        if ($username === '') {
            throw new InvalidArgumentException('Username must not be empty.');
        }
        if ($displayName === '') {
            throw new InvalidArgumentException('Display name must not be empty.');
        }
        if ($plainPassword === '') {
            throw new InvalidArgumentException('Password must not be empty.');
        }
        if (!in_array($role, self::VALID_ROLES, true)) {
            throw new InvalidArgumentException(
                "Invalid role '{$role}'. Allowed: " . implode(', ', self::VALID_ROLES) . '.'
            );
        }

        $now  = $this->now();
        $hash = password_hash($plainPassword, PASSWORD_DEFAULT);

        $stmt = $this->pdo->prepare('
            INSERT INTO users (username, display_name, password_hash, role, is_active, created_at, updated_at)
            VALUES (:username, :display_name, :password_hash, :role, 1, :created_at, :updated_at)
        ');

        try {
            $stmt->execute([
                'username'      => $username,
                'display_name'  => $displayName,
                'password_hash' => $hash,
                'role'          => $role,
                'created_at'    => $now,
                'updated_at'    => $now,
            ]);
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'UNIQUE constraint failed')) {
                throw new InvalidArgumentException("Username '{$username}' already exists.");
            }
            throw new RuntimeException('Could not create user: ' . $e->getMessage(), 0, $e);
        }

        $id = (int) $this->pdo->lastInsertId();

        return $this->findById($id) ?? throw new RuntimeException('User not found after insert.');
    }

    public function findByUsername(string $username): ?User
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE username = ?');
        $stmt->execute([trim($username)]);
        $row = $stmt->fetch();
        return $row ? User::fromRow($row) : null;
    }

    public function listUsers(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM users ORDER BY id');
        return array_map(User::fromRow(...), $stmt->fetchAll());
    }

    public function verifyPassword(string $username, string $plainPassword): bool
    {
        $user = $this->findByUsername($username);
        if ($user === null || !$user->isActive) {
            return false;
        }
        return password_verify($plainPassword, $user->passwordHash);
    }

    public function findById(int $id): ?User
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? User::fromRow($row) : null;
    }

    public function updateLastLogin(int $id): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE users SET last_login_at = ?, updated_at = ? WHERE id = ?'
        );
        $now = $this->now();
        $stmt->execute([$now, $now, $id]);
    }

    /**
     * Whether a display name is already taken (case-insensitive), optionally
     * ignoring one user (the one being renamed). Username stays the unique key;
     * we keep display names unique too so the user/sharing lists stay unambiguous.
     */
    public function displayNameExists(string $displayName, ?string $exceptUsername = null): bool
    {
        $sql    = 'SELECT 1 FROM users WHERE lower(display_name) = lower(?)';
        $params = [trim($displayName)];
        if ($exceptUsername !== null) {
            $sql .= ' AND username <> ?';
            $params[] = trim($exceptUsername);
        }
        $stmt = $this->pdo->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Update a user's display name. The username (and thus DAV URLs, principals,
     * shares, app passwords, audit log) is intentionally left untouched.
     *
     * @throws InvalidArgumentException on empty/too-long/duplicate name or unknown user
     */
    public function updateDisplayName(string $username, string $displayName): void
    {
        $displayName = trim($displayName);
        if ($displayName === '') {
            throw new InvalidArgumentException('Display name must not be empty.');
        }
        if (mb_strlen($displayName) > 64) {
            throw new InvalidArgumentException('Display name must be at most 64 characters.');
        }
        if ($this->displayNameExists($displayName, $username)) {
            throw new InvalidArgumentException('Display name is already in use.');
        }
        $stmt = $this->pdo->prepare(
            'UPDATE users SET display_name = ?, updated_at = ? WHERE username = ?'
        );
        $stmt->execute([$displayName, $this->now(), trim($username)]);
        if ($stmt->rowCount() === 0) {
            throw new InvalidArgumentException("User '{$username}' not found.");
        }
    }

    /**
     * Update a user's own preferences. Pass null to leave a field unchanged.
     * locale must be a supported UI locale; theme one of light|dark|system.
     */
    public function updatePreferences(string $username, ?string $locale, ?string $theme): void
    {
        $sets   = [];
        $params = [];
        if ($locale !== null) {
            if (!in_array($locale, ['en', 'de'], true)) {
                throw new InvalidArgumentException('Unsupported locale.');
            }
            $sets[]   = 'locale = ?';
            $params[] = $locale;
        }
        if ($theme !== null) {
            if (!in_array($theme, ['light', 'dark', 'system'], true)) {
                throw new InvalidArgumentException('Unsupported theme.');
            }
            $sets[]   = 'theme = ?';
            $params[] = $theme;
        }
        if ($sets === []) {
            return;
        }
        $params[] = $this->now();
        $params[] = trim($username);
        $stmt = $this->pdo->prepare(
            'UPDATE users SET ' . implode(', ', $sets) . ', updated_at = ? WHERE username = ?'
        );
        $stmt->execute($params);
        if ($stmt->rowCount() === 0) {
            throw new InvalidArgumentException("User '{$username}' not found.");
        }
    }

    /**
     * Minimal directory of active users a given user can share with (everyone but
     * themselves). Returns rows: username, display_name.
     */
    public function listShareTargets(int $exceptUserId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT username, display_name FROM users
             WHERE is_active = 1 AND id <> ? ORDER BY display_name COLLATE NOCASE'
        );
        $stmt->execute([$exceptUserId]);
        return $stmt->fetchAll();
    }

    public function changePassword(string $username, string $plainPassword): void
    {
        if ($plainPassword === '') {
            throw new InvalidArgumentException('Password must not be empty.');
        }
        $hash = password_hash($plainPassword, PASSWORD_DEFAULT);
        $now  = $this->now();
        $stmt = $this->pdo->prepare(
            'UPDATE users SET password_hash = ?, updated_at = ? WHERE username = ?'
        );
        $stmt->execute([$hash, $now, trim($username)]);
        if ($stmt->rowCount() === 0) {
            throw new InvalidArgumentException("User '{$username}' not found.");
        }
    }

    public function setActive(string $username, bool $active): void
    {
        $now  = $this->now();
        $stmt = $this->pdo->prepare(
            'UPDATE users SET is_active = ?, updated_at = ? WHERE username = ?'
        );
        $stmt->execute([$active ? 1 : 0, $now, trim($username)]);
        if ($stmt->rowCount() === 0) {
            throw new InvalidArgumentException("User '{$username}' not found.");
        }
    }

    public function countActiveAdmins(): int
    {
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1");
        return (int) $stmt->fetchColumn();
    }

    private function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
