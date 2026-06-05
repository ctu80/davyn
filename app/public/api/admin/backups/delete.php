<?php
declare(strict_types=1);

require_once __DIR__ . '/../_auth.php';

use Davyn\Audit\ActivityLog;
use Davyn\Database\ConnectionFactory;
use Davyn\Http\Csrf;

apiMethodGuard('POST');
['config' => $config, 'session' => $session] = apiAdminGuard();

$csrf = new Csrf($session);
if (!$csrf->verify($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    apiError('Invalid CSRF token', 403);
}

// Deleting a backup is destructive and irreversible — require a fresh reauth.
apiReauthGuard($session);

$body = json_decode(file_get_contents('php://input'), true) ?? [];

// Accept a single filename (back-compat) or a batch under "filenames". One reauth
// (already enforced above) covers the whole batch.
$names = [];
if (isset($body['filenames']) && is_array($body['filenames'])) {
    $names = $body['filenames'];
} elseif (isset($body['filename'])) {
    $names = [$body['filename']];
}
$names = array_values(array_unique(array_map('strval', $names)));

if ($names === []) {
    apiError('No backup specified', 400);
}

$backupDir = dirname($config->dbPath()) . '/backups';
$realDir   = realpath($backupDir);
if ($realDir === false) {
    apiError('Backup not found', 404);
}

$deleted = [];
$failed  = [];

foreach ($names as $name) {
    // Strict allow-list: only the exact backup filename shape, no path separators.
    // This blocks traversal (../) and deletion of anything but a real backup file.
    if (!preg_match('/^davyn-backup-\d{8}-\d{6}\.sqlite$/', $name)) {
        $failed[] = $name;
        continue;
    }
    // Resolve and confirm the target really sits inside the backup dir.
    $realTarget = realpath($backupDir . '/' . $name);
    if ($realTarget === false
        || !str_starts_with($realTarget, $realDir . DIRECTORY_SEPARATOR)
        || !is_file($realTarget)) {
        $failed[] = $name;
        continue;
    }
    if (@unlink($realTarget)) {
        $deleted[] = $name;
    } else {
        error_log('[davyn] backup delete failed: ' . $realTarget);
        $failed[] = $name;
    }
}

if ($deleted !== []) {
    try {
        $pdo = ConnectionFactory::create($config);
        $summary = count($deleted) === 1
            ? 'Deleted backup ' . $deleted[0]
            : 'Deleted ' . count($deleted) . ' backups';
        (new ActivityLog($pdo))->log(
            (int) $session->currentUserId(),
            'admin.backup.delete',
            $summary,
            'backup',
            count($deleted) === 1 ? $deleted[0] : null,
        );
    } catch (\Throwable) {
        // logging must never block the response
    }
}

// If nothing could be deleted, surface it as an error; otherwise report the batch.
if ($deleted === []) {
    apiError('Backup not found', 404);
}

apiJson(['ok' => true, 'deleted' => $deleted, 'failed' => $failed]);
