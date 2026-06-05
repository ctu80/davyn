<?php
declare(strict_types=1);

namespace Davyn\Backup;

use PDO;
use RuntimeException;

class BackupService
{
    private const REQUIRED_TABLES = [
        'schema_migrations',
        'users',
        'calendars',
        'calendar_objects',
        'addressbooks',
        'addressbook_objects',
        'calendar_changes',
        'addressbook_changes',
        'app_passwords',
    ];

    public function create(PDO $pdo, string $dbPath): string
    {
        $backupDir = dirname($dbPath) . '/backups';
        if (!is_dir($backupDir)) {
            if (!mkdir($backupDir, 0750, true)) {
                throw new RuntimeException("Cannot create backup directory: $backupDir");
            }
        }

        $timestamp  = gmdate('Ymd-His');
        $backupFile = "$backupDir/davyn-backup-$timestamp.sqlite";

        $pdo->exec("VACUUM INTO " . $pdo->quote($backupFile));

        if (!file_exists($backupFile)) {
            throw new RuntimeException("Backup file was not created: $backupFile");
        }

        return $backupFile;
    }

    public function validate(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new RuntimeException("File not found: $filePath");
        }

        $pdo = new PDO('sqlite:' . $filePath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        // integrity check
        $integrityResult = $pdo->query('PRAGMA integrity_check')->fetchColumn();
        if ($integrityResult !== 'ok') {
            throw new RuntimeException("Integrity check failed: $integrityResult");
        }

        // check required tables exist
        $existingTables = array_column(
            $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(),
            'name'
        );
        $missing = array_diff(self::REQUIRED_TABLES, $existingTables);
        if (!empty($missing)) {
            throw new RuntimeException("Missing tables: " . implode(', ', $missing));
        }

        // collect counts + migrations
        $counts = [];
        foreach (self::REQUIRED_TABLES as $table) {
            if ($table === 'schema_migrations') {
                continue;
            }
            $counts[$table] = (int) $pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn();
        }

        $migrations = array_column(
            $pdo->query("SELECT version FROM schema_migrations ORDER BY version")->fetchAll(),
            'version'
        );

        // optional tables (may not exist in older backups, not already in REQUIRED_TABLES)
        $optionalCounts = [];
        foreach (['collection_shares', 'web_sessions', 'object_versions', 'activity_log'] as $t) {
            if (in_array($t, $existingTables, true)) {
                $optionalCounts[$t] = (int) $pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();
            }
        }

        // version string from VERSION table or meta, if present
        $version = null;
        if (in_array('app_meta', $existingTables, true)) {
            $version = $pdo->query("SELECT value FROM app_meta WHERE key='version'")->fetchColumn() ?: null;
        }

        $size = filesize($filePath);

        return [
            'file'           => $filePath,
            'size'           => $size,
            'integrity'      => 'ok',
            'migrations'     => $migrations,
            'migration_count'=> count($migrations),
            'latest_migration'=> !empty($migrations) ? end($migrations) : null,
            'counts'         => $counts,
            'optional_counts'=> $optionalCounts,
            'version'        => $version,
        ];
    }

    public function list(string $dbPath): array
    {
        $backupDir = dirname($dbPath) . '/backups';
        if (!is_dir($backupDir)) {
            return [];
        }
        $files = glob("$backupDir/davyn-backup-*.sqlite") ?: [];
        usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
        return $files;
    }

    public function prune(string $dbPath, int $retentionDays, int $minKeep): array
    {
        $files   = $this->list($dbPath);
        $cutoff  = time() - ($retentionDays * 86400);
        $deleted = [];

        foreach ($files as $i => $file) {
            if ($i < $minKeep) {
                continue;
            }
            if (filemtime($file) < $cutoff) {
                if (@unlink($file)) {
                    $deleted[] = basename($file);
                }
            }
        }

        return [
            'deleted' => $deleted,
            'kept'    => count($files) - count($deleted),
        ];
    }

    public function formatSize(int $bytes): string
    {
        return $bytes >= 1024 * 1024
            ? round($bytes / (1024 * 1024), 2) . ' MB'
            : round($bytes / 1024, 1) . ' KB';
    }
}
