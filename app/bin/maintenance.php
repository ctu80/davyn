<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Davyn\Config\Config;
use Davyn\Maintenance\MaintenanceMode;

$args = array_slice($argv, 1);
$command = $args[0] ?? null;

if ($command === null || !in_array($command, ['status', 'enable', 'disable'], true)) {
    echo "Usage:" . PHP_EOL;
    echo "  php maintenance.php status" . PHP_EOL;
    echo "  php maintenance.php enable --reason <reason>" . PHP_EOL;
    echo "  php maintenance.php disable" . PHP_EOL;
    exit(1);
}

// Parse --reason from remaining args
$reason = null;
foreach ($args as $i => $arg) {
    if ($arg === '--reason' && isset($args[$i + 1])) {
        $reason = $args[$i + 1];
    }
}

try {
    $config = new Config();
    $mm     = MaintenanceMode::fromConfig($config);

    switch ($command) {
        case 'status':
            $s = $mm->status();
            if ($s['enabled']) {
                echo "Maintenance mode: ENABLED" . PHP_EOL;
                echo "  Reason    : " . ($s['reason']     ?? '—') . PHP_EOL;
                echo "  Enabled at: " . ($s['enabled_at'] ?? '—') . PHP_EOL;
            } else {
                echo "Maintenance mode: disabled" . PHP_EOL;
            }
            break;

        case 'enable':
            if ($reason === null || $reason === '') {
                echo "ERROR: --reason is required." . PHP_EOL;
                exit(1);
            }
            $mm->enable($reason);
            echo "Maintenance mode ENABLED. Reason: {$reason}" . PHP_EOL;
            break;

        case 'disable':
            $mm->disable();
            echo "Maintenance mode disabled." . PHP_EOL;
            break;
    }
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
