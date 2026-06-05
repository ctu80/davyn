<?php
declare(strict_types=1);

namespace Davyn\Tests\Support;

use Davyn\Database\MigrationRunner;
use PDO;

final class Db
{
    /**
     * Build a single in-memory SQLite connection with the full schema applied.
     * The same PDO is returned to caller and repositories so the :memory: DB is
     * shared (a new connection would see an empty database).
     */
    public static function migratedMemory(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        // Mirror production: enforce foreign keys so ON DELETE CASCADE fires.
        $pdo->exec('PRAGMA foreign_keys = ON');

        (new MigrationRunner($pdo, __DIR__ . '/../../migrations'))->run();
        return $pdo;
    }

    public static function insertUser(
        PDO $pdo,
        string $username = 'alice',
        string $role = 'user',
        int $active = 1,
    ): int {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $pdo->prepare(
            'INSERT INTO users (username, display_name, password_hash, role, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$username, ucfirst($username), password_hash('pw', PASSWORD_DEFAULT), $role, $active, $now, $now]);
        return (int) $pdo->lastInsertId();
    }
}
