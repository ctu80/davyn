<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Davyn\Config\Config;
use Davyn\Database\ConnectionFactory;
use Davyn\User\UserRepository;

try {
    $config = new Config();
    $pdo    = ConnectionFactory::create($config);
    $repo   = new UserRepository($pdo);
    $users  = $repo->listUsers();

    if (empty($users)) {
        echo "No users found." . PHP_EOL;
        exit(0);
    }

    $fmt = "%-20s %-24s %-10s %-6s %s" . PHP_EOL;
    printf($fmt, 'USERNAME', 'DISPLAY NAME', 'ROLE', 'ACTIVE', 'CREATED AT');
    echo str_repeat('-', 80) . PHP_EOL;

    foreach ($users as $user) {
        printf(
            $fmt,
            $user->username,
            $user->displayName,
            $user->role,
            $user->isActive ? 'yes' : 'no',
            $user->createdAt,
        );
    }
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
