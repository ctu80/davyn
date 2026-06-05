<?php
declare(strict_types=1);

require_once __DIR__ . '/_tls.php';

use Davyn\Audit\ActivityLog;
use Davyn\Http\Csrf;
use Davyn\Settings\SettingsRepository;

apiMethodGuard('POST');
['config' => $config, 'pdo' => $pdo, 'session' => $session] = apiAdminGuard();

$csrf = new Csrf($session);
if (!$csrf->verify($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    apiError('Invalid CSRF token', 403);
}
apiReauthGuard($session);

$repo = new SettingsRepository($pdo);

try {
    tlsManager($config)->remove();
} catch (\Throwable $e) {
    error_log('[davyn] tls remove: ' . $e->getMessage());
    apiError('Could not remove certificate', 500);
}

$repo->set('tls_mode', 'http');
tlsMarkRestartPending($repo);
(new ActivityLog($pdo))->log((int) $session->currentUserId(), 'admin.tls.remove',
    'Removed internal HTTPS certificate (switched to HTTP only)', 'tls', null);

apiJson(['ok' => true, 'status' => tlsStatusPayload($config, $pdo)]);
