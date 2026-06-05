<?php
declare(strict_types=1);

require_once __DIR__ . '/_auth.php';

use Davyn\Audit\ActivityLog;
use Davyn\Http\Csrf;
use Davyn\Maintenance\MaintenanceMode;

apiMethodGuard('GET', 'POST');
['config' => $config, 'pdo' => $pdo, 'user' => $user, 'session' => $session] = apiAdminGuard();

$maintenance = MaintenanceMode::fromConfig($config);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    apiJson($maintenance->status());
}

// POST — toggle maintenance. Disrupts sync but is reversible from this same web UI,
// so CSRF is required but reauth is not (the admin must stay able to flip it back).
$csrf = new Csrf($session);
if (!$csrf->verify($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    apiError('Invalid CSRF token', 403);
}

$body    = json_decode(file_get_contents('php://input'), true) ?? [];
$enabled = filter_var($body['enabled'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
if ($enabled === null) {
    apiError('enabled must be a boolean', 400);
}

if ($enabled) {
    $reason = trim((string) ($body['reason'] ?? ''));
    if (mb_strlen($reason) > 200) {
        $reason = mb_substr($reason, 0, 200);
    }
    $maintenance->enable($reason);
} else {
    $maintenance->disable();
}

(new ActivityLog($pdo))->log(
    (int) $user->id,
    'admin.maintenance.' . ($enabled ? 'on' : 'off'),
    $enabled
        ? ('Maintenance mode enabled' . ($reason !== '' ? ": {$reason}" : ''))
        : 'Maintenance mode disabled',
    'maintenance',
    null,
);

apiJson($maintenance->status());
