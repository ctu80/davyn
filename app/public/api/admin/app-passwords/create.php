<?php
declare(strict_types=1);

require_once __DIR__ . '/../_auth.php';

use Davyn\Audit\ActivityLog;
use Davyn\Auth\AppPasswordRepository;
use Davyn\Http\Csrf;

apiMethodGuard('POST');
['pdo' => $pdo, 'session' => $session] = apiAdminGuard();

$csrf = new Csrf($session);
if (!$csrf->verify($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    apiError('Invalid CSRF token', 403);
}
apiReauthGuard($session);

$body     = json_decode(file_get_contents('php://input'), true) ?? [];
$username = trim((string) ($body['username'] ?? ''));
$name     = trim((string) ($body['name']     ?? ''));
$password = (string) ($body['password'] ?? '');

if ($username === '') apiError('username is required', 400);
if ($name === '')     apiError('name is required', 400);
if ($password === '') apiError('password is required', 400);

try {
    $repo = new AppPasswordRepository($pdo);
    $repo->createForUser($username, $name, $password);
} catch (\InvalidArgumentException $e) {
    $status = str_contains($e->getMessage(), 'already exists') ? 409 : 400;
    apiError($e->getMessage(), $status);
} catch (\Throwable $e) {
    error_log("[davyn] 500 " . __FILE__ . ": " . $e->getMessage()); apiError("Internal server error", 500);
}

(new ActivityLog($pdo))->log(
    (int) $session->currentUserId(), 'admin.app_password.create',
    "Created app password '{$name}' for user '{$username}'", 'user', $username
);

apiJson(['ok' => true]);
