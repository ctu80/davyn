<?php
declare(strict_types=1);

require_once __DIR__ . '/_auth.php';

use Davyn\Sharing\SharingService;

apiMethodGuard('GET');
['pdo' => $pdo, 'session' => $session] = apiAdminGuard();

$type = $_GET['type'] ?? '';
$id   = (int) ($_GET['id'] ?? 0);

if (!in_array($type, ['calendar', 'addressbook'], true) || $id <= 0) {
    apiError('Missing or invalid type/id', 400);
}

// Verify collection exists
$table = $type === 'calendar' ? 'calendars' : 'addressbooks';
$row   = $pdo->prepare("SELECT id, uri, display_name, user_id FROM $table WHERE id = ?");
$row->execute([$id]);
$collection = $row->fetch();
if (!$collection) {
    apiError('Collection not found', 404);
}

$ownerStmt = $pdo->prepare('SELECT username FROM users WHERE id = ?');
$ownerStmt->execute([$collection['user_id']]);
$ownerUsername = (string) $ownerStmt->fetchColumn();

$svc    = new SharingService($pdo);
$shares = $svc->listSharesForCollection($type, $id);

apiHeaders();
echo json_encode([
    'collection' => [
        'id'             => (int) $collection['id'],
        'type'           => $type,
        'uri'            => $collection['uri'],
        'display_name'   => $collection['display_name'],
        'owner_username' => $ownerUsername,
    ],
    'shares' => array_map(fn($s) => [
        'username'    => $s['username'],
        'display_name'=> $s['display_name'],
        'permission'  => $s['permission'],
        'updated_at'  => $s['updated_at'],
    ], $shares),
]);
