<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../admin/_response.php';

use Davyn\Auth\SessionManager;
use Davyn\Auth\WebSessionRepository;
use Davyn\Config\Config;
use Davyn\Database\ConnectionFactory;
use Davyn\User\UserRepository;

/**
 * @param bool $allowDuringMaintenance When false (default), non-admin users are
 *        refused with 503 while maintenance mode is active. Endpoints that must
 *        keep working so a blocked user can see the maintenance screen and sign
 *        out (me.php, settings.php) pass true.
 */
function apiUserGuard(bool $allowDuringMaintenance = false): array
{
    $config  = new Config();
    $pdo     = ConnectionFactory::create($config);
    $session = new SessionManager($config);
    $session->setPdo($pdo);

    $userId = $session->currentUserId();

    if ($userId === null) {
        apiError('Unauthenticated', 401);
    }

    $webSessions = new WebSessionRepository($pdo);
    if ($webSessions->isRevoked($session->currentSessionId())) {
        apiError('Unauthenticated', 401);
    }

    // Enforce idle (14 days) and absolute (90 days) session lifetimes.
    if ($webSessions->isExpired($session->currentSessionId(), 1209600, 7776000)) {
        $webSessions->revoke($session->currentSessionId());
        apiError('Session expired', 401);
    }

    $users = new UserRepository($pdo);
    $user  = $users->findById($userId);

    if ($user === null || !$user->isActive) {
        apiError('Unauthenticated', 401);
    }

    // Maintenance mode pauses non-admin data access (admins keep full control so
    // they can manage and switch it off). Mirrors the 503 dav.php returns.
    if (!$allowDuringMaintenance
        && $user->role !== 'admin'
        && \Davyn\Maintenance\MaintenanceMode::fromConfig($config)->isEnabled()
    ) {
        apiError('Service temporarily unavailable (maintenance).', 503);
    }

    $session->touchSession();

    return ['config' => $config, 'pdo' => $pdo, 'user' => $user, 'session' => $session];
}
