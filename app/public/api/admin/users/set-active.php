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
$active   = isset($body['active']) ? (bool) $body['active'] : null;

if ($username === '')  apiError('username is required', 400);
if ($active === null)  apiError('active (boolean) is required', 400);

try {
    $users = new UserRepository($pdo);
    $user  = $users->findByUsername($username);
    if ($user === null) {
        apiError("User '{$username}' not found", 404);
    }

    if (!$active && $user->role === 'admin' && $user->isActive) {
        if ($users->countActiveAdmins() <= 1) {
            apiError('Cannot deactivate the last active admin', 409);
        }
    }

    if ($user->isActive !== $active) {
        $users->setActive($username, $active);
    }
} catch (\InvalidArgumentException $e) {
    apiError($e->getMessage(), 400);
} catch (\Throwable $e) {
    error_log("[davyn] 500 " . __FILE__ . ": " . $e->getMessage()); apiError("Internal server error", 500);
}

(new ActivityLog($pdo))->log(
    (int) $session->currentUserId(), 'admin.user.set_active',
    "Set user '{$username}' active=" . ($active ? 'true' : 'false'),
    'user', $username
);

apiJson(['ok' => true]);
