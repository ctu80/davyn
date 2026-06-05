<?php
declare(strict_types=1);

require_once __DIR__ . '/_auth.php';

use Davyn\Sharing\CollectionNotFoundException;
use Davyn\Sharing\NotCollectionOwnerException;
use Davyn\Sharing\SharingService;

// List the shares of one collection the current user OWNS. Ownership is enforced
// server-side: a user can never inspect shares of someone else's collection.
apiMethodGuard('GET');
['pdo' => $pdo, 'user' => $user] = apiUserGuard();

$type = $_GET['type'] ?? '';
$id   = (int) ($_GET['id'] ?? 0);

if (!in_array($type, ['calendar', 'addressbook'], true) || $id <= 0) {
    apiError('Missing or invalid type/id', 400);
}

$svc = new SharingService($pdo);
try {
    $svc->assertOwnedBy((int) $user->id, $type, $id);
} catch (CollectionNotFoundException $e) {
    apiError($e->getMessage(), 404);
} catch (NotCollectionOwnerException $e) {
    apiError($e->getMessage(), 403);
}

$shares = $svc->listSharesForCollection($type, $id);

apiJson(['shares' => array_map(fn($s) => [
    'username'     => $s['username'],
    'display_name' => $s['display_name'],
    'permission'   => $s['permission'],
    'updated_at'   => $s['updated_at'],
], $shares)]);
