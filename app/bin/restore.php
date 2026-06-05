<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Davyn\Backup\BackupService;
use Davyn\Config\Config;
use Davyn\Database\ConnectionFactory;
use Davyn\Maintenance\MaintenanceMode;

$opts    = getopt('', ['file:', 'dry-run', 'apply', 'confirm', 'yes']);
$file    = isset($opts['file'])    ? (string) $opts['file'] : null;
$dryRun  = isset($opts['dry-run']);
$apply   = isset($opts['apply']);
$confirm = isset($opts['confirm']) || isset($opts['yes']);

if ($file === null || (!$dryRun && !$apply)) {
    echo "Usage:" . PHP_EOL;
    echo "  php restore.php --file <backup.sqlite> --dry-run            # validate only" . PHP_EOL;
    echo "  php restore.php --file <backup.sqlite> --apply --confirm    # validate + restore" . PHP_EOL;
    exit(1);
}

if ($apply && !$confirm) {
    echo "ERROR: --apply requires --confirm (or --yes) to prevent accidental data loss." . PHP_EOL;
    echo "Run with --dry-run first to inspect the backup, then add --confirm to apply." . PHP_EOL;
    exit(1);
}

$service = new BackupService();

// ── Validate backup ───────────────────────────────────────────────────────
echo "Validating: $file" . PHP_EOL;
try {
    $info = $service->validate($file);
} catch (\Throwable $e) {
    echo "ERROR (validation failed): " . $e->getMessage() . PHP_EOL;
    exit(1);
}

echo "  Integrity:  " . $info['integrity'] . PHP_EOL;
echo "  Size:       " . $service->formatSize($info['size']) . " ({$info['size']} bytes)" . PHP_EOL;
echo "  Version:    " . ($info['version'] ?? '(unknown)') . PHP_EOL;
echo "  Migrations: " . $info['migration_count'] . " (latest: " . ($info['latest_migration'] ?? '—') . ")" . PHP_EOL;
echo "  Counts:" . PHP_EOL;
foreach ($info['counts'] as $table => $count) {
    printf("    %-30s %d" . PHP_EOL, $table, $count);
}
if (!empty($info['optional_counts'])) {
    echo "  Optional:" . PHP_EOL;
    foreach ($info['optional_counts'] as $table => $count) {
        printf("    %-30s %d" . PHP_EOL, $table, $count);
    }
}

if ($dryRun) {
    echo PHP_EOL . "Dry-run complete. No changes made." . PHP_EOL;
    exit(0);
}

// ── Apply restore ─────────────────────────────────────────────────────────
$config = new Config();
$dbPath = $config->dbPath();
$mm     = MaintenanceMode::fromConfig($config);

echo PHP_EOL . "Creating pre-restore backup..." . PHP_EOL;
try {
    $pdo        = ConnectionFactory::create($config);
    $preBackup  = $service->create($pdo, $dbPath);
    $pdo        = null; // close connection before file replace
    echo "Pre-restore backup created: $preBackup" . PHP_EOL;
} catch (\Throwable $e) {
    echo "ERROR (pre-restore backup failed): " . $e->getMessage() . PHP_EOL;
    exit(1);
}

$mm->enable('restore in progress');
echo "Maintenance mode enabled." . PHP_EOL;

try {
    $tmpPath = $dbPath . '.restore-' . getmypid();
    if (!copy($file, $tmpPath)) {
        throw new \RuntimeException("Could not copy restore file to: $tmpPath");
    }

    if (!rename($tmpPath, $dbPath)) {
        @unlink($tmpPath);
        throw new \RuntimeException("Could not replace active database. Pre-restore backup is at: $preBackup");
    }

    chmod($dbPath, 0640);

    // Restore ownership to www-data so PHP-FPM can write the database.
    $pwEntry = function_exists('posix_getpwnam') ? posix_getpwnam('www-data') : null;
    if ($pwEntry !== false && $pwEntry !== null) {
        @chown($dbPath, $pwEntry['uid']);
        @chgrp($dbPath, $pwEntry['gid']);
    }

    echo "Restored from: $file" . PHP_EOL;

    // Post-restore validation
    echo "Running post-restore validation..." . PHP_EOL;
    try {
        $postInfo = $service->validate($dbPath);
        echo "  Integrity: " . $postInfo['integrity'] . PHP_EOL;
        echo "  Migrations: " . $postInfo['migration_count'] . PHP_EOL;
    } catch (\Throwable $e) {
        echo "WARNING (post-restore validation): " . $e->getMessage() . PHP_EOL;
    }
} catch (\Throwable $e) {
    echo "ERROR (restore failed): " . $e->getMessage() . PHP_EOL;
    $restoreError = true;
} finally {
    $mm->disable();
    echo "Maintenance mode disabled." . PHP_EOL;
}

if (isset($restoreError)) {
    exit(1);
}

echo "Restore complete." . PHP_EOL;
echo PHP_EOL . "NOTE: Run migrate.php to apply any missing migrations to the restored database." . PHP_EOL;
