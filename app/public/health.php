<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Davyn\Config\Config;
use Davyn\Database\ConnectionFactory;
use Davyn\Maintenance\MaintenanceMode;

$config = new Config();
$mm     = MaintenanceMode::fromConfig($config);
$maint  = $mm->status();

// Single source of truth for the version string (app/bin/VERSION, bind-mounted
// at /var/www/html/bin/VERSION). Surfaced here so a deploy can be verified with
// a single unauthenticated request.
$versionFile = __DIR__ . '/../bin/VERSION';
$version     = is_file($versionFile) ? trim((string) file_get_contents($versionFile)) : 'unknown';

$db = ['driver' => $config->dbDriver(), 'ok' => false];
$status = $maint['enabled'] ? 'maintenance' : 'ok';

try {
    $pdo = ConnectionFactory::create($config);
    $pdo->query('SELECT 1');
    $db['ok'] = true;
} catch (\Throwable $e) {
    $status = 'degraded';
    // Do not expose internal error details to unauthenticated callers.
    error_log('[davyn] health DB check failed: ' . $e->getMessage());
}

header('Content-Type: application/json');
echo json_encode([
    'app'         => $config->appName(),
    'version'     => $version,
    'status'      => $status,
    'database'    => ['ok' => $db['ok']],
    'maintenance' => ['enabled' => $maint['enabled']],
]);
