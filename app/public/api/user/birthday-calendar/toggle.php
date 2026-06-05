<?php
declare(strict_types=1);

require_once __DIR__ . '/../_auth.php';

use Davyn\Birthday\BirthdayService;
use Davyn\Http\Csrf;

apiMethodGuard('POST');
['pdo' => $pdo, 'user' => $user, 'session' => $session] = apiUserGuard();

$csrf = new Csrf($session);
if (!$csrf->verify($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    apiError('Invalid CSRF token', 403);
}

$body    = json_decode(file_get_contents('php://input'), true) ?? [];
$enabled = (bool) ($body['enabled'] ?? false);

try {
    $svc = new BirthdayService($pdo);
    if ($enabled) {
        $svc->enable((int) $user->id);
    } else {
        $svc->disable((int) $user->id);
    }
} catch (\Throwable $e) {
    apiError('Could not update birthday calendar: ' . $e->getMessage(), 500);
}

apiJson(['ok' => true] + $svc->status((int) $user->id));
