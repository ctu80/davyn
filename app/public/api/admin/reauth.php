<?php
declare(strict_types=1);

require_once __DIR__ . '/_auth.php';

use Davyn\Auth\ReauthManager;
use Davyn\Http\Csrf;
use Davyn\User\UserRepository;

apiMethodGuard('POST');
['config' => $config, 'pdo' => $pdo, 'session' => $session, 'user' => $adminUser] = apiAdminGuard();

$csrf = new Csrf($session);
if (!$csrf->verify($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    apiError('Invalid CSRF token', 403);
}

$body     = json_decode(file_get_contents('php://input'), true) ?? [];
$password = (string) ($body['password'] ?? '');

if ($password === '') {
    apiError('password is required', 400);
}

// Must not be an app password — verify against the real user password hash only
$users = new UserRepository($pdo);
$user  = $users->findById($adminUser->id);

if ($user === null || !password_verify($password, $user->passwordHash)) {
    apiError('Invalid password', 401);
}

$reauth = new ReauthManager($session);
$reauth->confirm();

apiJson(['ok' => true, 'expires_at' => $reauth->expiresAt()]);
