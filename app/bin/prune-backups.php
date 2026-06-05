<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Davyn\Backup\BackupService;
use Davyn\Config\Config;

try {
    $config  = new Config();
    $service = new BackupService();

    $retentionDays = $config->backupRetentionDays();
    $minKeep       = $config->backupMinKeep();

    echo "Pruning backups (retention: {$retentionDays} days, min-keep: {$minKeep})..." . PHP_EOL;

    $result = $service->prune($config->dbPath(), $retentionDays, $minKeep);

    if (empty($result['deleted'])) {
        echo "Nothing to prune." . PHP_EOL;
    } else {
        echo "Deleted:" . PHP_EOL;
        foreach ($result['deleted'] as $name) {
            echo "  - {$name}" . PHP_EOL;
        }
    }

    echo "Kept   : {$result['kept']}" . PHP_EOL;
    echo "Deleted: " . count($result['deleted']) . PHP_EOL;
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
