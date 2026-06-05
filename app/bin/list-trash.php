<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Davyn\Config\Config;
use Davyn\Database\ConnectionFactory;
use Davyn\User\UserRepository;

$opts     = getopt('', ['username:']);
$username = trim((string) ($opts['username'] ?? ''));

if ($username === '') {
    fwrite(STDERR, "Usage: php list-trash.php --username <username>\n");
    exit(1);
}

$config = new Config();
$pdo    = ConnectionFactory::create($config);
$users  = new UserRepository($pdo);
$user   = $users->findByUsername($username);

if ($user === null) {
    fwrite(STDERR, "User '$username' not found.\n");
    exit(1);
}

echo "=== Trash for user: {$username} ===" . PHP_EOL;

// Deleted calendar objects
$stmt = $pdo->prepare('
    SELECT co.uri, co.deleted_at, c.uri AS calendar_uri, c.display_name AS calendar_name
    FROM calendar_objects co
    JOIN calendars c ON c.id = co.calendar_id
    WHERE c.user_id = ? AND co.deleted_at IS NOT NULL
    ORDER BY co.deleted_at DESC
');
$stmt->execute([$user->id]);
$calRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo PHP_EOL . "Calendar objects: " . count($calRows) . PHP_EOL;
foreach ($calRows as $row) {
    printf(
        "  %s  [calendar: %s (%s)]  deleted: %s\n",
        $row['uri'],
        $row['calendar_name'],
        $row['calendar_uri'],
        $row['deleted_at']
    );
}

// Deleted addressbook objects
$stmt = $pdo->prepare('
    SELECT ao.uri, ao.deleted_at, ab.uri AS ab_uri, ab.display_name AS ab_name
    FROM addressbook_objects ao
    JOIN addressbooks ab ON ab.id = ao.addressbook_id
    WHERE ab.user_id = ? AND ao.deleted_at IS NOT NULL
    ORDER BY ao.deleted_at DESC
');
$stmt->execute([$user->id]);
$abRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo PHP_EOL . "Addressbook objects: " . count($abRows) . PHP_EOL;
foreach ($abRows as $row) {
    printf(
        "  %s  [addressbook: %s (%s)]  deleted: %s\n",
        $row['uri'],
        $row['ab_name'],
        $row['ab_uri'],
        $row['deleted_at']
    );
}

if (!$calRows && !$abRows) {
    echo "  (trash is empty)" . PHP_EOL;
}

echo PHP_EOL . "Done." . PHP_EOL;
