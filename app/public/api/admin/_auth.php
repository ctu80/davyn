<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/_response.php';

use Davyn\Auth\RateLimiter;
use Davyn\Auth\ReauthManager;
use Davyn\Auth\SessionManager;
use Davyn\Auth\WebSessionRepository;
use Davyn\Config\Config;
use Davyn\Database\ConnectionFactory;
use Davyn\User\UserRepository;

function apiAdminGuard(): array
{
    $config  = new Config();
    $pdo     = ConnectionFactory::create($config);
    $session = new SessionManager($config);
    $session->setPdo($pdo);

    $userId = $session->currentUserId();

    if ($userId === null) {
        apiError('Unauthenticated', 401);
    }

    // Check session not explicitly revoked
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

    if ($user->role !== 'admin') {
        apiError('Forbidden', 403);
    }

    $session->touchSession();

    // Secondary heartbeat for request-triggered automatic backups (DAV traffic is the
    // primary one). Throttled + locked internally, runs after the response is flushed.
    \Davyn\Backup\BackupScheduler::arm($config, $pdo);

    return ['config' => $config, 'pdo' => $pdo, 'user' => $user, 'session' => $session];
}

function apiReauthGuard(SessionManager $session): void
{
    $reauth = new ReauthManager($session);
    if (!$reauth->isValid()) {
        apiError('Reauthentication required', 403);
    }
}

function apiBearerGuard(string ...$requiredScopes): array
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+([a-f0-9]{64})$/i', $header, $m)) {
        apiError('Unauthenticated', 401);
    }
    $tokenRaw  = $m[1];
    $tokenHash = hash('sha256', $tokenRaw);

    $config = new Config();
    $pdo    = ConnectionFactory::create($config);

    // Throttle repeated invalid-token attempts per client IP.
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $limiter  = new RateLimiter($pdo);
    if ($limiter->isBlocked('bearer', $clientIp)) {
        apiError('Too many failed attempts', 429);
    }

    $stmt = $pdo->prepare(
        'SELECT t.*, u.role, u.is_active FROM api_tokens t
         JOIN users u ON u.id = t.user_id
         WHERE t.token_hash = ? AND t.revoked_at IS NULL'
    );
    $stmt->execute([$tokenHash]);
    $token = $stmt->fetch();

    if (!$token || !$token['is_active']) {
        $limiter->recordFailure('bearer', $clientIp);
        apiError('Unauthenticated', 401);
    }
    if ($token['role'] !== 'admin') {
        $limiter->recordFailure('bearer', $clientIp);
        apiError('Forbidden', 403);
    }
    $limiter->recordSuccess('bearer', $clientIp);

    $tokenScopes = array_filter(array_map('trim', explode(',', $token['scopes'])));
    foreach ($requiredScopes as $req) {
        if (!in_array($req, $tokenScopes, true)) {
            apiError('Insufficient scope: ' . $req, 403);
        }
    }

    // Update last_used_at
    $pdo->prepare('UPDATE api_tokens SET last_used_at = ? WHERE id = ?')
        ->execute([gmdate('Y-m-d\TH:i:s\Z'), $token['id']]);

    return ['config' => $config, 'pdo' => $pdo, 'token' => $token];
}
