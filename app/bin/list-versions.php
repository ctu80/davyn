<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Davyn\Config\Config;
use Davyn\Database\ConnectionFactory;
use Davyn\Version\ObjectVersionRepository;

$opts = getopt('', ['type:', 'uri:', 'collection-id:']);
$type         = isset($opts['type'])          ? (string) $opts['type']          : null;
$uri          = isset($opts['uri'])           ? (string) $opts['uri']           : null;
$collectionId = isset($opts['collection-id']) ? (int)    $opts['collection-id'] : null;

if ($type === null || $uri === null || $collectionId === null) {
    echo "Usage: php list-versions.php --type calendar|addressbook --collection-id <id> --uri <object-uri>" . PHP_EOL;
    exit(1);
}

if (!in_array($type, ['calendar', 'addressbook'], true)) {
    echo "ERROR: type must be 'calendar' or 'addressbook'" . PHP_EOL;
    exit(1);
}

try {
    $config = new Config();
    $pdo    = ConnectionFactory::create($config);
    $repo   = new ObjectVersionRepository($pdo);

    $rows = $repo->listByUri($type, $collectionId, $uri);

    if (empty($rows)) {
        echo "No versions found for $type $uri in collection $collectionId." . PHP_EOL;
        exit(0);
    }

    printf("%-5s  %-30s  %-10s  %s" . PHP_EOL, 'ID', 'Created at', 'Reason', 'ETag');
    echo str_repeat('-', 80) . PHP_EOL;
    foreach ($rows as $r) {
        printf("%-5d  %-30s  %-10s  %s" . PHP_EOL,
            $r['id'],
            $r['version_created_at'],
            $r['reason'] ?? '—',
            $r['etag'],
        );
    }
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
