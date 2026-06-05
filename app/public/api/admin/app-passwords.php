<?php
declare(strict_types=1);

require_once __DIR__ . '/_auth.php';

use Davyn\Auth\AppPasswordRepository;

apiMethodGuard('GET');
['pdo' => $pdo] = apiAdminGuard();

$username = trim((string) ($_GET['username'] ?? ''));
if ($username === '') {
    apiError('username parameter is required', 400);
}

try {
    $repo = new AppPasswordRepository($pdo);
    $rows = $repo->listForUser($username);
} catch (\InvalidArgumentException $e) {
    apiError($e->getMessage(), 404);
} catch (\Throwable $e) {
    error_log("[davyn] 500 " . __FILE__ . ": " . $e->getMessage()); apiError("Internal server error", 500);
}

$list = array_map(fn(array $row) => [
    'name'         => $row['name'],
    'created_at'   => $row['created_at'],
    'last_used_at' => $row['last_used_at'] ?? null,
    'revoked_at'   => $row['revoked_at']   ?? null,
    'active'       => ($row['revoked_at'] ?? null) === null,
], $rows);

apiJson($list);
