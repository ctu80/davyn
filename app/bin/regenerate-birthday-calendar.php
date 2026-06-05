<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Davyn\Birthday\BirthdayService;
use Davyn\Config\Config;
use Davyn\Database\ConnectionFactory;
use Davyn\User\UserRepository;

$opts     = getopt('', ['username:']);
$username = isset($opts['username']) ? (string) $opts['username'] : null;

if ($username === null) {
    echo "Usage: php regenerate-birthday-calendar.php --username <username>" . PHP_EOL;
    exit(1);
}

$config = new Config();
$pdo    = ConnectionFactory::create($config);
$users  = new UserRepository($pdo);
$user   = $users->findByUsername($username);

if ($user === null) {
    echo "ERROR: User '$username' not found." . PHP_EOL;
    exit(1);
}

$svc    = new BirthdayService($pdo);
$result = $svc->regenerate((int) $user->id);

echo "Birthday calendar regenerated for user '$username'." . PHP_EOL;
echo "  Calendar ID : " . $result['calendar_id'] . PHP_EOL;
echo "  Events      : " . $result['generated'] . PHP_EOL;
