<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Davyn\Auth\AppPasswordRepository;
use Davyn\Config\Config;
use Davyn\Database\ConnectionFactory;

$opts     = getopt('', ['username:', 'name:']);
$username = isset($opts['username']) ? (string) $opts['username'] : null;
$name     = isset($opts['name'])     ? (string) $opts['name']     : null;

if ($username === null || $name === null) {
    echo "Usage: php revoke-app-password.php --username <username> --name <label>" . PHP_EOL;
    exit(1);
}

try {
    $config = new Config();
    $pdo    = ConnectionFactory::create($config);
    $repo   = new AppPasswordRepository($pdo);

    $repo->revokeForUser($username, $name);
    echo "App password '$name' revoked for user '$username'." . PHP_EOL;
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
