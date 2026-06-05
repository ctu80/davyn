<?php
declare(strict_types=1);

require_once __DIR__ . '/../_auth.php';
require_once __DIR__ . '/../_collection_resolver.php';

use Davyn\Audit\ActivityLog;
use Davyn\Dav\CalendarObjectRepository;
use Davyn\Dav\IcsPatcher;
use Davyn\Http\Csrf;

apiMethodGuard('POST');
['pdo' => $pdo, 'user' => $user, 'session' => $session] = apiUserGuard();

$csrf = new Csrf($session);
if (!$csrf->verify($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    apiError('Invalid CSRF token', 403);
}

$body         = json_decode(file_get_contents('php://input'), true) ?? [];
$calUri       = trim((string) ($body['cal']     ?? ''));
$fromCalUri   = trim((string) ($body['from_cal'] ?? ''));
$summary      = trim((string) ($body['summary'] ?? ''));
$dtstart      = trim((string) ($body['dtstart'] ?? ''));
$dtend        = trim((string) ($body['dtend']   ?? ''));
$allDay       = (bool) ($body['all_day'] ?? false);
$uri          = trim((string) ($body['uri']     ?? ''));
$expectedEtag = isset($body['expected_etag']) ? (string) $body['expected_etag'] : null;

if ($calUri  === '') apiError('cal is required', 400);
if ($summary === '') apiError('summary is required', 400);
if ($dtstart === '') apiError('dtstart is required', 400);
if ($dtend   === '') apiError('dtend is required', 400);

$resolved = resolveAccessibleCalendar($pdo, (int) $user->id, $calUri);
if ($resolved === null) apiError('Calendar not found', 404);
if ($resolved['permission'] === 'read_only') apiError('Read-only: write not permitted', 403);
$cal = $resolved['cal'];
if (!empty($cal['generated_type'])) apiError('Read-only: generated calendar', 403);

if ($uri === '') {
    $uid = strtoupper(bin2hex(random_bytes(8)));
    $uri = $uid . '.ics';
} else {
    $uid = preg_replace('/\.ics$/i', '', $uri);
}

$repo = new CalendarObjectRepository($pdo);

// A move = editing an existing object while choosing a different target calendar.
// The lossless base ICS and the conflict check come from the SOURCE calendar; the
// object is written into the target and then removed from the source.
$isMove      = $uri !== '' && $fromCalUri !== '' && $fromCalUri !== $calUri;
$sourceCalId = (int) $cal['id'];
if ($isMove) {
    $srcResolved = resolveAccessibleCalendar($pdo, (int) $user->id, $fromCalUri);
    if ($srcResolved === null) apiError('Source calendar not found', 404);
    if ($srcResolved['permission'] === 'read_only') apiError('Read-only: source not writable', 403);
    if (!empty($srcResolved['cal']['generated_type'])) apiError('Read-only: generated calendar', 403);
    $sourceCalId = (int) $srcResolved['cal']['id'];
}

// Conflict detection (normalize etag: strip surrounding quotes for comparison).
// For a move the existing object lives in the source calendar.
$existing = $repo->getObject($sourceCalId, $uri);
if ($expectedEtag !== null && $existing !== null && trim($existing['etag'], '"') !== trim($expectedEtag, '"')) {
    apiJson(['error' => 'Conflict: object changed elsewhere, reload first', 'current_etag' => $existing['etag']], 409);
}

$isCreate = $repo->getObject((int) $cal['id'], $uri) === null;

// Lossless patch: load the original raw ICS when editing and only touch the
// fields the WebUI manages, preserving VTIMEZONE / ATTENDEE / X-* / unknown
// recurrence and alarm data.
$fields = [
    'summary'          => $summary,
    'all_day'          => $allDay,
    'dtstart'          => $dtstart,
    'dtend'            => $dtend,
    'location'         => (string) ($body['location'] ?? ''),
    'description'      => (string) ($body['description'] ?? ''),
    'status'           => (string) ($body['status'] ?? ''),
    'categories'       => array_values((array) ($body['categories'] ?? [])),
    'patch_recurrence' => (bool) ($body['patch_recurrence'] ?? false),
    'recurrence'       => (array) ($body['recurrence'] ?? []),
    'patch_reminders'  => (bool) ($body['patch_reminders'] ?? false),
    'reminders'        => array_values((array) ($body['reminders'] ?? [])),
];

// Patch from the source object's raw ICS so a move preserves unmanaged data.
$ics  = IcsPatcher::patch($existing['ics'] ?? null, $uid, $fields);
$etag = $repo->putObject((int) $cal['id'], $uri, $ics);

// Complete the move: remove the original from the source calendar.
if ($isMove && $existing !== null) {
    $repo->deleteObject($sourceCalId, $uri);
}

$action = $isMove ? 'user.event.move' : ($isCreate ? 'user.event.create' : 'user.event.update');
$verb   = $isMove ? "Moved event '{$summary}' to calendar '{$calUri}'"
                  : (($isCreate ? 'Created' : 'Updated') . " event '{$summary}' in calendar '{$calUri}'");
(new ActivityLog($pdo))->log((int) $user->id, $action, $verb, 'calendar_object', $uri);

apiJson(['ok' => true, 'uri' => $uri, 'etag' => $etag]);
