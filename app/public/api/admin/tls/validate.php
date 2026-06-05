<?php
declare(strict_types=1);

require_once __DIR__ . '/_tls.php';

use Davyn\Audit\ActivityLog;
use Davyn\Http\Csrf;
use Davyn\Tls\CertificateManager;

apiMethodGuard('POST');
['config' => $config, 'pdo' => $pdo, 'session' => $session] = apiAdminGuard();

$csrf = new Csrf($session);
if (!$csrf->verify($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    apiError('Invalid CSRF token', 403);
}

$status = tlsStatusPayload($config, $pdo);
$certStatus = $status['certificate']['status'] ?? CertificateManager::STATUS_MISSING;

if (!in_array($certStatus, [CertificateManager::STATUS_VALID, CertificateManager::STATUS_MISSING], true)) {
    (new ActivityLog($pdo))->log((int) $session->currentUserId(), 'admin.tls.validate_failed',
        'Certificate validation reported: ' . $certStatus, 'tls', null);
}

apiJson(['ok' => true, 'status' => $status]);
