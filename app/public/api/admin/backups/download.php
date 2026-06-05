<?php
declare(strict_types=1);

require_once __DIR__ . '/../_auth.php';

use Davyn\Audit\ActivityLog;

apiMethodGuard('GET');
['config' => $config, 'pdo' => $pdo, 'session' => $session] = apiAdminGuard();

$file = $_GET['file'] ?? '';

// Validate: only allow the bare filename pattern, no slashes or traversal
if (!preg_match('/^davyn-backup-[0-9A-Za-z_\-]+\.sqlite$/', $file)) {
    apiError('Invalid filename', 400);
}

$backupDir  = dirname($config->dbPath()) . '/backups';
$fullPath   = $backupDir . '/' . $file;

// Resolve real path to prevent symlink-based traversal
$realBase   = realpath($backupDir);
$realTarget = realpath($fullPath);

if ($realBase === false || $realTarget === false) {
    apiError('File not found', 404);
}

if (!str_starts_with($realTarget, $realBase . '/')) {
    apiError('File not found', 404);
}

if (!is_file($realTarget)) {
    apiError('File not found', 404);
}

$size = filesize($realTarget);

// Downloading a backup exfiltrates the full database (all hashes) — record it.
(new ActivityLog($pdo))->log(
    (int) $session->currentUserId(), 'admin.backup.download',
    "Downloaded backup '{$file}'", 'backup', $file
);

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $file . '"');
header('Content-Length: ' . $size);
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

readfile($realTarget);
exit;
