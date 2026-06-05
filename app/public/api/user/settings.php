<?php
declare(strict_types=1);

require_once __DIR__ . '/_auth.php';

use Davyn\Settings\SettingsRepository;

apiMethodGuard('GET');
// Allowed during maintenance so the maintenance screen still gets branding/locale.
['pdo' => $pdo] = apiUserGuard(true);

$repo = new SettingsRepository($pdo);
apiJson($repo->getAll());
