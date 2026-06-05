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
    echo "Usage: php create-default-collections.php --username <username>" . PHP_EOL;
    exit(1);
}

try {
    $config   = new Config();
    $pdo      = ConnectionFactory::create($config);
    $users    = new UserRepository($pdo);
    $calendars = new CalendarRepository($pdo);
    $addressbooks = new AddressBookRepository($pdo);

    $user = $users->findByUsername($username);
    if ($user === null) {
        echo "ERROR: User '{$username}' not found." . PHP_EOL;
        exit(1);
    }

    $calsBefore = count($calendars->listCalendarsForUser($user->id));
    $calendars->createDefaultCalendarForUser($user->id);
    $calsAfter  = count($calendars->listCalendarsForUser($user->id));
    echo ($calsAfter > $calsBefore ? 'Created' : 'Exists ') . " default calendar for {$username}" . PHP_EOL;

    $absBefore = count($addressbooks->listAddressBooksForUser($user->id));
    $addressbooks->createDefaultAddressBookForUser($user->id);
    $absAfter  = count($addressbooks->listAddressBooksForUser($user->id));
    echo ($absAfter > $absBefore ? 'Created' : 'Exists ') . " default addressbook for {$username}" . PHP_EOL;
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
