<?php
declare(strict_types=1);

require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_collection_resolver.php';

use Davyn\Dav\AddressBookObjectRepository;
use Davyn\Dav\VCardPatcher;

apiMethodGuard('GET');
['pdo' => $pdo, 'user' => $user] = apiUserGuard();

$abUri = trim((string) ($_GET['ab'] ?? ''));
if ($abUri === '') apiError('ab parameter is required', 400);

$resolved = resolveAccessibleAddressBook($pdo, (int) $user->id, $abUri);
if ($resolved === null) apiError('Addressbook not found', 404);
$ab = $resolved['ab'];

$repo    = new AddressBookObjectRepository($pdo);
$objects = $repo->listObjects((int) $ab['id']);

$contacts = array_map(function ($obj) {
    // Parse losslessly with VObject so the edit form has every supported field.
    $f = VCardPatcher::read($obj['vcard']);
    return [
        'uri'        => $obj['uri'],
        'etag'       => $obj['etag'],
        'fn'         => $f['fn'],
        'first_name' => $f['first_name'],
        'last_name'  => $f['last_name'],
        'nickname'   => $f['nickname'],
        'org'        => $f['org'],
        'title'      => $f['title'],
        'note'       => $f['note'],
        'bday'       => $f['bday'],
        'url'        => $f['url'],
        'categories' => $f['categories'],
        'emails'     => $f['emails'],
        'phones'     => $f['phones'],
        'addresses'  => $f['addresses'],
        'has_photo'  => $f['has_photo'],
        // Flattened primaries kept for compact cards / search.
        'email'      => $f['email'],
        'tel'        => $f['tel'],
    ];
}, $objects);

usort($contacts, fn($a, $b) => strcasecmp($a['fn'], $b['fn']));

apiJson(['contacts' => array_values($contacts)]);
