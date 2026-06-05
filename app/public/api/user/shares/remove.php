<?php
declare(strict_types=1);

require_once __DIR__ . '/../_auth.php';

use Davyn\Audit\ActivityLog;
use Davyn\Http\Csrf;
use Davyn\Sharing\CollectionNotFoundException;
use Davyn\Sharing\NotCollectionOwnerException;
use Davyn\Sharing\SharingService;
use Davyn\User\UserRepository;

// Revoke a share from a collection the current user OWNS.
apiMethodGuard('POST');
['pdo' => $pdo, 'user' => $user, 'session' => $session] = apiUserGuard();

$csrf = new Csrf($session);
if (!$csrf->verify($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    apiError('Invalid CSRF token', 403);
}

$body     = json_decode(file_get_contents('php://input'), true) ?? [];
$type     = $body['collection_type'] ?? '';
$id       = (int) ($body['collection_id'] ?? 0);
$username = trim((string) ($body['username'] ?? ''));

if (!in_array($type, ['calendar', 'addressbook'], true)) apiError('Invalid collection_type', 400);
if ($id <= 0)        apiError('Invalid collection_id', 400);
if ($username === '') apiError('username required', 400);

$targetUser = (new UserRepository($pdo))->findByUsername($username);
if ($targetUser === null) {
    apiError("User '$username' not found", 404);
}

$svc = new SharingService($pdo);
try {
    $svc->removeShareAsOwner((int) $user->id, $type, $id, $targetUser->id);
} catch (CollectionNotFoundException $e) {
    apiError($e->getMessage(), 404);
} catch (NotCollectionOwnerException $e) {
    apiError($e->getMessage(), 403);
} catch (\InvalidArgumentException $e) {
    apiError($e->getMessage(), 422);
}

(new ActivityLog($pdo))->log(
    (int) $user->id, 'user.share.remove',
    "Removed share of own {$type} id={$id} from '{$username}'",
    $type, (string) $id
);

apiJson(['ok' => true]);
