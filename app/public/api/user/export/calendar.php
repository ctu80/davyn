<?php
declare(strict_types=1);

require_once __DIR__ . '/../_auth.php';
require_once __DIR__ . '/../_collection_resolver.php';

use Davyn\ImportExport\ExportService;

apiMethodGuard('GET');
['pdo' => $pdo, 'user' => $user] = apiUserGuard();

$calUri = isset($_GET['cal']) ? trim((string) $_GET['cal']) : '';
$objUri = isset($_GET['uri']) ? trim((string) $_GET['uri']) : '';

if ($calUri === '') {
    apiError('cal is required', 400);
}

// Accept own or shared-* calendars (read access is enough to export).
$resolved = resolveAccessibleCalendar($pdo, (int) $user->id, $calUri);
if ($resolved === null) {
    apiError("Calendar '$calUri' not found or not accessible", 403);
}
$calendarId = (int) $resolved['cal']['id'];

$service = new ExportService();

if ($objUri !== '') {
    $ics = $service->exportCalendarObject($calendarId, $objUri, $pdo);
    if ($ics === null) {
        apiError('Event not found', 404);
    }
    $base = $objUri;
} else {
    $ics  = $service->exportCalendar($calendarId, $pdo);
    $base = $calUri;
}

$name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $base);
$name = preg_replace('/\.ics$/i', '', (string) $name) . '.ics';

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Content-Length: ' . strlen($ics));
echo $ics;
