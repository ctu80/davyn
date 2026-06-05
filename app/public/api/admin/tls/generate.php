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

$body = json_decode(file_get_contents('php://input'), true) ?? [];

$cn   = trim((string) ($body['common_name'] ?? ''));
$org  = trim((string) ($body['organization'] ?? ''));
$days = (int) ($body['days'] ?? 730);

if ($cn !== '' && !tlsIsValidHostname($cn) && !filter_var($cn, FILTER_VALIDATE_IP)) {
    apiError('Invalid common name', 400);
}
if ($days < 1 || $days > 3650) {
    apiError('Validity must be between 1 and 3650 days', 400);
}
if ($org !== '') {
    $org = preg_replace('/[\x00-\x1F\x7F]/', '', $org);
    if (mb_strlen($org) > 64) apiError('Organization must be 64 characters or fewer', 400);
}

$dns = [];
foreach ((array) ($body['dns_sans'] ?? []) as $d) {
    $d = trim((string) $d);
    if ($d === '') continue;
    if (!tlsIsValidHostname($d)) apiError("Invalid DNS name: $d", 400);
    $dns[] = $d;
}
$ips = [];
foreach ((array) ($body['ip_sans'] ?? []) as $ip) {
    $ip = trim((string) $ip);
    if ($ip === '') continue;
    if (!filter_var($ip, FILTER_VALIDATE_IP)) apiError("Invalid IP address: $ip", 400);
    $ips[] = $ip;
}

if ($cn === '' && $dns === [] && $ips === []) {
    apiError('Provide a common name or at least one SAN', 400);
}

$repo = new SettingsRepository($pdo);
$log  = new ActivityLog($pdo);

try {
    tlsManager($config)->generateSelfSigned($cn, $dns, $ips, $days, $org !== '' ? $org : null);
} catch (\Throwable $e) {
    $log->log((int) $session->currentUserId(), 'admin.tls.generate_failed',
        'Self-signed certificate generation failed', 'tls', null);
    error_log('[davyn] tls generate: ' . $e->getMessage());
    apiError('Certificate generation failed: ' . $e->getMessage(), 422);
}

$repo->set('tls_mode', 'selfsigned');
tlsMarkRestartPending($repo);
$log->log((int) $session->currentUserId(), 'admin.tls.generate',
    'Generated self-signed certificate (CN ' . ($cn !== '' ? $cn : ($dns[0] ?? $ips[0])) . ", $days days)", 'tls', null);

apiJson([
    'ok'     => true,
    'status' => tlsStatusPayload($config, $pdo),
]);
