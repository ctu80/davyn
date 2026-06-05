<?php
declare(strict_types=1);

require_once __DIR__ . '/_auth.php';

use Davyn\Sharing\SharingService;

apiMethodGuard('GET');

$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (str_starts_with($authHeader, 'Bearer ')) {
    ['pdo' => $pdo] = apiBearerGuard('read:collections');
} else {
    ['pdo' => $pdo] = apiAdminGuard();
}

$users = $pdo->query('SELECT id, username FROM users ORDER BY id')->fetchAll();

$svc = new SharingService($pdo);

$countObjects = function(PDO $db, string $table, string $fk, int $id): int {
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM $table WHERE $fk = ? AND deleted_at IS NULL"
    );
    $stmt->execute([$id]);
    return (int) $stmt->fetchColumn();
};

$result = [];

foreach ($users as $user) {
    $uid = (int) $user['id'];

    $calStmt = $pdo->prepare('SELECT id, uri, display_name, sync_token FROM calendars WHERE user_id = ? ORDER BY id');
    $calStmt->execute([$uid]);
    $calendars = [];
    foreach ($calStmt->fetchAll() as $cal) {
        $calId = (int) $cal['id'];
        $calendars[] = [
            'id'             => $calId,
            'uri'            => $cal['uri'],
            'display_name'   => $cal['display_name'],
            'owner_username' => $user['username'],
            'sync_token'     => (int) $cal['sync_token'],
            'object_count'   => $countObjects($pdo, 'calendar_objects', 'calendar_id', $calId),
            'shares_count'   => $svc->countSharesForCollection('calendar', $calId),
        ];
    }

    $abStmt = $pdo->prepare('SELECT id, uri, display_name, sync_token FROM addressbooks WHERE user_id = ? ORDER BY id');
    $abStmt->execute([$uid]);
    $addressbooks = [];
    foreach ($abStmt->fetchAll() as $ab) {
        $abId = (int) $ab['id'];
        $addressbooks[] = [
            'id'             => $abId,
            'uri'            => $ab['uri'],
            'display_name'   => $ab['display_name'],
            'owner_username' => $user['username'],
            'sync_token'     => (int) $ab['sync_token'],
            'object_count'   => $countObjects($pdo, 'addressbook_objects', 'addressbook_id', $abId),
            'shares_count'   => $svc->countSharesForCollection('addressbook', $abId),
        ];
    }

    $result[] = [
        'username'     => $user['username'],
        'calendars'    => $calendars,
        'addressbooks' => $addressbooks,
    ];
}

apiHeaders();
echo json_encode($result);
