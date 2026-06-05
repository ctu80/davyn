<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Davyn\Config\Config;
use Davyn\Database\ConnectionFactory;

// Extract token from path: /public/calendar/{token}.ics
$path  = $_SERVER['REQUEST_URI'] ?? '';
$path  = strtok($path, '?'); // strip query string
if (!preg_match('#^/public/calendar/([a-f0-9]{64})\.ics$#', $path, $m)) {
    http_response_code(404);
    echo 'Not found';
    exit;
}
$tokenRaw  = $m[1];
$tokenHash = hash('sha256', $tokenRaw);

$config = new Config();
$pdo    = ConnectionFactory::create($config);

$stmt = $pdo->prepare(
    'SELECT pl.calendar_id, pl.name, c.display_name, c.user_id
     FROM public_calendar_links pl
     JOIN calendars c ON c.id = pl.calendar_id
     WHERE pl.token_hash = ? AND pl.revoked_at IS NULL'
);
$stmt->execute([$tokenHash]);
$link = $stmt->fetch();

if (!$link) {
    http_response_code(404);
    echo 'Not found';
    exit;
}

$calendarId = (int) $link['calendar_id'];

// Fetch active calendar objects
$objects = $pdo->prepare(
    'SELECT ics FROM calendar_objects WHERE calendar_id = ? AND deleted_at IS NULL ORDER BY id'
);
$objects->execute([$calendarId]);
$rows = $objects->fetchAll(\PDO::FETCH_COLUMN);

// Build combined VCALENDAR
$calName = htmlspecialchars($link['display_name'] ?? $link['name'] ?? 'Calendar', ENT_XML1);
$vcal  = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Davyn//Public Export//EN\r\n";
$vcal .= "X-WR-CALNAME:$calName\r\n";
foreach ($rows as $ics) {
    // Extract VEVENT blocks from each object
    if (preg_match_all('/(BEGIN:VEVENT.*?END:VEVENT)/s', $ics, $matches)) {
        foreach ($matches[1] as $block) {
            $vcal .= $block . "\r\n";
        }
    }
}
$vcal .= "END:VCALENDAR\r\n";

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="calendar.ics"');
header('Cache-Control: no-store');
echo $vcal;
