<?php
declare(strict_types=1);

namespace Davyn\Database;

use Davyn\Config\Config;
use PDO;
use RuntimeException;

class ConnectionFactory
{
    public static function create(Config $config): PDO
    {
        if ($config->dbDriver() !== 'sqlite') {
            throw new RuntimeException(
                "Unsupported DB driver: {$config->dbDriver()}. Only 'sqlite' is supported."
            );
        }

        $dir = dirname($config->dbPath());
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new RuntimeException("Could not create database directory: {$dir}");
        }

        $pdo = new PDO('sqlite:' . $config->dbPath());
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        // Connection-level pragmas applied to EVERY connection. SQLite resets most of
        // these per-connection, so they must live here rather than in a migration.
        //  - busy_timeout: wait (ms) for a lock instead of failing instantly with
        //    SQLITE_BUSY — essential when a DAVx5 sync and a WebUI write overlap.
        //  - journal_mode=WAL: readers don't block the writer and vice versa. WAL is a
        //    persistent property of the DB file but is cheap to re-assert per connection.
        //  - synchronous=NORMAL: the safe+fast pairing for WAL (durable across app
        //    crashes; only a power-loss mid-checkpoint risks the last commit).
        //  - foreign_keys=ON: SQLite leaves FK enforcement OFF per connection by default,
        //    so the schema's ON DELETE CASCADE / SET NULL only fire when this is set.
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('PRAGMA foreign_keys = ON');

        return $pdo;
    }
}
