<?php
declare(strict_types=1);

require_once __DIR__ . '/../_auth.php';
require_once __DIR__ . '/../_collection_resolver.php';

use Davyn\Audit\ActivityLog;
use Davyn\Birthday\BirthdayTrigger;
use Davyn\Dav\AddressBookObjectRepository;
use Davyn\Http\Csrf;

apiMethodGuard('POST');
['pdo' => $pdo, 'user' => $user, 'session' => $session] = apiUserGuard();

$csrf = new Csrf($session);
if (!$csrf->verify($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    apiError('Invalid CSRF token', 403);
}

$body  = json_decode(file_get_contents('php://input'), true) ?? [];
$abUri = trim((string) ($body['ab']  ?? ''));
$uri   = trim((string) ($body['uri'] ?? ''));

if ($abUri === '') apiError('ab is required', 400);
if ($uri   === '') apiError('uri is required', 400);

$resolved = resolveAccessibleAddressBook($pdo, (int) $user->id, $abUri);
if ($resolved === null) apiError('Addressbook not found', 404);
if ($resolved['permission'] === 'read_only') apiError('Read-only: write not permitted', 403);
$ab = $resolved['ab'];

$repo = new AddressBookObjectRepository($pdo);
$obj  = $repo->getObject((int) $ab['id'], $uri);
if ($obj === null) apiError('Contact not found', 404);

$repo->deleteObject((int) $ab['id'], $uri);

// Remove the matching birthday event from the generated calendar.
BirthdayTrigger::onContactDeleted($pdo, (int) $ab['id'], $uri);

(new ActivityLog($pdo))->log(
    (int) $user->id, 'user.contact.delete',
    "Deleted contact '{$uri}' from addressbook '{$abUri}'",
    'addressbook_object', $uri
);

apiJson(['ok' => true]);
