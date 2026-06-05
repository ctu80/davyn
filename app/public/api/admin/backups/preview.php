<?php
declare(strict_types=1);

require_once __DIR__ . '/../_auth.php';

use Davyn\Backup\BackupService;

apiMethodGuard('GET');
['config' => $config] = apiAdminGuard();

$file = trim($_GET['file'] ?? '');
if ($file === '') {
    apiError('file parameter is required', 400);
}

// Block path traversal
if (str_contains($file, '/') || str_contains($file, '\\') || str_contains($file, '..')) {
    apiError('Invalid file name', 400);
}

if (!preg_match('/^davyn-backup-[\d\-]+\.sqlite$/', $file)) {
    apiError('Invalid backup filename format', 400);
}

$backupDir  = dirname($config->dbPath()) . '/backups';
$fullPath   = $backupDir . '/' . $file;

// Resolve real path to prevent symlink-based traversal (mirrors download.php).
$realBase   = realpath($backupDir);
$realTarget = realpath($fullPath);

if ($realBase === false || $realTarget === false
    || !str_starts_with($realTarget, $realBase . '/')
    || !is_file($realTarget)) {
    apiError('Backup file not found', 404);
}

$service = new BackupService();
try {
    $info = $service->validate($realTarget);
} catch (\Throwable $e) {
    error_log('[davyn] backup preview validation failed: ' . $e->getMessage());
    apiJson([
        'ok'        => false,
        'error'     => 'Backup validation failed',
        'file'      => $file,
        'integrity' => 'failed',
    ], 422);
}

apiJson([
    'ok'               => true,
    'file'             => $file,
    'size'             => $info['size'],
    'size_human'       => $service->formatSize($info['size']),
    'integrity'        => $info['integrity'],
    'version'          => $info['version'],
    'migration_count'  => $info['migration_count'],
    'latest_migration' => $info['latest_migration'],
    'counts'           => $info['counts'],
    'optional_counts'  => $info['optional_counts'],
]);
