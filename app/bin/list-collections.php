<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Davyn\Config\Config;
use Davyn\Database\ConnectionFactory;
use Davyn\Dav\AddressBookRepository;
use Davyn\Dav\CalendarRepository;
use Davyn\User\UserRepository;

$opts     = getopt('', ['username:']);
$username = isset($opts['username']) ? (string) $opts['username'] : null;

if ($username === null || $username === '') {
    echo "Usage: php list-collections.php --username <username>" . PHP_EOL;
    exit(1);
}

try {
    $config       = new Config();
    $pdo          = ConnectionFactory::create($config);
    $users        = new UserRepository($pdo);
    $calendars    = new CalendarRepository($pdo);
    $addressbooks = new AddressBookRepository($pdo);

    $user = $users->findByUsername($username);
    if ($user === null) {
        echo "ERROR: User '{$username}' not found." . PHP_EOL;
        exit(1);
    }

    $fmt = "  %-20s %-24s %s" . PHP_EOL;

    echo "Calendars:" . PHP_EOL;
    $cals = $calendars->listCalendarsForUser($user->id);
    if (empty($cals)) {
        echo "  (none)" . PHP_EOL;
    } else {
        printf($fmt, 'URI', 'DISPLAY NAME', 'SYNC TOKEN');
        echo '  ' . str_repeat('-', 52) . PHP_EOL;
        foreach ($cals as $cal) {
            printf($fmt, $cal['uri'], $cal['display_name'], $cal['sync_token']);
        }
    }

    echo PHP_EOL . "Addressbooks:" . PHP_EOL;
    $abs = $addressbooks->listAddressBooksForUser($user->id);
    if (empty($abs)) {
        echo "  (none)" . PHP_EOL;
    } else {
        printf($fmt, 'URI', 'DISPLAY NAME', 'SYNC TOKEN');
        echo '  ' . str_repeat('-', 52) . PHP_EOL;
        foreach ($abs as $ab) {
            printf($fmt, $ab['uri'], $ab['display_name'], $ab['sync_token']);
        }
    }
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
