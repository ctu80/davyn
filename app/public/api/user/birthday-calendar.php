<?php
declare(strict_types=1);

require_once __DIR__ . '/_auth.php';

use Davyn\Birthday\BirthdayService;

apiMethodGuard('GET');
['pdo' => $pdo, 'user' => $user] = apiUserGuard();

apiJson((new BirthdayService($pdo))->status((int) $user->id));
