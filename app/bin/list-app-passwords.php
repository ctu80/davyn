<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Davyn\Auth\AppPasswordRepository;
use Davyn\Config\Config;
use Davyn\Database\ConnectionFactory;

$opts     = getopt('', ['username:']);
$username = isset($opts['username']) ? (string) $opts['username'] : null;

if ($username === null || $username === '') {
    echo "Usage: php list-app-passwords.php --username <username>" . PHP_EOL;
    exit(1);
}

try {
    $config = new Config();
    $pdo    = ConnectionFactory::create($config);
    $repo   = new AppPasswordRepository($pdo);

    $rows = $repo->listForUser($username);

    if (empty($rows)) {
        echo "No app passwords for user '$username'." . PHP_EOL;
        exit(0);
    }

    $fmt = "%-30s %-22s %-22s %s" . PHP_EOL;
    printf($fmt, 'NAME', 'CREATED AT', 'LAST USED', 'STATUS');
    echo str_repeat('-', 90) . PHP_EOL;
    foreach ($rows as $row) {
        $status   = $row['revoked_at'] !== null ? 'revoked' : 'active';
        $lastUsed = $row['last_used_at'] ?? '(never)';
        printf($fmt, $row['name'], $row['created_at'], $lastUsed, $status);
    }
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
