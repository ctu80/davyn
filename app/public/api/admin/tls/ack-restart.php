<?php
declare(strict_types=1);

require_once __DIR__ . '/_tls.php';

use Davyn\Http\Csrf;
use Davyn\Settings\SettingsRepository;

apiMethodGuard('POST');
['config' => $config, 'pdo' => $pdo, 'session' => $session] = apiAdminGuard();

$csrf = new Csrf($session);
if (!$csrf->verify($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    apiError('Invalid CSRF token', 403);
}

// Admin confirms they've restarted Caddy; clear the "restart required" hint.
(new SettingsRepository($pdo))->set('tls_restart_pending_at', '');

apiJson(['ok' => true, 'status' => tlsStatusPayload($config, $pdo)]);
