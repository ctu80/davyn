<?php
declare(strict_types=1);

require_once __DIR__ . '/../_auth.php';

use Davyn\Audit\ActivityLog;
use Davyn\Holiday\HolidaySubscriptionService;
use Davyn\Http\Csrf;

apiMethodGuard('POST');
['pdo' => $pdo, 'user' => $user, 'session' => $session, 'config' => $config] = apiUserGuard();

$csrf = new Csrf($session);
if (!$csrf->verify($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    apiError('Invalid CSRF token', 403);
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$id   = (int) ($body['id'] ?? 0);
if ($id <= 0) apiError('id is required', 400);

try {
    $svc = new HolidaySubscriptionService($pdo, $config);
    $svc->remove((int) $user->id, $id);
} catch (\InvalidArgumentException $e) {
    apiError($e->getMessage(), 404);
}

(new ActivityLog($pdo))->log((int) $user->id, 'holiday.unsubscribe', 'Removed holiday calendar', 'subscription', (string) $id);

apiJson(['ok' => true]);
