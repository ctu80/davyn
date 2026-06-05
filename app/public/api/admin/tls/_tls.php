<?php
declare(strict_types=1);

require_once __DIR__ . '/../_auth.php';

use Davyn\Config\Config;
use Davyn\Settings\SettingsRepository;
use Davyn\Tls\CertificateManager;

function tlsManager(Config $config): CertificateManager
{
    return new CertificateManager($config->certDir(), $config->certName(), $config->keyName());
}

/** Full status payload shown on the Security page. Admin-only callers. */
function tlsStatusPayload(Config $config, \PDO $pdo): array
{
    $mgr  = tlsManager($config);
    $repo = new SettingsRepository($pdo);

    $base = trim($repo->get('public_base_url'));
    if ($base === '') $base = $config->baseUrl();
    $host = $base !== '' ? (parse_url($base, PHP_URL_HOST) ?: '') : '';

    $pending = $repo->get('tls_restart_pending_at');

    return [
        'mode'                  => $repo->get('tls_mode'),
        'http_mode'             => $repo->get('http_mode'),
        'public_base_url'       => rtrim($base, '/'),
        'host'                  => $host,
        'http_port'             => $config->httpPort(),
        'https_port'            => $config->httpsPort(),
        'restart_required'      => $pending !== '',
        'restart_pending_since' => $pending !== '' ? $pending : null,
        'cert_dir'              => 'config/certs',
        'certificate'           => $mgr->inspect(),
    ];
}

function tlsMarkRestartPending(SettingsRepository $repo): void
{
    $repo->set('tls_restart_pending_at', gmdate('Y-m-d\TH:i:s\Z'));
}

/** Validate a DNS SAN / common name (optionally a leading wildcard). */
function tlsIsValidHostname(string $h): bool
{
    if ($h === '' || strlen($h) > 253) return false;
    if ($h === 'localhost') return true;
    return (bool) preg_match(
        '/^(\*\.)?([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/i',
        $h
    );
}
