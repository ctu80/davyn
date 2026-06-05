<?php
declare(strict_types=1);

require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_collection_resolver.php';

use Davyn\Dav\CalendarObjectRepository;
use Davyn\Dav\IcsPatcher;

apiMethodGuard('GET');
['pdo' => $pdo, 'user' => $user] = apiUserGuard();

$calUri = trim((string) ($_GET['cal'] ?? ''));
if ($calUri === '') apiError('cal parameter is required', 400);

$resolved = resolveAccessibleCalendar($pdo, (int) $user->id, $calUri);
if ($resolved === null) apiError('Calendar not found', 404);
$cal = $resolved['cal'];

$repo    = new CalendarObjectRepository($pdo);
$objects = $repo->listObjects((int) $cal['id']);

$events = array_map(function ($obj) {
    // Parse losslessly with VObject so all the rich fields the form needs are
    // available without a second round trip. Only VEVENT objects are surfaced.
    $f = IcsPatcher::read($obj['ics']);
    return [
        'uri'                  => $obj['uri'],
        'etag'                 => $obj['etag'],
        'summary'              => $f['summary'],
        'all_day'              => $f['all_day'],
        'dtstart'              => $f['dtstart'],
        'dtend'                => $f['dtend'],
        'location'             => $f['location'],
        'description'          => $f['description'],
        'status'               => $f['status'],
        'categories'           => $f['categories'],
        'recurring'            => $f['recurring'],
        'recurrence'           => $f['recurrence'],
        'rrule_raw'            => $f['rrule_raw'],
        'recurrence_supported' => $f['recurrence_supported'],
        'reminders'            => $f['reminders'],
        'reminders_supported'  => $f['reminders_supported'],
    ];
}, array_filter($objects, fn($o) => ($o['component_type'] ?? 'VEVENT') === 'VEVENT' || str_contains($o['ics'], 'BEGIN:VEVENT')));

usort($events, fn($a, $b) => strcmp($a['dtstart'], $b['dtstart']));

apiJson(['events' => array_values($events)]);
