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
$meta = [];

if ($jsonFile && is_file($imagesDir.$jsonFile)) {
    $meta = load_meta($jsonFile, $imagesDir);
    if (is_array($meta) && isset($meta['original_filename'])) {
        $originalFilename = $meta['original_filename'];
    }
}

// Always update JSON file to set live status to false first (thread-safe)
// This ensures the state is saved even if gallery operations fail
$jsonUpdated = false;
if ($jsonFile && is_file($imagesDir.$jsonFile)) {
    $jsonUpdated = update_json_file($imagesDir.$jsonFile, ['live' => false], false);
}

// Try to find and delete gallery entry, but don't fail if it doesn't exist
$galleryFilename = find_gallery_entry($originalFilename, $galleryDir);
if (!$galleryFilename) {
    $galleryFilename = find_gallery_entry($base, $galleryDir);
}

$deleted = [];
if ($galleryFilename) {
    // Delete all files with this base name in gallery (image, json, and variants)
    $galleryFiles = scandir($galleryDir) ?: [];
    foreach ($galleryFiles as $gFile) {
        if ($gFile === '.' || $gFile === '..') continue;
        $fileStem = pathinfo($gFile, PATHINFO_FILENAME);
        // Check if file starts with gallery filename
        if (strpos($fileStem, $galleryFilename) === 0) {
            $filePath = $galleryDir.$gFile;
            if (is_file($filePath) && unlink($filePath)) {
                $deleted[] = $gFile;
            }
        }
    }
}

// Always return success if JSON was updated, even if gallery files weren't found/deleted
if ($jsonUpdated) {
    echo json_encode([
        'ok' => true,
        'deleted' => $deleted,
        'json_updated' => true
    ]);
    exit;
}

// Only fail if JSON file doesn't exist or couldn't be updated
http_response_code(404);
echo json_encode(['ok' => false, 'error' => 'JSON metadata not found or could not be updated']);
exit;

