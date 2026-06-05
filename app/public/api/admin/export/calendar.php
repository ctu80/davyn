<?php
declare(strict_types=1);

require_once __DIR__ . '/../_auth.php';

use Davyn\ImportExport\ExportService;

apiMethodGuard('GET');
['config' => $config, 'pdo' => $pdo] = apiAdminGuard();

$username    = isset($_GET['username']) ? trim((string) $_GET['username']) : '';
$calendarUri = isset($_GET['calendar']) ? trim((string) $_GET['calendar']) : '';

if ($username === '' || $calendarUri === '') {
    apiError('username and calendar parameters are required', 400);
}

try {
    $service    = new ExportService();
    $calendarId = $service->resolveCalendarId($username, $calendarUri, $pdo);
    $ics        = $service->exportCalendar($calendarId, $pdo);

    $filename = preg_replace('/[^A-Za-z0-9._-]+/', '_', "{$username}-{$calendarUri}") . '.ics';
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($ics));
    echo $ics;
} catch (\Throwable $e) {
    apiError($e->getMessage(), 404);
}
