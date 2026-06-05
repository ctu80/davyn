<?php
declare(strict_types=1);

require_once __DIR__ . '/_auth.php';

use Davyn\User\UserRepository;

// Minimal directory of other active users a user can share their own collections
// with. Exposes only username + display name (needed to pick a share recipient).
apiMethodGuard('GET');
['pdo' => $pdo, 'user' => $user] = apiUserGuard();

$rows = (new UserRepository($pdo))->listShareTargets((int) $user->id);

apiJson(['users' => array_map(fn($r) => [
    'username'     => $r['username'],
    'display_name' => $r['display_name'],
], $rows)]);
