<?php
declare(strict_types=1);

require_once __DIR__ . '/_auth.php';

use Davyn\Audit\ActivityLog;
use Davyn\Http\Csrf;
use Davyn\Maintenance\MaintenanceMode;
use Davyn\Settings\SettingsRepository;
use Davyn\Tls\CertificateManager;

apiMethodGuard('GET');

// Accept either session auth or Bearer token with read:status scope
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (str_starts_with($authHeader, 'Bearer ')) {
    ['config' => $config, 'pdo' => $pdo] = apiBearerGuard('read:status');
    $session = null;
} else {
    ['config' => $config, 'pdo' => $pdo, 'session' => $session] = apiAdminGuard();
}

$dbOk = false;
try {
    $pdo->query('SELECT 1');
    $dbOk = true;
} catch (\Throwable) {}

$count = fn(string $table) => (int) $pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn();

$backupDir   = dirname($config->dbPath()) . '/backups';
$backupFiles = is_dir($backupDir) ? (glob("$backupDir/davyn-backup-*.sqlite") ?: []) : [];
$backupCount = count($backupFiles);
$latestBackup = null;
if ($backupCount > 0) {
    usort($backupFiles, fn($a, $b) => filemtime($b) <=> filemtime($a));
    $latestBackup = [
        'filename'    => basename($backupFiles[0]),
        'modified_at' => gmdate('Y-m-d\TH:i:s\Z', filemtime($backupFiles[0])),
    ];
}

$mm          = MaintenanceMode::fromConfig($config);
$mmStatus    = $mm->status();

$log         = new ActivityLog($pdo);
$recentAct   = $log->recent(5);

$csrfToken = $session !== null ? (new Csrf($session))->token() : null;

$settings    = new SettingsRepository($pdo);
$certInspect = (new CertificateManager($config->certDir(), $config->certName(), $config->keyName()))->inspect();
$tls = [
    'mode'             => $settings->get('tls_mode'),
    'configured'       => $certInspect['has_certificate'],
    'certificate'      => $certInspect['status'],
    'days_remaining'   => $certInspect['days_remaining'],
    'restart_required' => $settings->get('tls_restart_pending_at') !== '',
];

apiHeaders();
echo json_encode([
    'app'           => $config->appName(),
    'env'           => $config->appEnv(),
    'database'      => ['ok' => $dbOk],
    'maintenance'   => ['enabled' => $mmStatus['enabled'], 'reason' => $mmStatus['reason'] ?? null],
    'latest_backup' => $latestBackup,
    'backup_auto_frequency' => $settings->get('backup_auto_frequency'),
    'recent_activity' => $recentAct,
    'counts'        => [
        'users'               => $count('users'),
        'calendars'           => $count('calendars'),
        'addressbooks'        => $count('addressbooks'),
        'calendar_objects'    => $count('calendar_objects'),
        'addressbook_objects' => $count('addressbook_objects'),
        'backups'             => $backupCount,
    ],
    'tls'           => $tls,
    'csrf_token'    => $csrfToken,
]);
