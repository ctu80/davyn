<?php
declare(strict_types=1);

require_once __DIR__ . '/../../_auth.php';

use Davyn\Audit\ActivityLog;
use Davyn\Dav\AddressBookRepository;
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
    'SELECT a.id FROM addressbooks a JOIN users u ON u.id = a.user_id WHERE u.username = ? AND a.uri = ?'
);
$stmt->execute([$username, $uri]);
$id = $stmt->fetchColumn();
if ($id === false) apiError('Address book not found', 404);

(new AddressBookRepository($pdo))->deleteAddressBookById((int) $id);

(new ActivityLog($pdo))->log(
    (int) $admin->id, 'admin.addressbook.delete',
    "Deleted address book '{$uri}' of user '{$username}'", 'addressbook', (string) $id
);

apiJson(['ok' => true]);
