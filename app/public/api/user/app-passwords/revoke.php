<?php
declare(strict_types=1);

require_once __DIR__ . '/../_auth.php';

use Davyn\Auth\AppPasswordRepository;
use Davyn\Http\Csrf;

apiMethodGuard('POST');
['pdo' => $pdo, 'user' => $user, 'session' => $session] = apiUserGuard();

$csrf = new Csrf($session);
if (!$csrf->verify($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    apiError('Invalid CSRF token', 403);
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$name = trim((string) ($body['name'] ?? ''));

if ($name === '') apiError('name is required', 400);

try {
    $repo = new AppPasswordRepository($pdo);
    $repo->revokeForUser($user->username, $name);
} catch (\InvalidArgumentException $e) {
    apiError($e->getMessage(), 400);
}

apiJson(['ok' => true]);
