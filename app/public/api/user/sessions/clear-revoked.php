<?php
declare(strict_types=1);

require_once __DIR__ . '/../_auth.php';

use Davyn\Auth\WebSessionRepository;
use Davyn\Http\Csrf;

apiMethodGuard('POST');
['pdo' => $pdo, 'user' => $user, 'session' => $session, 'config' => $config] = apiUserGuard();

$csrf = new Csrf($session);
if (!$csrf->verify($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    apiError('Invalid CSRF token', 403);
}

$repo = new WebSessionRepository($pdo);
// Delete all revoked sessions.
$deleted = $repo->deleteRevokedForUser((int) $user->id);
// Also sweep long-abandoned (never-revoked but inactive) sessions.
$repo->cleanupForUser((int) $user->id, $config->sessionCleanupRevokedDays(), $config->sessionCleanupInactiveDays());

apiJson(['ok' => true, 'deleted' => $deleted]);
