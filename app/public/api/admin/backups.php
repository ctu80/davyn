<?php
declare(strict_types=1);

require_once __DIR__ . '/_auth.php';

apiMethodGuard('GET');
['config' => $config] = apiAdminGuard();

$backupDir = dirname($config->dbPath()) . '/backups';
$files     = is_dir($backupDir) ? glob("$backupDir/davyn-backup-*.sqlite") : [];

usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));

$list = array_map(function(string $file): array {
    $size = filesize($file);
    $sizeHuman = $size >= 1024 * 1024
        ? round($size / (1024 * 1024), 2) . ' MB'
        : round($size / 1024, 1) . ' KB';
    return [
        'filename'    => basename($file),
        'size'        => $size,
        'size_human'  => $sizeHuman,
        'modified_at' => gmdate('Y-m-d H:i:s', filemtime($file)),
    ];
}, $files);

apiHeaders();
echo json_encode($list);
