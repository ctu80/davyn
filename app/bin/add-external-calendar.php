<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Davyn\Config\Config;
use Davyn\Database\ConnectionFactory;
use Davyn\External\ExternalCalendarService;
use Davyn\User\UserRepository;

$opts     = getopt('', ['username:', 'uri:', 'name:', 'url:']);
$username = isset($opts['username']) ? (string) $opts['username'] : null;
$uri      = isset($opts['uri'])      ? (string) $opts['uri']      : null;
$name     = isset($opts['name'])     ? (string) $opts['name']     : null;
$url      = isset($opts['url'])      ? (string) $opts['url']      : null;

if (!$username || !$uri || !$name || !$url) {
    echo "Usage: php add-external-calendar.php --username <user> --uri <cal-uri> --name \"Display Name\" --url \"https://...\"" . PHP_EOL;
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

$svc = new ExternalCalendarService($pdo);
$id  = $svc->add((int) $user->id, $uri, $name, $url);

echo "External calendar added." . PHP_EOL;
echo "  ID  : $id" . PHP_EOL;
echo "  URI : $uri" . PHP_EOL;
echo "  URL : $url" . PHP_EOL;
echo "Run refresh-external-calendar.php to fetch events." . PHP_EOL;
