<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Davyn\Config\Config;
use Davyn\Database\ConnectionFactory;
use Davyn\Holiday\HolidayService;
use Davyn\User\UserRepository;

$opts     = getopt('', ['username:', 'country:', 'state:', 'year:']);
$username = isset($opts['username']) ? (string) $opts['username'] : null;
$country  = isset($opts['country'])  ? (string) $opts['country']  : 'DE';
$state    = isset($opts['state'])    ? (string) $opts['state']    : null;
$year     = isset($opts['year'])     ? (int)    $opts['year']     : (int) date('Y');

if ($username === null) {
    echo "Usage: php generate-holiday-calendar.php --username <username> [--country DE] [--state BW] [--year YYYY]" . PHP_EOL;
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
    $svc    = new HolidayService($pdo);
    $result = $svc->generate((int) $user->id, $country, $state, $year);

    echo "Holiday calendar generated for user '$username'." . PHP_EOL;
    echo "  Country     : " . strtoupper($country) . ($state ? '/' . strtoupper($state) : '') . PHP_EOL;
    echo "  Year(s)     : $year + " . ($year + 1) . PHP_EOL;
    echo "  Calendar URI: " . $result['calendar_uri'] . PHP_EOL;
    echo "  Calendar ID : " . $result['calendar_id'] . PHP_EOL;
    echo "  Events      : " . $result['generated'] . PHP_EOL;
} catch (\InvalidArgumentException $e) {
    echo "ERROR: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
