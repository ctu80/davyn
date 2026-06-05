<?php
declare(strict_types=1);

require_once __DIR__ . '/../_auth.php';
require_once __DIR__ . '/../_collection_resolver.php';

use Davyn\Audit\ActivityLog;
use Davyn\Dav\AddressBookRepository;
use Davyn\Http\Csrf;

apiMethodGuard('POST');
['pdo' => $pdo, 'user' => $user, 'session' => $session] = apiUserGuard();

$csrf = new Csrf($session);
if (!$csrf->verify($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    apiError('Invalid CSRF token', 403);
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$abUri = trim((string) ($body['uri'] ?? $body['ab'] ?? ''));
if ($abUri === '') apiError('uri is required', 400);

$resolved = resolveAccessibleAddressBook($pdo, (int) $user->id, $abUri);
if ($resolved === null) apiError('Address book not found', 404);
// Only the owner may delete an address book; one shared TO you cannot be removed.
if ($resolved['permission'] !== 'owner') apiError('Only the owner can delete this address book', 403);

$ab = $resolved['ab'];

$repo = new AddressBookRepository($pdo);
$repo->deleteAddressBookById((int) $ab['id']);

(new ActivityLog($pdo))->log(
    (int) $user->id, 'user.addressbook.delete',
    "Deleted address book '{$abUri}'", 'addressbook', (string) $ab['id']
);

apiJson(['ok' => true]);
