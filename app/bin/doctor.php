<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Davyn\Config\Config;
use Davyn\Database\ConnectionFactory;
use Davyn\Maintenance\MaintenanceMode;

try {
    $config = new Config();
    $pdo    = ConnectionFactory::create($config);

    $dbOk = false;
    try {
        $pdo->query('SELECT 1');
        $dbOk = true;
    } catch (\Throwable) {}

    $migrationCount = 0;
    if ($dbOk) {
        try {
            $migrationCount = (int) $pdo->query(
                'SELECT COUNT(*) FROM schema_migrations'
            )->fetchColumn();
        } catch (\Throwable) {}
    }

    $count = fn(string $table) => $dbOk
        ? (int) $pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn()
        : 0;

    $sabreVersion = 'unknown';
    try {
        $sabreVersion = \Sabre\DAV\Version::VERSION;
    } catch (\Throwable) {}

    $mm           = MaintenanceMode::fromConfig($config);
    $maintStatus  = $mm->status();
    $maintEnabled = $maintStatus['enabled'] ? 'yes' : 'no';

    $backupDir    = dirname($config->dbPath()) . '/backups';
    $backupFiles  = is_dir($backupDir) ? glob("$backupDir/davyn-backup-*.sqlite") : [];
    $backupCount  = count($backupFiles);
    $latestBackup = 'none';
    if ($backupCount > 0) {
        usort($backupFiles, fn($a, $b) => filemtime($b) <=> filemtime($a));
        $latestBackup = basename($backupFiles[0]) . ' (' . gmdate('Y-m-d H:i:s', filemtime($backupFiles[0])) . ' UTC)';
    }

    $dataDir       = dirname($config->dbPath());
    $dataDirWrit   = is_writable($dataDir) ? 'yes' : 'no';
    $backupDirWrit = is_dir($backupDir) ? (is_writable($backupDir) ? 'yes' : 'no') : 'missing';

    $versionFile = __DIR__ . '/VERSION';
    $version     = is_file($versionFile) ? trim(file_get_contents($versionFile)) : 'unknown';

    // Extended diagnostics
    $diagData = [];
    if ($dbOk) {
        // Orphan objects (calendar_id/addressbook_id not in parent table)
        $diagData['orphan_cal_objects'] = (int) $pdo->query(
            'SELECT COUNT(*) FROM calendar_objects WHERE calendar_id NOT IN (SELECT id FROM calendars)'
        )->fetchColumn();
        $diagData['orphan_ab_objects'] = (int) $pdo->query(
            'SELECT COUNT(*) FROM addressbook_objects WHERE addressbook_id NOT IN (SELECT id FROM addressbooks)'
        )->fetchColumn();

        // Objects missing UID
        $diagData['cal_missing_uid'] = (int) $pdo->query(
            "SELECT COUNT(*) FROM calendar_objects WHERE (uid IS NULL OR uid = '') AND deleted_at IS NULL"
        )->fetchColumn();
        $diagData['ab_missing_uid'] = (int) $pdo->query(
            "SELECT COUNT(*) FROM addressbook_objects WHERE (uid IS NULL OR uid = '') AND deleted_at IS NULL"
        )->fetchColumn();

        // Duplicate UIDs per collection
        $diagData['cal_dup_uid'] = (int) $pdo->query(
            'SELECT COUNT(*) FROM (
                SELECT calendar_id, uid, COUNT(*) c FROM calendar_objects
                WHERE uid IS NOT NULL AND uid != \'\' AND deleted_at IS NULL
                GROUP BY calendar_id, uid HAVING c > 1
            )'
        )->fetchColumn();
        $diagData['ab_dup_uid'] = (int) $pdo->query(
            'SELECT COUNT(*) FROM (
                SELECT addressbook_id, uid, COUNT(*) c FROM addressbook_objects
                WHERE uid IS NOT NULL AND uid != \'\' AND deleted_at IS NULL
                GROUP BY addressbook_id, uid HAVING c > 1
            )'
        )->fetchColumn();

        // App passwords
        $diagData['app_passwords_active']  = (int) $pdo->query(
            'SELECT COUNT(*) FROM app_passwords WHERE revoked_at IS NULL'
        )->fetchColumn();
        $diagData['app_passwords_revoked'] = (int) $pdo->query(
            'SELECT COUNT(*) FROM app_passwords WHERE revoked_at IS NOT NULL'
        )->fetchColumn();

        // Shares
        $diagData['shares_total'] = (int) $pdo->query(
            'SELECT COUNT(*) FROM collection_shares'
        )->fetchColumn();

        // Trash (soft-deleted objects)
        $diagData['cal_trash']  = (int) $pdo->query(
            'SELECT COUNT(*) FROM calendar_objects WHERE deleted_at IS NOT NULL'
        )->fetchColumn();
        $diagData['ab_trash'] = (int) $pdo->query(
            'SELECT COUNT(*) FROM addressbook_objects WHERE deleted_at IS NOT NULL'
        )->fetchColumn();

        // Phase 3: web sessions, object versions, activity log
        $existingTables = array_column(
            $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(\PDO::FETCH_ASSOC),
            'name'
        );
        $diagData['web_sessions_active'] = in_array('web_sessions', $existingTables)
            ? (int) $pdo->query("SELECT COUNT(*) FROM web_sessions WHERE revoked_at IS NULL")->fetchColumn()
            : 'n/a';
        $diagData['object_versions'] = in_array('object_versions', $existingTables)
            ? (int) $pdo->query("SELECT COUNT(*) FROM object_versions")->fetchColumn()
            : 'n/a';
        $diagData['activity_log'] = in_array('activity_log', $existingTables)
            ? (int) $pdo->query("SELECT COUNT(*) FROM activity_log")->fetchColumn()
            : 'n/a';
    } else {
        $diagData = array_fill_keys([
            'orphan_cal_objects','orphan_ab_objects','cal_missing_uid','ab_missing_uid',
            'cal_dup_uid','ab_dup_uid','app_passwords_active','app_passwords_revoked',
            'shares_total','cal_trash','ab_trash',
            'web_sessions_active','object_versions','activity_log',
        ], 'n/a');
    }

    $lines = [
        ['Version',              $version],
        ['Max ICS size',         $config->maxIcsSize() . ' bytes (' . round($config->maxIcsSize()/1024) . ' KB)'],
        ['Max vCard size',       $config->maxVcardSize() . ' bytes (' . round($config->maxVcardSize()/1024) . ' KB)'],
        ['Max events/user',      $config->maxEventsPerUser() === 0 ? 'unlimited' : (string) $config->maxEventsPerUser()],
        ['Max contacts/user',    $config->maxContactsPerUser() === 0 ? 'unlimited' : (string) $config->maxContactsPerUser()],
        ['App Name',             $config->appName()],
        ['Env',                  $config->appEnv()],
        ['DB Driver',            'sqlite'],
        ['DB OK',                $dbOk ? 'true' : 'false'],
        ['Migrations',           (string) $migrationCount],
        ['Users',                (string) $count('users')],
        ['Calendars',            (string) $count('calendars')],
        ['Addressbooks',         (string) $count('addressbooks')],
        ['Calendar objects',     (string) $count('calendar_objects')],
        ['Addressbook objects',  (string) $count('addressbook_objects')],
        ['PHP Version',          PHP_VERSION],
        ['SabreDAV Version',     $sabreVersion],
        ['---',                  ''],
        ['Data dir writable',    $dataDirWrit],
        ['Backup dir writable',  $backupDirWrit],
        ['Backup count',         (string) $backupCount],
        ['Latest backup',        $latestBackup],
        ['Maintenance enabled',  $maintEnabled],
        ['---',                  ''],
        ['Orphan cal objects',   (string) $diagData['orphan_cal_objects']],
        ['Orphan ab objects',    (string) $diagData['orphan_ab_objects']],
        ['Cal missing UID',      (string) $diagData['cal_missing_uid']],
        ['Ab missing UID',       (string) $diagData['ab_missing_uid']],
        ['Cal dup UIDs',         (string) $diagData['cal_dup_uid']],
        ['Ab dup UIDs',          (string) $diagData['ab_dup_uid']],
        ['App passwords active', (string) $diagData['app_passwords_active']],
        ['App passwords revoked',(string) $diagData['app_passwords_revoked']],
        ['Shares',               (string) $diagData['shares_total']],
        ['Cal trash',            (string) $diagData['cal_trash']],
        ['Ab trash',             (string) $diagData['ab_trash']],
        ['Web sessions active',  (string) $diagData['web_sessions_active']],
        ['Object versions',      (string) $diagData['object_versions']],
        ['Activity log',         (string) $diagData['activity_log']],
        ['---',                  ''],
        ['APP_SECRET configured', $config->hasSecret() ? 'yes' : 'no'],
        ['BASE_URL configured',   $config->baseUrl() !== '' ? 'yes' : 'no'],
        ['Session name',          $config->sessionName()],
        ['Cookie secure',         $config->cookieSecure() ? 'true' : 'false'],
        ['Cookie samesite',       $config->cookieSameSite()],
    ];

    $labelWidth = max(array_map(fn($r) => strlen($r[0]), $lines)) + 2;
    echo "=== Davyn Doctor ===" . PHP_EOL;
    foreach ($lines as [$label, $value]) {
        if ($label === '---') {
            echo PHP_EOL;
            continue;
        }
        printf("%-{$labelWidth}s %s" . PHP_EOL, $label . ':', $value);
    }

    // Warnings
    $warnings = [];
    if (!$config->hasSecret()) {
        $warnings[] = "APP_SECRET is not set or too short (< 32 chars). Set it via APP_SECRET env var.";
    }
    if ($config->baseUrl() === '') {
        $warnings[] = "BASE_URL is not configured. Set it via BASE_URL env var (e.g. https://dav.example.com).";
    }
    if ($config->isProd() && !$config->cookieSecure()) {
        $warnings[] = "COOKIE_SECURE is false in production. Cookies will be sent over HTTP.";
    }

    if (!empty($warnings)) {
        echo PHP_EOL . "WARNINGS:" . PHP_EOL;
        foreach ($warnings as $w) {
            echo "  ! $w" . PHP_EOL;
        }
        if ($config->isProd()) {
            exit(1);
        }
    }
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
