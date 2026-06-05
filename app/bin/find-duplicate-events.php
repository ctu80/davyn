<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Davyn\Config\Config;
use Davyn\Database\ConnectionFactory;
use Davyn\User\UserRepository;

$opts     = getopt('', ['username:', 'calendar:']);
$username = isset($opts['username']) ? (string) $opts['username'] : null;
$calUri   = isset($opts['calendar']) ? (string) $opts['calendar'] : null;

if (!$username) {
    echo "Usage: php find-duplicate-events.php --username <username> [--calendar <uri>]" . PHP_EOL;
    exit(1);
}

$config = new Config();
$pdo    = ConnectionFactory::create($config);
$users  = new UserRepository($pdo);
$user   = $users->findByUsername($username);

if (!$user) {
    echo "ERROR: User '$username' not found." . PHP_EOL;
    exit(1);
}

// Get calendars for user
$query = 'SELECT id, uri, display_name FROM calendars WHERE user_id = ?';
$params = [(int) $user->id];
if ($calUri) {
    $query   .= ' AND uri = ?';
    $params[] = $calUri;
}
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$calendars = $stmt->fetchAll();

if (!$calendars) {
    echo "No calendars found." . PHP_EOL;
    exit(0);
}

$totalDupes = 0;

foreach ($calendars as $cal) {
    $objects = $pdo->prepare(
        'SELECT uri, ics FROM calendar_objects WHERE calendar_id = ? AND deleted_at IS NULL'
    );
    $objects->execute([(int) $cal['id']]);

    // Group by (summary_lower, dtstart)
    $groups = [];
    foreach ($objects->fetchAll() as $obj) {
        $summary = extractLine($obj['ics'], 'SUMMARY');
        $dtstart = extractLine($obj['ics'], 'DTSTART');
        // Normalize DTSTART (strip params)
        $dtstart = preg_replace('/^[^:]+:/', '', $dtstart);
        $key = strtolower($summary) . '|' . $dtstart;
        if (!$key || $key === '|') continue;
        $groups[$key][] = $obj['uri'];
    }

    $dupes = array_filter($groups, fn($uris) => count($uris) > 1);
    if (!$dupes) {
        echo "[{$cal['uri']}] No duplicates found." . PHP_EOL;
        continue;
    }

    echo "[{$cal['uri']}] Found " . count($dupes) . " duplicate group(s):" . PHP_EOL;
    foreach ($dupes as $key => $uris) {
        [$summary, $dtstart] = explode('|', $key, 2);
        echo "  Summary: $summary  DTSTART: $dtstart" . PHP_EOL;
        foreach ($uris as $uri) {
            echo "    - $uri" . PHP_EOL;
        }
    }
    $totalDupes += count($dupes);
}

echo PHP_EOL . "Total duplicate groups: $totalDupes" . PHP_EOL;
exit($totalDupes > 0 ? 1 : 0);

function extractLine(string $ics, string $prop): string
{
    if (preg_match('/^' . preg_quote($prop, '/') . '[^:\r\n]*:([^\r\n]+)/m', $ics, $m)) {
        return trim($m[1]);
    }
    return '';
}
