<?php
declare(strict_types=1);

require_once __DIR__ . '/_tls.php';

apiMethodGuard('GET');
['config' => $config] = apiAdminGuard();

$pem = tlsManager($config)->publicCertPem();
if ($pem === null) {
    apiError('No certificate installed', 404);
}

// Public certificate only — the private key is never served.
header('Content-Type: application/x-pem-file');
header('Content-Disposition: attachment; filename="davyn.crt"');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
echo $pem;
