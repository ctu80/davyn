<?php
declare(strict_types=1);

require_once __DIR__ . '/../_auth.php';

use Davyn\Audit\ActivityLog;
use Davyn\Backup\BackupService;
use Davyn\Database\ConnectionFactory;
use Davyn\Http\Csrf;

apiMethodGuard('POST');
['config' => $config, 'session' => $session] = apiAdminGuard();

$csrf       = new Csrf($session);
$csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;

if (!$csrf->verify($csrfHeader)) {
    apiError('Invalid CSRF token', 403);
}

try {
    $pdo     = ConnectionFactory::create($config);
    $service = new BackupService();

    $backupFile = $service->create($pdo, $config->dbPath());
    (new ActivityLog($pdo))->log(
        (int) $session->currentUserId(), 'admin.backup.create',
        'Created backup: ' . basename($backupFile), 'backup', basename($backupFile)
    );
    $size       = filesize($backupFile);
    $sizeHuman  = $size >= 1024 * 1024
        ? round($size / (1024 * 1024), 2) . ' MB'
        : round($size / 1024, 1) . ' KB';

    apiJson([
        'ok'     => true,
        'backup' => [
            'filename'    => basename($backupFile),
            'size'        => $size,
            'size_human'  => $sizeHuman,
            'modified_at' => gmdate('Y-m-d H:i:s'),
        ],
    ]);
} catch (\Throwable $e) {
    error_log("[davyn] 500 " . __FILE__ . ": " . $e->getMessage()); apiError("Internal server error", 500);
}
