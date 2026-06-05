<?php
declare(strict_types=1);

require_once __DIR__ . '/../_auth.php';

use Davyn\Audit\ActivityLog;
use Davyn\Http\Csrf;
use Davyn\User\UserRepository;

// Admin renames a user's display name. Username (and all DAV URLs / principals /
// shares / app passwords / audit references keyed on it) stays untouched.
apiMethodGuard('POST');
['pdo' => $pdo, 'session' => $session] = apiAdminGuard();

$csrf = new Csrf($session);
if (!$csrf->verify($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    apiError('Invalid CSRF token', 403);
}
apiReauthGuard($session);

$body        = json_decode(file_get_contents('php://input'), true) ?? [];
$username    = trim((string) ($body['username'] ?? ''));
$displayName = (string) ($body['display_name'] ?? '');

if ($username === '') apiError('username is required', 400);

$users = new UserRepository($pdo);
if ($users->findByUsername($username) === null) {
    apiError("User '{$username}' not found", 404);
}

try {
    $users->updateDisplayName($username, $displayName);
} catch (\InvalidArgumentException $e) {
    $status = str_contains($e->getMessage(), 'already in use') ? 409 : 400;
    apiError($e->getMessage(), $status);
} catch (\Throwable $e) {
    error_log("[davyn] 500 " . __FILE__ . ": " . $e->getMessage());
    apiError('Internal server error', 500);
}

(new ActivityLog($pdo))->log(
    (int) $session->currentUserId(), 'admin.user.rename',
    "Changed display name of '{$username}'", 'user', $username
);

apiJson(['ok' => true, 'username' => $username, 'display_name' => trim($displayName)]);
