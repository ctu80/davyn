<?php
declare(strict_types=1);

require_once __DIR__ . '/../_auth.php';

use Davyn\Audit\ActivityLog;
use Davyn\Http\Csrf;
use Davyn\User\UserRepository;

apiMethodGuard('POST');
['pdo' => $pdo, 'user' => $admin, 'session' => $session] = apiAdminGuard();

$csrf = new Csrf($session);
if (!$csrf->verify($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    apiError('Invalid CSRF token', 403);
}

// Deleting a user erases all of their data — require a fresh reauth.
apiReauthGuard($session);

$body    = json_decode(file_get_contents('php://input'), true) ?? [];
$username = trim((string) ($body['username'] ?? ''));
$confirm  = trim((string) ($body['confirm_username'] ?? ''));

if ($username === '') apiError('username is required', 400);
if ($confirm !== $username) apiError('Confirmation does not match the username', 400);

$users  = new UserRepository($pdo);
$target = $users->findByUsername($username);
if ($target === null) apiError('User not found', 404);

// Never let an admin delete their own account through this endpoint.
if ($target->username === $admin->username || (int) $target->id === (int) $admin->id) {
    apiError('You cannot delete your own account', 409);
}

// Never remove the last remaining admin.
if ($target->role === 'admin' && $users->countActiveAdmins() <= 1) {
    apiError('Cannot delete the last administrator', 409);
}

$uid = (int) $target->id;

try {
    // Defensive: enable FK enforcement for this connection (off by default in SQLite).
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->beginTransaction();

    // 1. Rows that reference the user's collections (delete before the collections).
    $pdo->prepare(
        'DELETE FROM calendar_objects WHERE calendar_id IN (SELECT id FROM calendars WHERE user_id = ?)'
    )->execute([$uid]);
    $pdo->prepare(
        'DELETE FROM calendar_changes WHERE calendar_id IN (SELECT id FROM calendars WHERE user_id = ?)'
    )->execute([$uid]);
    $pdo->prepare(
        'DELETE FROM addressbook_objects WHERE addressbook_id IN (SELECT id FROM addressbooks WHERE user_id = ?)'
    )->execute([$uid]);
    $pdo->prepare(
        'DELETE FROM addressbook_changes WHERE addressbook_id IN (SELECT id FROM addressbooks WHERE user_id = ?)'
    )->execute([$uid]);
    $pdo->prepare(
        'DELETE FROM public_calendar_links WHERE calendar_id IN (SELECT id FROM calendars WHERE user_id = ?)'
    )->execute([$uid]);
    $pdo->prepare(
        "DELETE FROM object_versions
         WHERE (object_type = 'calendar'    AND collection_id IN (SELECT id FROM calendars    WHERE user_id = ?))
            OR (object_type = 'addressbook' AND collection_id IN (SELECT id FROM addressbooks WHERE user_id = ?))"
    )->execute([$uid, $uid]);

    // 2. Shares: both shares granted TO this user and shares OF this user's collections.
    $pdo->prepare('DELETE FROM collection_shares WHERE user_id = ?')->execute([$uid]);
    $pdo->prepare(
        "DELETE FROM collection_shares
         WHERE (collection_type = 'calendar'    AND collection_id IN (SELECT id FROM calendars    WHERE user_id = ?))
            OR (collection_type = 'addressbook' AND collection_id IN (SELECT id FROM addressbooks WHERE user_id = ?))"
    )->execute([$uid, $uid]);

    // 3. The collections themselves + external calendar configs.
    $pdo->prepare('DELETE FROM calendars WHERE user_id = ?')->execute([$uid]);
    $pdo->prepare('DELETE FROM addressbooks WHERE user_id = ?')->execute([$uid]);
    $pdo->prepare('DELETE FROM external_calendars WHERE user_id = ?')->execute([$uid]);

    // 4. Auth artefacts.
    $pdo->prepare('DELETE FROM app_passwords WHERE user_id = ?')->execute([$uid]);
    $pdo->prepare('DELETE FROM web_sessions WHERE user_id = ?')->execute([$uid]);
    $pdo->prepare('DELETE FROM api_tokens WHERE user_id = ?')->execute([$uid]);

    // 5. Preserve the audit trail but unlink it from the deleted user.
    $pdo->prepare('UPDATE activity_log SET actor_user_id = NULL WHERE actor_user_id = ?')->execute([$uid]);

    // 6. Finally the user record.
    $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);

    (new ActivityLog($pdo))->log(
        (int) $admin->id,
        'admin.user.delete',
        "Deleted user '{$username}' and all of their data",
        'user',
        $username,
    );

    $pdo->commit();
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[davyn] user delete failed: ' . $e->getMessage());
    apiError('Could not delete user', 500);
}

apiJson(['ok' => true]);
