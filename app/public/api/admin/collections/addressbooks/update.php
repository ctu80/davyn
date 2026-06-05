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

$body     = json_decode(file_get_contents('php://input'), true) ?? [];
$username = trim((string) ($body['username'] ?? ''));
$uri      = trim((string) ($body['uri']      ?? ''));

if ($username === '') apiError('username is required', 400);
if ($uri === '')      apiError('uri is required', 400);

$fields = [];
foreach (['display_name', 'description'] as $field) {
    if (array_key_exists($field, $body)) {
        $fields[$field] = $body[$field] === null ? null : (string) $body[$field];
    }
}

try {
    $repo = new AddressBookRepository($pdo);
    $repo->updateAddressBook($username, $uri, $fields);
} catch (\InvalidArgumentException $e) {
    apiError($e->getMessage(), 400);
} catch (\Throwable $e) {
    error_log("[davyn] 500 " . __FILE__ . ": " . $e->getMessage()); apiError("Internal server error", 500);
}

apiJson(['ok' => true]);
