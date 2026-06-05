<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Davyn\Config\Config;
use Davyn\Database\ConnectionFactory;
use Davyn\User\UserRepository;

$opts     = getopt('', ['username:', 'name:', 'scopes:']);
$username = isset($opts['username']) ? (string) $opts['username'] : null;
$name     = isset($opts['name'])     ? (string) $opts['name']     : null;
$scopes   = isset($opts['scopes'])   ? (string) $opts['scopes']   : '';

if (!$username || !$name) {
    echo "Usage: php create-api-token.php --username <user> --name \"automation\" [--scopes \"read:status,read:collections\"]" . PHP_EOL;
    echo "Available scopes: read:status, read:collections" . PHP_EOL;
    exit(1);
}

$validScopes = ['read:status', 'read:collections'];
if ($scopes) {
    foreach (explode(',', $scopes) as $s) {
        if (!in_array(trim($s), $validScopes, true)) {
            echo "ERROR: Unknown scope '" . trim($s) . "'. Valid: " . implode(', ', $validScopes) . PHP_EOL;
            exit(1);
        }
    }
}

$config = new Config();
$pdo    = ConnectionFactory::create($config);
$users  = new UserRepository($pdo);
$user   = $users->findByUsername($username);

if (!$user) {
    echo "ERROR: User '$username' not found." . PHP_EOL;
    exit(1);
}

$tokenRaw  = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $tokenRaw);
$prefix    = substr($tokenRaw, 0, 8);
$now       = gmdate('Y-m-d\TH:i:s\Z');

$pdo->prepare(
    'INSERT INTO api_tokens (user_id, name, token_hash, token_prefix, scopes, created_at)
     VALUES (?, ?, ?, ?, ?, ?)'
)->execute([(int) $user->id, $name, $tokenHash, $prefix, $scopes, $now]);

$id = $pdo->lastInsertId();
echo "API token created." . PHP_EOL;
echo "  ID     : $id" . PHP_EOL;
echo "  Name   : $name" . PHP_EOL;
echo "  Prefix : $prefix..." . PHP_EOL;
echo "  Scopes : " . ($scopes ?: '(none)') . PHP_EOL;
echo "" . PHP_EOL;
echo "Token (copy now — shown only once):" . PHP_EOL;
echo "  $tokenRaw" . PHP_EOL;
