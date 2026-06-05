<?php
declare(strict_types=1);

require_once __DIR__ . '/_setup.php';

use Davyn\Http\Csrf;
use Davyn\Http\PublicUrl;

apiMethodGuard('GET');
['config' => $config, 'pdo' => $pdo, 'session' => $session, 'setup' => $setup] = setupContext();

apiJson([
    // Whether Davyn already has an active admin. When true, setup is locked.
    'initialized'     => $setup->isInitialized(),
    // Lets the wizard surface an HTTP warning before the first admin is created.
    'https'           => setupIsHttps(),
    'public_base_url' => PublicUrl::base($config, $pdo),
    // Session-bound token so the unauthenticated create-admin POST is CSRF-checked.
    'csrf_token'      => (new Csrf($session))->token(),
]);
