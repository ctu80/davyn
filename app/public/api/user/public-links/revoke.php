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

// Only allow revoking links for calendars owned by this user
$stmt = $pdo->prepare(
    'SELECT pl.id, c.display_name FROM public_calendar_links pl
     JOIN calendars c ON c.id = pl.calendar_id
     WHERE pl.id = ? AND c.user_id = ? AND pl.revoked_at IS NULL'
);
$stmt->execute([$id, (int) $user->id]);
$link = $stmt->fetch();
if (!$link) apiError('Link not found or already revoked', 404);

$now = gmdate('Y-m-d\TH:i:s\Z');
$pdo->prepare('UPDATE public_calendar_links SET revoked_at = ? WHERE id = ?')
    ->execute([$now, $id]);

(new ActivityLog($pdo))->log(
    (int) $user->id,
    'public_link.revoke',
    "Revoked public link for calendar '{$link['display_name']}'",
    'calendar',
    (string) $link['display_name'],
);

apiJson(['ok' => true]);
