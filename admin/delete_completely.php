<?php
declare(strict_types=1);

require_once __DIR__.'/utils.php';
require_once __DIR__.'/meta.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$image = isset($_POST['image']) ? basename((string)$_POST['image']) : '';
if ($image === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing image']);
    exit;
}

$imagesDir = __DIR__.'/images/';
$galleryDir = dirname(__DIR__).'/img/gallery/';

// Extract base name from image
$base = extract_base_name($image);

// Find JSON file to get original_filename
$jsonFile = find_json_file($base, $imagesDir);
$originalFilename = $base;

if ($jsonFile && is_file($imagesDir.$jsonFile)) {
    $meta = load_meta($jsonFile, $imagesDir);
    if (is_array($meta) && isset($meta['original_filename'])) {
        $originalFilename = $meta['original_filename'];
    }
}

// Find gallery entry by original_filename or base name
$galleryFilename = find_gallery_entry($originalFilename, $galleryDir);
if (!$galleryFilename) {
    $galleryFilename = find_gallery_entry($base, $galleryDir);
}

$deleted = [];

// Delete gallery files if exists
if ($galleryFilename && is_dir($galleryDir)) {
    $galleryFiles = scandir($galleryDir) ?: [];
    foreach ($galleryFiles as $gFile) {
        if ($gFile === '.' || $gFile === '..') continue;
        $fileStem = pathinfo($gFile, PATHINFO_FILENAME);
        if (strpos($fileStem, $galleryFilename) === 0) {
            $filePath = $galleryDir.$gFile;
            if (is_file($filePath) && unlink($filePath)) {
                $deleted[] = 'gallery/'.$gFile;
            }
        }
    }
}

// Delete all admin/images files for this painting
$files = scandir($imagesDir) ?: [];
foreach ($files as $file) {
    if ($file === '.' || $file === '..') continue;
    if (strpos($file, $base) === 0) {
        $filePath = $imagesDir.$file;
        if (is_file($filePath) && unlink($filePath)) {
            $deleted[] = 'images/'.$file;
        }
    }
}

if (empty($deleted)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'No files found to delete']);
    exit;
}

echo json_encode([
    'ok' => true,
    'deleted' => $deleted,
    'count' => count($deleted)
]);
exit;

