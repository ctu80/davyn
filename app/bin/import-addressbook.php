<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Davyn\Config\Config;
use Davyn\Database\ConnectionFactory;
use Davyn\ImportExport\ImportService;

$opts        = getopt('', ['username:', 'addressbook:', 'file:']);
$username    = isset($opts['username'])    ? (string) $opts['username']    : null;
$addressbook = isset($opts['addressbook']) ? (string) $opts['addressbook'] : null;
$file        = isset($opts['file'])        ? (string) $opts['file']        : null;

if ($username === null || $addressbook === null || $file === null) {
    echo "Usage: php import-addressbook.php --username <user> --addressbook <uri> --file <path.vcf>" . PHP_EOL;
    exit(1);
}

try {
    $config  = new Config();
    $pdo     = ConnectionFactory::create($config);
    $service = new ImportService();

    $result = $service->importAddressBookFile($username, $addressbook, $file, $pdo);

    echo "Import complete for addressbook '{$addressbook}' (user: {$username})." . PHP_EOL;
    echo "  Created  : {$result['created']}" . PHP_EOL;
    echo "  Updated  : {$result['updated']}" . PHP_EOL;
    echo "  Skipped  : {$result['skipped']}" . PHP_EOL;
    if (!empty($result['errors'])) {
        echo "  Errors   :" . PHP_EOL;
        foreach ($result['errors'] as $err) {
            echo "    - {$err}" . PHP_EOL;
        }
    }
    if (!empty($result['uris'])) {
        echo "  Objects  :" . PHP_EOL;
        foreach ($result['uris'] as $uri) {
            echo "    - {$uri}" . PHP_EOL;
        }
    }
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
