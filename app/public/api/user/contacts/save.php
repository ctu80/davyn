<?php
declare(strict_types=1);

require_once __DIR__ . '/../_auth.php';
require_once __DIR__ . '/../_collection_resolver.php';

use Davyn\Audit\ActivityLog;
use Davyn\Birthday\BirthdayTrigger;
use Davyn\Dav\AddressBookObjectRepository;
use Davyn\Dav\VCardPatcher;
use Davyn\Http\Csrf;

apiMethodGuard('POST');
['pdo' => $pdo, 'user' => $user, 'session' => $session] = apiUserGuard();

$csrf = new Csrf($session);
if (!$csrf->verify($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    apiError('Invalid CSRF token', 403);
}

$body         = json_decode(file_get_contents('php://input'), true) ?? [];
$abUri        = trim((string) ($body['ab']  ?? ''));
$fn           = trim((string) ($body['fn']  ?? ''));
$first        = trim((string) ($body['first_name'] ?? ''));
$last         = trim((string) ($body['last_name']  ?? ''));
$uri          = trim((string) ($body['uri'] ?? ''));
$expectedEtag = isset($body['expected_etag']) ? (string) $body['expected_etag'] : null;

if ($abUri === '') apiError('ab is required', 400);
// A contact needs at least a full name or a first/last name.
if ($fn === '' && $first === '' && $last === '') apiError('A name is required', 400);

$resolved = resolveAccessibleAddressBook($pdo, (int) $user->id, $abUri);
if ($resolved === null) apiError('Addressbook not found', 404);
if ($resolved['permission'] === 'read_only') apiError('Read-only: write not permitted', 403);
$ab = $resolved['ab'];

if ($uri === '') {
    $uid = strtoupper(bin2hex(random_bytes(8)));
    $uri = $uid . '.vcf';
} else {
    $uid = preg_replace('/\.vcf$/i', '', $uri);
}

$repo = new AddressBookObjectRepository($pdo);

// Conflict detection (normalize etag: strip surrounding quotes for comparison)
$existing = $repo->getObject((int) $ab['id'], $uri);
if ($expectedEtag !== null && $existing !== null && trim($existing['etag'], '"') !== trim($expectedEtag, '"')) {
    apiJson(['error' => 'Conflict: object changed elsewhere, reload first', 'current_etag' => $existing['etag']], 409);
}

$isCreate = $existing === null;

// Lossless patch: preserve PHOTO / IMPP / X-* / unknown properties on edit.
$fields = [
    'fn'         => $fn,
    'first_name' => $first,
    'last_name'  => $last,
    'nickname'   => (string) ($body['nickname'] ?? ''),
    'org'        => (string) ($body['org'] ?? ''),
    'title'      => (string) ($body['title'] ?? ''),
    'note'       => (string) ($body['note'] ?? ''),
    'bday'       => (string) ($body['bday'] ?? ''),
    'url'        => (string) ($body['url'] ?? ''),
    'categories' => array_values((array) ($body['categories'] ?? [])),
    'emails'     => array_values((array) ($body['emails'] ?? [])),
    'phones'     => array_values((array) ($body['phones'] ?? [])),
    'addresses'  => array_values((array) ($body['addresses'] ?? [])),
];

$vcard = VCardPatcher::patch($isCreate ? null : ($existing['vcard'] ?? null), $uid, $fields);
$etag  = $repo->putObject((int) $ab['id'], $uri, $vcard);

// Keep the generated birthday calendar in sync (after the contact has been written).
BirthdayTrigger::onContactSaved($pdo, (int) $ab['id'], $uri, $vcard);

$label = $fn !== '' ? $fn : trim($first . ' ' . $last);
(new ActivityLog($pdo))->log(
    (int) $user->id, $isCreate ? 'user.contact.create' : 'user.contact.update',
    ($isCreate ? 'Created' : 'Updated') . " contact '{$label}' in addressbook '{$abUri}'",
    'addressbook_object', $uri
);

apiJson(['ok' => true, 'uri' => $uri, 'etag' => $etag]);
