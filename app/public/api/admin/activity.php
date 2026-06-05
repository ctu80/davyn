<?php
declare(strict_types=1);

require_once __DIR__ . '/_auth.php';

use Davyn\Audit\ActivityLog;

apiMethodGuard('GET');
['pdo' => $pdo] = apiAdminGuard();

$limit = min(500, max(1, (int) ($_GET['limit'] ?? 100)));

$log     = new ActivityLog($pdo);
$entries = $log->recent($limit);

apiJson(['entries' => $entries, 'count' => count($entries)]);
