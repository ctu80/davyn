<?php
declare(strict_types=1);

require_once __DIR__ . '/../_auth.php';

use Davyn\Audit\ActivityLog;
use Davyn\Http\Csrf;
use Davyn\User\UserRepository;

apiMethodGuard('POST');
['config' => $config, 'pdo' => $pdo, 'session' => $session] = apiAdminGuard();

$csrf = new Csrf($session);
if (!$csrf->verify($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    apiError('Invalid CSRF token', 403);
}
apiReauthGuard($session);

$body = json_decode(file_get_contents('php://input'), true) ?? [];

$username    = trim((string) ($body['username']     ?? ''));
$displayName = trim((string) ($body['display_name'] ?? ''));
$role        = trim((string) ($body['role']         ?? ''));
$password    = (string) ($body['password'] ?? '');

if ($username === '')    apiError('username is required', 400);
if ($displayName === '') apiError('display_name is required', 400);
if ($password === '')    apiError('password is required', 400);
if (!in_array($role, ['admin', 'user', 'read_only'], true)) {
    apiError('role must be admin, user, or read_only', 400);
}

try {
    $users = new UserRepository($pdo);
    $user  = $users->createUser($username, $displayName, $password, $role);
} catch (\InvalidArgumentException $e) {
    $status = str_contains($e->getMessage(), 'already exists') ? 409 : 400;
    apiError($e->getMessage(), $status);
} catch (\Throwable $e) {
    error_log("[davyn] 500 " . __FILE__ . ": " . $e->getMessage()); apiError("Internal server error", 500);
}

(new ActivityLog($pdo))->log(
    (int) $session->currentUserId(), 'admin.user.create', "Created user '{$username}' role={$role}",
    'user', $username
);

apiJson([
    'ok'   => true,
    'user' => [
        'username'     => $user->username,
        'display_name' => $user->displayName,
        'role'         => $user->role,
        'active'       => $user->isActive,
        'created_at'   => $user->createdAt,
    ],
]);
