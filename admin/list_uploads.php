<?php
declare(strict_types=1);

header('Content-Type: application/json');

$uploadDir = dirname(__DIR__) . '/img/upload';

if (!is_dir($uploadDir)) {
    echo json_encode(['ok' => true, 'files' => []]);
    exit;
}

$files = [];
$dirFiles = scandir($uploadDir) ?: [];

foreach ($dirFiles as $file) {
    if ($file === '.' || $file === '..') continue;
    $filePath = $uploadDir . '/' . $file;
    
    if (!is_file($filePath)) continue;
    
    $stat = stat($filePath);
    if ($stat === false) continue;
    
    $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $isImage = in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
    $isPdf = $extension === 'pdf';
    
    if (!$isImage && !$isPdf) continue;
    
    $files[] = [
        'filename' => $file,
        'url' => '/img/upload/' . $file,
        'size' => $stat['size'],
        'timestamp' => $stat['mtime'],
        'type' => $isImage ? 'image' : 'pdf'
    ];
}

// Sort by timestamp (newest first)
usort($files, function($a, $b) {
    return $b['timestamp'] - $a['timestamp'];
});

echo json_encode([
    'ok' => true,
    'files' => $files
]);

