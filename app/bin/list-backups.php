<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Davyn\Config\Config;

try {
    $config    = new Config();
    $backupDir = dirname($config->dbPath()) . '/backups';

    if (!is_dir($backupDir)) {
        echo "No backup directory found at: $backupDir" . PHP_EOL;
        exit(0);
    }

    $files = glob("$backupDir/davyn-backup-*.sqlite");
    if (empty($files)) {
        echo "No backups found in: $backupDir" . PHP_EOL;
        exit(0);
    }

    usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));

    $fmt = "%-44s  %10s  %s" . PHP_EOL;
    printf($fmt, 'FILENAME', 'SIZE', 'MODIFIED AT (UTC)');
    echo str_repeat('-', 80) . PHP_EOL;

    foreach ($files as $file) {
        $name  = basename($file);
        $size  = filesize($file);
        $mtime = gmdate('Y-m-d H:i:s', filemtime($file));
        $sizeHuman = $size >= 1024 * 1024
            ? round($size / (1024 * 1024), 2) . ' MB'
            : round($size / 1024, 1) . ' KB';
        printf($fmt, $name, $sizeHuman, $mtime);
    }
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
