<?php
declare(strict_types=1);

require_once __DIR__ . '/_setup.php';

use Davyn\Audit\ActivityLog;
use Davyn\Auth\AuthService;
use Davyn\Http\Csrf;
use Davyn\Setup\SetupAlreadyDoneException;

apiMethodGuard('POST');
['pdo' => $pdo, 'session' => $session, 'users' => $users, 'setup' => $setup] = setupContext();

// CSRF: session-bound, the same mechanism used everywhere else in Davyn. The
// token is handed out by /api/setup/status and embedded in the setup page.
$csrf = new Csrf($session);
if (!$csrf->verify($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    apiError('Invalid CSRF token', 403);
}

// Defence-in-depth: reject before parsing if already initialized. createFirstAdmin
// re-checks this race-safely under a write lock.
if ($setup->isInitialized()) {
    apiError('Davyn is already initialized.', 409);
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$username    = trim((string) ($body['username']         ?? ''));
$displayName = trim((string) ($body['display_name']     ?? ''));
$password    = (string) ($body['password']              ?? '');
$confirm     = (string) ($body['password_confirm']      ?? '');

if ($username === '') apiError('username is required', 400);
if ($password === '') apiError('password is required', 400);
// Confirmation is primarily a UI safeguard; enforce it server-side when sent.
if ($confirm !== '' && $confirm !== $password) apiError('Passwords do not match', 400);

try {
    $user = $setup->createFirstAdmin($username, $displayName, $password);
} catch (SetupAlreadyDoneException) {
    apiError('Davyn is already initialized.', 409);
} catch (\InvalidArgumentException $e) {
    $status = str_contains($e->getMessage(), 'already exists') ? 409 : 400;
    apiError($e->getMessage(), $status);
} catch (\Throwable $e) {
    error_log("[davyn] 500 " . __FILE__ . ": " . $e->getMessage());
    apiError('Internal server error', 500);
}

// First audit entry for the instance.
(new ActivityLog($pdo))->log(
    $user->id, 'setup.create_admin', "First admin '{$user->username}' created via setup", 'user', $user->username
);

// Log the new admin straight in (this request already carries the session
// cookie), so the browser can go directly to the app. Fall back to the login
// page if the login step does not take for any reason.
$loggedIn = (new AuthService($users, $session))->login($username, $password);

apiJson([
    'ok'   => true,
    'user' => [
        'username'     => $user->username,
        'display_name' => $user->displayName,
        'role'         => $user->role,
    ],
    'redirect' => $loggedIn ? '/app/' : '/login',
], 201);
