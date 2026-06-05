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

// Bound the request body to keep memory predictable (cert + key + chain).
$raw = file_get_contents('php://input', false, null, 0, 3 * CertificateManager::MAX_PEM_BYTES + 1024);
if ($raw !== false && strlen($raw) > 3 * CertificateManager::MAX_PEM_BYTES) {
    apiError('Upload too large', 413);
}
$body = json_decode($raw ?: '', true) ?? [];

$certPem  = trim((string) ($body['certificate'] ?? ''));
$keyPem   = trim((string) ($body['private_key'] ?? ''));
$chainPem = trim((string) ($body['chain'] ?? ''));

if ($certPem === '' || $keyPem === '') {
    apiError('Certificate and private key are required', 400);
}

$repo = new SettingsRepository($pdo);
$log  = new ActivityLog($pdo);

$expectedHost = '';
$base = trim($repo->get('public_base_url')) ?: $config->baseUrl();
if ($base !== '') $expectedHost = parse_url($base, PHP_URL_HOST) ?: '';

try {
    $warnings = tlsManager($config)->installCustom(
        $certPem,
        $keyPem,
        $chainPem !== '' ? $chainPem : null,
        $expectedHost !== '' ? $expectedHost : null,
    );
} catch (\Throwable $e) {
    $log->log((int) $session->currentUserId(), 'admin.tls.upload_failed',
        'Custom certificate validation failed', 'tls', null);
    apiError($e->getMessage(), 422);
}

$repo->set('tls_mode', 'custom');
tlsMarkRestartPending($repo);
$log->log((int) $session->currentUserId(), 'admin.tls.upload',
    'Uploaded custom certificate', 'tls', null);

apiJson([
    'ok'       => true,
    'warnings' => $warnings,
    'status'   => tlsStatusPayload($config, $pdo),
]);
