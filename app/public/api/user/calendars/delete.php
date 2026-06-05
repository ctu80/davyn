<?php
declare(strict_types=1);

require_once __DIR__ . '/../_auth.php';
require_once __DIR__ . '/../_collection_resolver.php';

use Davyn\Audit\ActivityLog;
use Davyn\Dav\CalendarRepository;
use Davyn\Http\Csrf;

apiMethodGuard('POST');
['pdo' => $pdo, 'user' => $user, 'session' => $session] = apiUserGuard();

$csrf = new Csrf($session);
if (!$csrf->verify($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    apiError('Invalid CSRF token', 403);
}

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$calUri = trim((string) ($body['uri'] ?? $body['cal'] ?? ''));
if ($calUri === '') apiError('uri is required', 400);

$resolved = resolveAccessibleCalendar($pdo, (int) $user->id, $calUri);
if ($resolved === null) apiError('Calendar not found', 404);
// Only the owner may delete a calendar; a calendar shared TO you cannot be removed.
if ($resolved['permission'] !== 'owner') apiError('Only the owner can delete this calendar', 403);

$cal = $resolved['cal'];
// Generated calendars (holidays, birthdays) are read-only; they are managed via their
// own settings (disable), not deleted like a normal calendar.
if (!empty($cal['generated_type'])) {
    apiError('This is a generated, read-only calendar — disable it from its settings instead of deleting it.', 403);
}

$repo = new CalendarRepository($pdo);
$repo->deleteCalendarById((int) $cal['id']);

(new ActivityLog($pdo))->log(
    (int) $user->id, 'user.calendar.delete',
    "Deleted calendar '{$calUri}'", 'calendar', (string) $cal['id']
);

apiJson(['ok' => true]);
