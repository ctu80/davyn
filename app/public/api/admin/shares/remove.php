<?php
declare(strict_types=1);

require_once __DIR__ . '/../_auth.php';

use Davyn\Audit\ActivityLog;
use Davyn\Http\Csrf;
use Davyn\Sharing\SharingService;
use Davyn\User\UserRepository;

apiMethodGuard('POST');
['pdo' => $pdo, 'session' => $session] = apiAdminGuard();

$csrf = new Csrf($session);
if (!$csrf->verify($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    apiError('Invalid CSRF token', 403);
}
apiReauthGuard($session);

$body = json_decode(file_get_contents('php://input'), true) ?? [];

$type     = $body['collection_type'] ?? '';
$id       = (int) ($body['collection_id'] ?? 0);
$username = trim((string) ($body['username'] ?? ''));

if (!in_array($type, ['calendar', 'addressbook'], true)) {
    apiError('Invalid collection_type', 400);
}
if ($id <= 0) {
    apiError('Invalid collection_id', 400);
}
if ($username === '') {
    apiError('username required', 400);
}

$users      = new UserRepository($pdo);
$targetUser = $users->findByUsername($username);
if ($targetUser === null) {
    apiError("User '$username' not found", 404);
}

$svc = new SharingService($pdo);

try {
    $svc->removeShare($type, $id, $targetUser->id);
} catch (\InvalidArgumentException $e) {
    apiError($e->getMessage(), 422);
}

(new ActivityLog($pdo))->log(
    (int) $session->currentUserId(), 'admin.share.remove',
    "Removed {$type} share id={$id} for user '{$username}'",
    $type, (string) $id
);

apiJson(['ok' => true]);
