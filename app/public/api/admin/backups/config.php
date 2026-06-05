<?php
declare(strict_types=1);

require_once __DIR__ . '/../_auth.php';

use Davyn\Audit\ActivityLog;
use Davyn\Backup\BackupScheduler;
use Davyn\Http\Csrf;
use Davyn\Settings\SettingsRepository;

apiMethodGuard('GET', 'POST');
['config' => $config, 'pdo' => $pdo, 'session' => $session] = apiAdminGuard();

$settings  = new SettingsRepository($pdo);
$scheduler = new BackupScheduler($pdo, $config->dbPath());

/** Build the current automatic-backup configuration + derived display fields. */
$payload = static function () use ($settings, $scheduler): array {
    $freq = $settings->get('backup_auto_frequency');
    return [
        'frequency'      => $freq,
        'retention_days' => (int) $settings->get('backup_retention_days'),
        'min_keep'       => (int) $settings->get('backup_min_keep'),
        'last_run_at'    => $settings->get('backup_last_run_at') ?: null,
        'next_due_at'    => $scheduler->nextDueAt($freq),
        'auto_active'    => $freq !== 'off',
    ];
};

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    apiJson($payload());
}

// POST — change the schedule. Non-destructive, so CSRF but no reauth.
$csrf = new Csrf($session);
if (!$csrf->verify($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    apiError('Invalid CSRF token', 403);
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];

$frequency = (string) ($body['frequency'] ?? '');
if (!in_array($frequency, BackupScheduler::FREQUENCIES, true)) {
    apiError('Invalid frequency', 400);
}

$retentionDays = filter_var($body['retention_days'] ?? null, FILTER_VALIDATE_INT);
if ($retentionDays === false || $retentionDays < 0 || $retentionDays > 3650) {
    apiError('Retention days must be between 0 and 3650', 400);
}

$minKeep = filter_var($body['min_keep'] ?? null, FILTER_VALIDATE_INT);
if ($minKeep === false || $minKeep < 1 || $minKeep > 100) {
    apiError('Minimum to keep must be between 1 and 100', 400);
}

$settings->set('backup_auto_frequency', $frequency);
$settings->set('backup_retention_days', (string) $retentionDays);
$settings->set('backup_min_keep', (string) $minKeep);

(new ActivityLog($pdo))->log(
    (int) $session->currentUserId(),
    'admin.backup.config',
    sprintf('Backup schedule set to %s (keep %s, min %d)', $frequency, $retentionDays === 0 ? 'forever' : "{$retentionDays}d", $minKeep),
    'backup',
    null,
);

apiJson($payload());
