<?php
declare(strict_types=1);

require_once __DIR__ . '/_auth.php';

use Davyn\Auth\WebSessionRepository;
use Davyn\Http\Csrf;
use Davyn\User\UserRepository;

apiMethodGuard('POST');
['pdo' => $pdo, 'user' => $user, 'session' => $session] = apiUserGuard();

$csrf = new Csrf($session);
if (!$csrf->verify($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    apiError('Invalid CSRF token', 403);
}

$body        = json_decode(file_get_contents('php://input'), true) ?? [];
$currentPass = (string) ($body['current_password'] ?? '');
$newPass     = (string) ($body['new_password']     ?? '');

if ($currentPass === '') apiError('current_password is required', 400);
if ($newPass     === '') apiError('new_password is required', 400);
if (mb_strlen($newPass) < 8) apiError('new_password must be at least 8 characters', 400);

$users = new UserRepository($pdo);

if (!$users->verifyPassword($user->username, $currentPass)) {
    apiError('Current password is incorrect', 403);
}

$users->changePassword($user->username, $newPass);

// Invalidate all other web sessions so a stolen session cannot survive a
// password change; the session performing the change stays valid.
(new WebSessionRepository($pdo))
    ->revokeAllForUserExcept((int) $user->id, $session->currentSessionId());

apiJson(['ok' => true]);
