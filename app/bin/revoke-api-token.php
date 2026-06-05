<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Davyn\Config\Config;
use Davyn\Database\ConnectionFactory;

$opts = getopt('', ['id:']);
$id   = isset($opts['id']) ? (int) $opts['id'] : 0;

if (!$id) {
    echo "Usage: php revoke-api-token.php --id <token-id>" . PHP_EOL;
    echo "Run list-api-tokens.php to see token IDs." . PHP_EOL;
    exit(1);
}

$config = new Config();
$pdo    = ConnectionFactory::create($config);

$stmt = $pdo->prepare('SELECT id, name, revoked_at FROM api_tokens WHERE id = ?');
$stmt->execute([$id]);
$token = $stmt->fetch();

if (!$token) {
    echo "ERROR: Token ID $id not found." . PHP_EOL;
    exit(1);
}
if ($token['revoked_at']) {
    echo "Token ID $id ('{$token['name']}') is already revoked." . PHP_EOL;
    exit(0);
}

$pdo->prepare('UPDATE api_tokens SET revoked_at = ? WHERE id = ?')
    ->execute([gmdate('Y-m-d\TH:i:s\Z'), $id]);

echo "Token ID $id ('{$token['name']}') revoked." . PHP_EOL;
