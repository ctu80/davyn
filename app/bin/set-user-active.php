<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Davyn\Config\Config;
use Davyn\Database\ConnectionFactory;
use Davyn\User\UserRepository;

$opts     = getopt('', ['username:', 'active:']);
$username = isset($opts['username']) ? (string) $opts['username'] : null;
$activeRaw = isset($opts['active']) ? (string) $opts['active'] : null;

if ($username === null || $username === '' || $activeRaw === null) {
    echo "Usage: php set-user-active.php --username <username> --active 1|0" . PHP_EOL;
    exit(1);
}

if ($activeRaw !== '0' && $activeRaw !== '1') {
    echo "ERROR: --active must be 0 or 1." . PHP_EOL;
    exit(1);
}

try {
    $config = new Config();
    $pdo    = ConnectionFactory::create($config);
    $users  = new UserRepository($pdo);

    $active = $activeRaw === '1';
    $users->setActive($username, $active);
    echo "User '$username' is now " . ($active ? 'active' : 'inactive') . "." . PHP_EOL;
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
