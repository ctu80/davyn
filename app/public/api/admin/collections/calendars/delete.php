<?php
declare(strict_types=1);

require_once __DIR__ . '/../../_auth.php';

use Davyn\Audit\ActivityLog;
use Davyn\Dav\CalendarRepository;
use Davyn\Http\Csrf;

apiMethodGuard('POST');
['pdo' => $pdo, 'user' => $admin, 'session' => $session] = apiAdminGuard();

$csrf = new Csrf($session);
if (!$csrf->verify($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    apiError('Invalid CSRF token', 403);
}

$body     = json_decode(file_get_contents('php://input'), true) ?? [];
$username = trim((string) ($body['username'] ?? ''));
$uri      = trim((string) ($body['uri']      ?? ''));
if ($username === '' || $uri === '') apiError('username and uri are required', 400);

$stmt = $pdo->prepare(
    'SELECT c.id, c.generated_type FROM calendars c JOIN users u ON u.id = c.user_id WHERE u.username = ? AND c.uri = ?'
);
$stmt->execute([$username, $uri]);
$row = $stmt->fetch();
if ($row === false) apiError('Calendar not found', 404);
if (!empty($row['generated_type'])) {
    apiError('This is a generated, read-only calendar — disable it from its settings instead of deleting it.', 403);
}
$id = (int) $row['id'];

(new CalendarRepository($pdo))->deleteCalendarById((int) $id);

(new ActivityLog($pdo))->log(
    (int) $admin->id, 'admin.calendar.delete',
    "Deleted calendar '{$uri}' of user '{$username}'", 'calendar', (string) $id
);

apiJson(['ok' => true]);
