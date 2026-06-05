<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../admin/_response.php';

use Davyn\Auth\SessionManager;
use Davyn\Config\Config;
use Davyn\Database\ConnectionFactory;
use Davyn\Setup\SetupService;
use Davyn\User\UserRepository;

/**
 * Shared bootstrap for the unauthenticated first-run setup endpoints. There is
 * deliberately no auth guard: these endpoints are only meaningful before any
 * admin exists, and each one enforces that state server-side via SetupService.
 *
 * @return array{config: Config, pdo: \PDO, session: SessionManager, users: UserRepository, setup: SetupService}
 */
function setupContext(): array
{
    $config  = new Config();
    $pdo     = ConnectionFactory::create($config);
    $session = new SessionManager($config);
    $session->setPdo($pdo);
    $users   = new UserRepository($pdo);
    $setup   = new SetupService($pdo, $users);

    return [
        'config'  => $config,
        'pdo'     => $pdo,
        'session' => $session,
        'users'   => $users,
        'setup'   => $setup,
    ];
}

/** True when the current request reached Davyn over HTTPS (directly or via a proxy). */
function setupIsHttps(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    $proto = (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');
    return strtolower(trim(explode(',', $proto)[0])) === 'https';
}
