<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Davyn\Config\Config;
use Davyn\Database\ConnectionFactory;
use Davyn\Database\MigrationRunner;

$config = new Config();

echo "DB driver : " . $config->dbDriver() . PHP_EOL;
echo "DB path   : " . $config->dbPath() . PHP_EOL;
echo PHP_EOL;

try {
    $pdo = ConnectionFactory::create($config);
    $runner = new MigrationRunner($pdo, __DIR__ . '/../migrations');
    $applied = $runner->run();

    if (empty($applied)) {
        echo "No new migrations." . PHP_EOL;
    } else {
        foreach ($applied as $version) {
            echo "Applied: {$version}" . PHP_EOL;
        }
    }

    echo PHP_EOL . "Migrations complete." . PHP_EOL;
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
