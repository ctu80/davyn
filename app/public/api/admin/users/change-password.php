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

$body     = json_decode(file_get_contents('php://input'), true) ?? [];
$username = trim((string) ($body['username'] ?? ''));
$password = (string) ($body['password'] ?? '');

if ($username === '') apiError('username is required', 400);
if ($password === '') apiError('password is required', 400);

try {
    $users = new UserRepository($pdo);
    if ($users->findByUsername($username) === null) {
        apiError("User '{$username}' not found", 404);
    }
    $users->changePassword($username, $password);
} catch (\InvalidArgumentException $e) {
    apiError($e->getMessage(), 400);
} catch (\Throwable $e) {
    error_log("[davyn] 500 " . __FILE__ . ": " . $e->getMessage()); apiError("Internal server error", 500);
}

(new ActivityLog($pdo))->log(
    (int) $session->currentUserId(), 'admin.user.change_password', "Changed password for user '{$username}'",
    'user', $username
);

apiJson(['ok' => true]);
