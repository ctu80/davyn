<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Davyn\Config\Config;
use Davyn\Database\ConnectionFactory;
use Davyn\External\ExternalCalendarService;
use Davyn\User\UserRepository;

$opts      = getopt('', ['username:', 'uri:', 'file:']);
$username  = isset($opts['username']) ? (string) $opts['username'] : null;
$uri       = isset($opts['uri'])      ? (string) $opts['uri']      : null;
$localFile = isset($opts['file'])     ? (string) $opts['file']     : null;

if (!$username || !$uri) {
    echo "Usage: php refresh-external-calendar.php --username <user> --uri <cal-uri> [--file /path/to/local.ics]" . PHP_EOL;
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

try {
    $svc    = new ExternalCalendarService($pdo);
    $result = $svc->refresh((int) $user->id, $uri, $localFile);
    echo "External calendar '$uri' refreshed." . PHP_EOL;
    echo "  Calendar ID : " . $result['calendar_id'] . PHP_EOL;
    echo "  Events      : " . $result['imported'] . PHP_EOL;
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
