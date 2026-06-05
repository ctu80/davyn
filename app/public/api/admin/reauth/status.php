<?php
declare(strict_types=1);

require_once __DIR__ . '/../_auth.php';

use Davyn\Auth\ReauthManager;

apiMethodGuard('GET');
['session' => $session] = apiAdminGuard();

$reauth = new ReauthManager($session);

apiJson([
    'reauthenticated' => $reauth->isValid(),
    'expires_at'      => $reauth->expiresAt(),
]);
