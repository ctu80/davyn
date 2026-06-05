<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Davyn\Auth\AppPasswordRepository;
use Davyn\Config\Config;
use Davyn\Database\ConnectionFactory;

$opts     = getopt('', ['username:', 'name:', 'password:']);
$username = isset($opts['username']) ? (string) $opts['username'] : null;
$name     = isset($opts['name'])     ? (string) $opts['name']     : null;
$password = isset($opts['password']) ? (string) $opts['password'] : null;

if ($username === null || $name === null || $password === null) {
    echo "Usage: php create-app-password.php --username <username> --name <label> --password <password>" . PHP_EOL;
    exit(1);
}

try {
    $config  = new Config();
    $pdo     = ConnectionFactory::create($config);
    $repo    = new AppPasswordRepository($pdo);

    $repo->createForUser($username, $name, $password);
    echo "App password '$name' created for user '$username'." . PHP_EOL;
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
