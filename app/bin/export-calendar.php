<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Davyn\Config\Config;
use Davyn\Database\ConnectionFactory;
use Davyn\ImportExport\ExportService;

$opts     = getopt('', ['username:', 'calendar:', 'output:']);
$username = isset($opts['username']) ? (string) $opts['username'] : null;
$calendar = isset($opts['calendar']) ? (string) $opts['calendar'] : null;
$output   = isset($opts['output'])   ? (string) $opts['output']   : null;

if ($username === null || $calendar === null || $output === null) {
    echo "Usage: php export-calendar.php --username <user> --calendar <uri> --output <path.ics>" . PHP_EOL;
    exit(1);
}

try {
    $config  = new Config();
    $pdo     = ConnectionFactory::create($config);
    $service = new ExportService();

    $service->ensureDir(dirname($output));

    $calendarId = $service->resolveCalendarId($username, $calendar, $pdo);
    $ics        = $service->exportCalendar($calendarId, $pdo);

    file_put_contents($output, $ics);
    $size = strlen($ics);

    echo "Exported calendar '{$calendar}' for user '{$username}'." . PHP_EOL;
    echo "Output: {$output}" . PHP_EOL;
    echo "Size: {$size} bytes" . PHP_EOL;
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
