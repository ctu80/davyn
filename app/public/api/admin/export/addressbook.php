<?php
declare(strict_types=1);

require_once __DIR__ . '/../_auth.php';

use Davyn\ImportExport\ExportService;

apiMethodGuard('GET');
['config' => $config, 'pdo' => $pdo] = apiAdminGuard();

$username       = isset($_GET['username'])    ? trim((string) $_GET['username'])    : '';
$addressBookUri = isset($_GET['addressbook']) ? trim((string) $_GET['addressbook']) : '';

if ($username === '' || $addressBookUri === '') {
    apiError('username and addressbook parameters are required', 400);
}

try {
    $service       = new ExportService();
    $addressBookId = $service->resolveAddressBookId($username, $addressBookUri, $pdo);
    $vcf           = $service->exportAddressBook($addressBookId, $pdo);

    $filename = preg_replace('/[^A-Za-z0-9._-]+/', '_', "{$username}-{$addressBookUri}") . '.vcf';
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    header('Content-Type: text/vcard; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($vcf));
    echo $vcf;
} catch (\Throwable $e) {
    apiError($e->getMessage(), 404);
}
