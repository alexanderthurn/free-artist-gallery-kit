<?php
declare(strict_types=1);

header('Content-Type: application/json');

$backupDir = __DIR__ . '/backups';

if (!is_dir($backupDir)) {
    echo json_encode(['ok' => true, 'backups' => []]);
    exit;
}

$backups = [];
$files = scandir($backupDir) ?: [];

foreach ($files as $file) {
    if ($file === '.' || $file === '..') continue;
    // Support both JSON and HTML backups
    if ((strpos($file, 'artist_') === 0 && pathinfo($file, PATHINFO_EXTENSION) === 'json') ||
        (strpos($file, 'index_') === 0 && pathinfo($file, PATHINFO_EXTENSION) === 'html')) {
        $filePath = $backupDir . '/' . $file;
        $stat = stat($filePath);
        if ($stat !== false) {
            $backups[] = [
                'filename' => $file,
                'timestamp' => $stat['mtime'],
                'size' => $stat['size']
            ];
        }
    }
}

// Sort by timestamp (newest first)
usort($backups, function($a, $b) {
    return $b['timestamp'] - $a['timestamp'];
});

echo json_encode([
    'ok' => true,
    'backups' => $backups
]);

