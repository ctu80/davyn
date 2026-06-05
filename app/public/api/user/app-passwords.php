<?php
declare(strict_types=1);

require_once __DIR__ . '/_auth.php';

use Davyn\Auth\AppPasswordRepository;

apiMethodGuard('GET');
['pdo' => $pdo, 'user' => $user] = apiUserGuard();

$repo = new AppPasswordRepository($pdo);
$list = $repo->listForUser($user->username);

apiJson(['app_passwords' => $list]);
