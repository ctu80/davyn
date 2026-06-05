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

try {
    $result = (new BirthdayService($pdo))->regenerate((int) $user->id);
} catch (\Throwable $e) {
    apiError('Could not regenerate birthday calendar: ' . $e->getMessage(), 500);
}

apiJson(['ok' => true, 'generated' => $result['generated'], 'removed' => $result['removed']]);
