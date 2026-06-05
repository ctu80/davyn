<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Davyn\Config\Config;
use Davyn\Database\ConnectionFactory;
use Davyn\User\UserRepository;

// Parse arguments — single colon = required value, works with --flag "value" (space-separated)
$opts = getopt('', ['username:', 'display-name:', 'role:', 'password:']);

$username    = isset($opts['username'])     ? (string) $opts['username']     : null;
$displayName = isset($opts['display-name']) ? (string) $opts['display-name'] : null;
$role        = isset($opts['role'])         ? (string) $opts['role']         : null;
$password    = isset($opts['password'])     ? (string) $opts['password']     : null;

if ($username === null || $username === '' || $displayName === null || $displayName === '' || $role === null || $role === '') {
    echo "Usage: php create-user.php --username <u> --display-name <n> --role <r> [--password <p>]" . PHP_EOL;
    echo "Roles: admin, user, read_only" . PHP_EOL;
    exit(1);
}

if ($password === null) {
    // Interactive prompt when no --password given
    if (function_exists('readline')) {
        $raw = readline('Password: ');
    } else {
        echo 'Password: ';
        $raw = fgets(STDIN);
    }
    $password = ($raw !== false) ? trim((string) $raw) : '';
}

if ($password === '') {
    echo "ERROR: Password must not be empty." . PHP_EOL;
    exit(1);
}

try {
    $config = new Config();
    $pdo    = ConnectionFactory::create($config);
    $repo   = new UserRepository($pdo);
    $user   = $repo->createUser($username, $displayName, $password, $role);

    echo "Created user: {$user->username}" . PHP_EOL;
    echo "Role        : {$user->role}" . PHP_EOL;
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
