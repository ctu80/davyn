<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Davyn\Config\Config;
use Davyn\Database\ConnectionFactory;
use Davyn\User\UserRepository;

$opts     = getopt('', ['username:']);
$username = isset($opts['username']) ? (string) $opts['username'] : null;

$config = new Config();
$pdo    = ConnectionFactory::create($config);

if ($username) {
    $users = new UserRepository($pdo);
    $user  = $users->findByUsername($username);
    if (!$user) { echo "ERROR: User '$username' not found." . PHP_EOL; exit(1); }

    $stmt = $pdo->prepare(
        'SELECT t.*, u.username FROM api_tokens t JOIN users u ON u.id = t.user_id
         WHERE t.user_id = ? ORDER BY t.id DESC'
    );
    $stmt->execute([(int) $user->id]);
} else {
    $stmt = $pdo->query(
        'SELECT t.*, u.username FROM api_tokens t JOIN users u ON u.id = t.user_id ORDER BY t.id DESC'
    );
}

$tokens = $stmt->fetchAll();
if (!$tokens) {
    echo "No API tokens found." . PHP_EOL;
    exit(0);
}

printf("%-4s  %-12s  %-20s  %-30s  %-8s  %s\n", 'ID', 'User', 'Name', 'Scopes', 'Prefix', 'Status');
echo str_repeat('-', 100) . PHP_EOL;
foreach ($tokens as $t) {
    $status = $t['revoked_at'] ? 'revoked' : 'active';
    printf("%-4d  %-12s  %-20s  %-30s  %-8s  %s\n",
        $t['id'], $t['username'], $t['name'], $t['scopes'] ?: '(none)', $t['token_prefix'] . '...', $status
    );
}
