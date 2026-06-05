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
    echo "Usage: php list-objects.php --username <username>" . PHP_EOL;
    exit(1);
}

try {
    $config       = new Config();
    $pdo          = ConnectionFactory::create($config);
    $users        = new UserRepository($pdo);
    $calendarRepo = new CalendarRepository($pdo);
    $abRepo       = new AddressBookRepository($pdo);

    $user = $users->findByUsername($username);
    if ($user === null) {
        echo "ERROR: User '{$username}' not found." . PHP_EOL;
        exit(1);
    }

    // ── Calendars ──────────────────────────────────────────────────────────
    echo "Calendars:" . PHP_EOL;
    $calendars = $calendarRepo->listCalendarsForUser($user->id);
    if (empty($calendars)) {
        echo "  (no calendars)" . PHP_EOL;
    } else {
        foreach ($calendars as $cal) {
            echo "  [{$cal['uri']}]" . PHP_EOL;

            $stmt = $pdo->prepare(
                'SELECT uri, uid, component_type, size
                 FROM calendar_objects
                 WHERE calendar_id = ? AND deleted_at IS NULL
                 ORDER BY id'
            );
            $stmt->execute([(int) $cal['id']]);
            $objects = $stmt->fetchAll();

            if (empty($objects)) {
                echo "    (empty)" . PHP_EOL;
            } else {
                $fmt = "    %-40s %-36s %-10s %s" . PHP_EOL;
                printf($fmt, 'OBJECT URI', 'UID', 'TYPE', 'SIZE');
                echo "    " . str_repeat('-', 96) . PHP_EOL;
                foreach ($objects as $obj) {
                    printf($fmt,
                        $obj['uri'],
                        $obj['uid'] ?? '',
                        $obj['component_type'] ?? '',
                        $obj['size'] . ' B',
                    );
                }
            }
        }
    }

    // ── Addressbooks ───────────────────────────────────────────────────────
    echo PHP_EOL . "Addressbooks:" . PHP_EOL;
    $addressbooks = $abRepo->listAddressBooksForUser($user->id);
    if (empty($addressbooks)) {
        echo "  (no addressbooks)" . PHP_EOL;
    } else {
        foreach ($addressbooks as $ab) {
            echo "  [{$ab['uri']}]" . PHP_EOL;

            $stmt = $pdo->prepare(
                'SELECT uri, uid, size
                 FROM addressbook_objects
                 WHERE addressbook_id = ? AND deleted_at IS NULL
                 ORDER BY id'
            );
            $stmt->execute([(int) $ab['id']]);
            $objects = $stmt->fetchAll();

            if (empty($objects)) {
                echo "    (empty)" . PHP_EOL;
            } else {
                $fmt = "    %-40s %-36s %s" . PHP_EOL;
                printf($fmt, 'OBJECT URI', 'UID', 'SIZE');
                echo "    " . str_repeat('-', 80) . PHP_EOL;
                foreach ($objects as $obj) {
                    printf($fmt,
                        $obj['uri'],
                        $obj['uid'] ?? '',
                        $obj['size'] . ' B',
                    );
                }
            }
        }
    }
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
