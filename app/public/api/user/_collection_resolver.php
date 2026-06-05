<?php
declare(strict_types=1);

use Davyn\Sharing\SharingService;

/**
 * Resolve a virtual addressbook URI (own or shared-*) to its DB row + permission.
 * Returns ['ab' => row, 'permission' => string] or null if not accessible.
 */
function resolveAccessibleAddressBook(\PDO $pdo, int $userId, string $virtualUri): ?array
{
    if (str_starts_with($virtualUri, 'shared-')) {
        $svc        = new SharingService($pdo);
        $accessible = $svc->listAccessibleAddressBooksForUser($userId);
        foreach ($accessible as $row) {
            if ($row['permission'] === 'owner') {
                continue;
            }
            if ('shared-' . $row['owner_username'] . '-' . $row['uri'] === $virtualUri) {
                $stmt = $pdo->prepare('SELECT * FROM addressbooks WHERE id = ?');
                $stmt->execute([(int) $row['id']]);
                $ab = $stmt->fetch();
                return $ab ? ['ab' => $ab, 'permission' => $row['permission']] : null;
            }
        }
        return null;
    }

    $stmt = $pdo->prepare('SELECT * FROM addressbooks WHERE user_id = ? AND uri = ?');
    $stmt->execute([$userId, $virtualUri]);
    $ab = $stmt->fetch();
    return $ab ? ['ab' => $ab, 'permission' => 'owner'] : null;
}

/**
 * Resolve a virtual calendar URI (own or shared-*) to its DB row + permission.
 */
function resolveAccessibleCalendar(\PDO $pdo, int $userId, string $virtualUri): ?array
{
    if (str_starts_with($virtualUri, 'shared-')) {
        $svc        = new SharingService($pdo);
        $accessible = $svc->listAccessibleCalendarsForUser($userId);
        foreach ($accessible as $row) {
            if ($row['permission'] === 'owner') {
                continue;
            }
            if ('shared-' . $row['owner_username'] . '-' . $row['uri'] === $virtualUri) {
                $stmt = $pdo->prepare('SELECT * FROM calendars WHERE id = ?');
                $stmt->execute([(int) $row['id']]);
                $cal = $stmt->fetch();
                return $cal ? ['cal' => $cal, 'permission' => $row['permission']] : null;
            }
        }
        return null;
    }

    $stmt = $pdo->prepare('SELECT * FROM calendars WHERE user_id = ? AND uri = ?');
    $stmt->execute([$userId, $virtualUri]);
    $cal = $stmt->fetch();
    return $cal ? ['cal' => $cal, 'permission' => 'owner'] : null;
}
