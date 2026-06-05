<?php
declare(strict_types=1);

// Front-controller default. Caddy's catch-all routes every unrouted path here
// (the SPA lives under /app, the JSON APIs under /api, DAV under /dav). Rather
// than show a dead landing page for unknown URLs like /data/dkfg, funnel the
// visitor somewhere sensible:
//   - signed in     → the app
//   - no admin yet   → the setup wizard
//   - otherwise      → the login page
// Any bootstrap error (e.g. DB not migrated) falls back to /login.

require __DIR__ . '/../vendor/autoload.php';

use Davyn\Auth\SessionManager;
use Davyn\Config\Config;
use Davyn\Database\ConnectionFactory;
use Davyn\User\UserRepository;

$target = '/login';

try {
    $config  = new Config();
    $pdo     = ConnectionFactory::create($config);
    $session = new SessionManager($config);
    $session->setPdo($pdo);

    if ($session->isLoggedIn()) {
        $target = '/app/';
    } elseif ((new UserRepository($pdo))->countActiveAdmins() === 0) {
        $target = '/setup';
    }
} catch (\Throwable) {
    // Keep the default /login on any failure — never leak an error page.
}

header('Location: ' . $target, true, 302);
