<?php
declare(strict_types=1);

require_once __DIR__ . '/../_auth.php';

use Davyn\Auth\WebSessionRepository;
use Davyn\Http\Csrf;

apiMethodGuard('POST');
['pdo' => $pdo, 'user' => $user, 'session' => $session] = apiUserGuard();

$csrf = new Csrf($session);
if (!$csrf->verify($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    apiError('Invalid CSRF token', 403);
}

$body      = json_decode(file_get_contents('php://input'), true) ?? [];
$sessionId = (int) ($body['id'] ?? 0);

if ($sessionId <= 0) {
    apiError('id is required', 400);
}

$repo    = new WebSessionRepository($pdo);
$revoked = $repo->revokeById($sessionId, $user->id);

if (!$revoked) {
    apiError('Session not found or already revoked', 404);
}

// If revoking the current session, also invalidate the PHP session
$currentHash = hash('sha256', $session->currentSessionId());
$rows        = $repo->listForUser($user->id);
foreach ($rows as $r) {
    if ((int) $r['id'] === $sessionId && $r['session_id_hash'] === $currentHash) {
        $session->logout();
        apiJson(['ok' => true, 'logged_out' => true]);
    }
}

apiJson(['ok' => true, 'logged_out' => false]);
