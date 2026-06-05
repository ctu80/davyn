<?php
declare(strict_types=1);

require_once __DIR__ . '/../_auth.php';
require_once __DIR__ . '/../_collection_resolver.php';

use Davyn\Audit\ActivityLog;
use Davyn\Dav\CalendarObjectRepository;
use Davyn\Http\Csrf;

apiMethodGuard('POST');
['pdo' => $pdo, 'user' => $user, 'session' => $session] = apiUserGuard();

$csrf = new Csrf($session);
if (!$csrf->verify($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    apiError('Invalid CSRF token', 403);
}

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$calUri = trim((string) ($body['cal'] ?? ''));
$uri    = trim((string) ($body['uri'] ?? ''));

if ($calUri === '') apiError('cal is required', 400);
if ($uri    === '') apiError('uri is required', 400);

$resolved = resolveAccessibleCalendar($pdo, (int) $user->id, $calUri);
if ($resolved === null) apiError('Calendar not found', 404);
if ($resolved['permission'] === 'read_only') apiError('Read-only: write not permitted', 403);
$cal = $resolved['cal'];
if (!empty($cal['generated_type'])) apiError('Read-only: generated calendar', 403);

$repo = new CalendarObjectRepository($pdo);
$obj  = $repo->getObject((int) $cal['id'], $uri);
if ($obj === null) apiError('Event not found', 404);

$repo->deleteObject((int) $cal['id'], $uri);

(new ActivityLog($pdo))->log(
    (int) $user->id, 'user.event.delete',
    "Deleted event '{$uri}' from calendar '{$calUri}'",
    'calendar_object', $uri
);

apiJson(['ok' => true]);
