<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Davyn\Config\Config;
use Davyn\Database\ConnectionFactory;
use Davyn\User\UserRepository;

$opts     = getopt('', ['username:', 'password:']);
$username = isset($opts['username']) ? (string) $opts['username'] : null;
$password = isset($opts['password']) ? (string) $opts['password'] : null;

if ($username === null || $username === '' || $password === null) {
    echo "Usage: php change-password.php --username <username> --password <new-password>" . PHP_EOL;
    exit(1);
}

try {
    $config = new Config();
    $pdo    = ConnectionFactory::create($config);
    $users  = new UserRepository($pdo);

    $users->changePassword($username, $password);
    echo "Password updated for user '$username'." . PHP_EOL;
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
