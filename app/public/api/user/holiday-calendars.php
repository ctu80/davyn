<?php
declare(strict_types=1);

require_once __DIR__ . '/_auth.php';

use Davyn\Holiday\HolidaySubscriptionService;

apiMethodGuard('GET');
['pdo' => $pdo, 'user' => $user, 'config' => $config] = apiUserGuard();

$svc = new HolidaySubscriptionService($pdo, $config);

// Lazy rolling generation: roll subscriptions forward as new years come into
// range. Never let it break the listing.
if ($config->holidayAutoGenerate()) {
    try {
        $svc->runRolling((int) $user->id);
    } catch (\Throwable) {
        // ignore — listing must still succeed
    }
}

apiJson([
    'subscriptions' => $svc->listForUser((int) $user->id),
    'catalog'       => $svc->catalog(),
]);
