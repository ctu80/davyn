<?php
declare(strict_types=1);

require_once __DIR__ . '/_auth.php';

apiMethodGuard('GET');
['pdo' => $pdo] = apiAdminGuard();

$rows = $pdo->query(
    'SELECT username, display_name, role, is_active, created_at FROM users ORDER BY id'
)->fetchAll();

$users = array_map(fn(array $row) => [
    'username'     => $row['username'],
    'display_name' => $row['display_name'],
    'role'         => $row['role'],
    'active'       => (bool) $row['is_active'],
    'created_at'   => $row['created_at'],
], $rows);

apiHeaders();
echo json_encode($users);
