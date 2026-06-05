<?php
declare(strict_types=1);

require_once __DIR__ . '/../../_auth.php';

use Davyn\Dav\AddressBookRepository;
use Davyn\Http\Csrf;

apiMethodGuard('POST');
['pdo' => $pdo, 'session' => $session] = apiAdminGuard();

$csrf = new Csrf($session);
if (!$csrf->verify($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    apiError('Invalid CSRF token', 403);
}

$body        = json_decode(file_get_contents('php://input'), true) ?? [];
$username    = trim((string) ($body['username']     ?? ''));
$uri         = trim((string) ($body['uri']          ?? ''));
$displayName = trim((string) ($body['display_name'] ?? ''));
$description = isset($body['description']) ? (string) $body['description'] : null;

if ($username === '')    apiError('username is required', 400);
if ($displayName === '') apiError('display_name is required', 400);

try {
    $repo = new AddressBookRepository($pdo);
    $repo->createAddressBookForUser($username, $uri, $displayName, $description ?: null);
} catch (\InvalidArgumentException $e) {
    $status = str_contains($e->getMessage(), 'already exists') ? 409 : 400;
    apiError($e->getMessage(), $status);
} catch (\Throwable $e) {
    error_log("[davyn] 500 " . __FILE__ . ": " . $e->getMessage()); apiError("Internal server error", 500);
}

apiJson(['ok' => true]);
