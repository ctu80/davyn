<?php
declare(strict_types=1);

require_once __DIR__ . '/_tls.php';

use Davyn\Audit\ActivityLog;
use Davyn\Http\Csrf;
use Davyn\Settings\SettingsRepository;
use Davyn\Tls\CertificateManager;

apiMethodGuard('POST');
['config' => $config, 'pdo' => $pdo, 'session' => $session] = apiAdminGuard();

$csrf = new Csrf($session);
if (!$csrf->verify($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    apiError('Invalid CSRF token', 403);
}
apiReauthGuard($session);

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$mode = (string) ($body['mode'] ?? '');
if (!in_array($mode, ['enabled', 'redirect'], true)) {
    apiError("mode must be 'enabled' or 'redirect'", 400);
}

$repo = new SettingsRepository($pdo);
$mgr  = tlsManager($config);

// Precondition: forcing HTTPS is only safe once HTTPS is actually configured. Require
// a non-http tls_mode AND a usable certificate on disk.
if ($mode === 'redirect') {
    $cert = $mgr->inspect();
    if (!CertificateManager::canForceHttps((string) $repo->get('tls_mode'), (string) ($cert['status'] ?? ''))) {
        apiError('Configure HTTPS before disabling plain HTTP', 400);
    }
}

$repo->set('http_mode', $mode);
$mgr->setHttpDisabledMarker($mode === 'redirect');
tlsMarkRestartPending($repo);

(new ActivityLog($pdo))->log(
    (int) $session->currentUserId(),
    'admin.tls.http_mode',
    $mode === 'redirect' ? 'Plain HTTP disabled (redirect to HTTPS)' : 'Plain HTTP re-enabled',
    'tls',
    null,
);

apiJson([
    'ok'     => true,
    'status' => tlsStatusPayload($config, $pdo),
]);
