<?php
declare(strict_types=1);

require_once __DIR__ . '/../_auth.php';
require_once __DIR__ . '/../_collection_resolver.php';

use Davyn\ImportExport\ExportService;

apiMethodGuard('GET');
['pdo' => $pdo, 'user' => $user] = apiUserGuard();

$abUri  = isset($_GET['ab'])  ? trim((string) $_GET['ab'])  : '';
$objUri = isset($_GET['uri']) ? trim((string) $_GET['uri']) : '';

if ($abUri === '') {
    apiError('ab is required', 400);
}

// Accept own or shared-* address books (read access is enough to export).
$resolved = resolveAccessibleAddressBook($pdo, (int) $user->id, $abUri);
if ($resolved === null) {
    apiError("Address book '$abUri' not found or not accessible", 403);
}
$addressBookId = (int) $resolved['ab']['id'];

$service = new ExportService();

if ($objUri !== '') {
    $vcard = $service->exportAddressBookObject($addressBookId, $objUri, $pdo);
    if ($vcard === null) {
        apiError('Contact not found', 404);
    }
    $base = $objUri;
} else {
    $vcard = $service->exportAddressBook($addressBookId, $pdo);
    $base  = $abUri;
}

$name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $base);
$name = preg_replace('/\.vcf$/i', '', (string) $name) . '.vcf';

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('Content-Type: text/vcard; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Content-Length: ' . strlen($vcard));
echo $vcard;
