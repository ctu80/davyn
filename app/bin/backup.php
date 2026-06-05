<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Davyn\Backup\BackupService;
use Davyn\Config\Config;
use Davyn\Database\ConnectionFactory;

try {
    $config  = new Config();
    $pdo     = ConnectionFactory::create($config);
    $service = new BackupService();

    $backupFile = $service->create($pdo, $config->dbPath());
    $size       = filesize($backupFile);

    echo "Backup created: $backupFile" . PHP_EOL;
    echo "Size: " . $service->formatSize($size) . " ($size bytes)" . PHP_EOL;
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
