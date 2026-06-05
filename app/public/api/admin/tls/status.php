<?php
declare(strict_types=1);

require_once __DIR__ . '/_tls.php';

apiMethodGuard('GET');
['config' => $config, 'pdo' => $pdo] = apiAdminGuard();

apiJson(tlsStatusPayload($config, $pdo));
