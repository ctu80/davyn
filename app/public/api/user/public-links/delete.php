<?php
declare(strict_types=1);

require_once __DIR__ . '/../_auth.php';

use Davyn\Http\Csrf;
use Davyn\Audit\ActivityLog;

apiMethodGuard('POST');
['pdo' => $pdo, 'user' => $user, 'session' => $session] = apiUserGuard();

$csrf = new Csrf($session);
if (!$csrf->verify($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    apiError('Invalid CSRF token', 403);
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$id   = (int) ($body['id'] ?? 0);
if (!$id) apiError('id is required', 400);

// Only allow deleting links for calendars owned by this user. A link must be
// revoked first — deleting an active link is refused so a live feed is never
// removed without an explicit revoke step.
$stmt = $pdo->prepare(
    'SELECT pl.id, pl.revoked_at, c.display_name FROM public_calendar_links pl
     JOIN calendars c ON c.id = pl.calendar_id
     WHERE pl.id = ? AND c.user_id = ?'
);
$stmt->execute([$id, (int) $user->id]);
$link = $stmt->fetch();
if (!$link) apiError('Link not found', 404);
if ($link['revoked_at'] === null) apiError('Revoke the link before deleting it', 409);

$pdo->prepare('DELETE FROM public_calendar_links WHERE id = ?')->execute([$id]);

(new ActivityLog($pdo))->log(
    (int) $user->id,
    'public_link.delete',
    "Deleted revoked public link for calendar '{$link['display_name']}'",
    'calendar',
    (string) $link['display_name'],
);

apiJson(['ok' => true]);
