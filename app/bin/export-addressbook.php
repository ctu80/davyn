<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Davyn\Config\Config;
use Davyn\Database\ConnectionFactory;
use Davyn\ImportExport\ExportService;

$opts        = getopt('', ['username:', 'addressbook:', 'output:']);
$username    = isset($opts['username'])    ? (string) $opts['username']    : null;
$addressbook = isset($opts['addressbook']) ? (string) $opts['addressbook'] : null;
$output      = isset($opts['output'])      ? (string) $opts['output']      : null;

if ($username === null || $addressbook === null || $output === null) {
    echo "Usage: php export-addressbook.php --username <user> --addressbook <uri> --output <path.vcf>" . PHP_EOL;
    exit(1);
}

try {
    $config  = new Config();
    $pdo     = ConnectionFactory::create($config);
    $service = new ExportService();

    $service->ensureDir(dirname($output));

    $addressBookId = $service->resolveAddressBookId($username, $addressbook, $pdo);
    $vcf           = $service->exportAddressBook($addressBookId, $pdo);

    file_put_contents($output, $vcf);
    $size = strlen($vcf);

    echo "Exported addressbook '{$addressbook}' for user '{$username}'." . PHP_EOL;
    echo "Output: {$output}" . PHP_EOL;
    echo "Size: {$size} bytes" . PHP_EOL;
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
