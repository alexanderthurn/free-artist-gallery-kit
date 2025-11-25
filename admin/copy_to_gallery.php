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

// Find JSON file to load metadata
$jsonFile = find_json_file($base, $imagesDir);

if (!$jsonFile || !is_file($imagesDir.$jsonFile)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'JSON metadata not found']);
    exit;
}

// Load JSON to get metadata
$meta = load_meta($jsonFile, $imagesDir);
if (empty($meta)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON metadata']);
    exit;
}

// Always set live status to true first (thread-safe)
// This ensures the state is saved even if gallery operations fail
$saveResult = save_meta($jsonFile, ['live' => true], $imagesDir, false);
if (!$saveResult['ok']) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to update JSON metadata']);
    exit;
}

// Reload meta for gallery operations
$meta = load_meta($jsonFile, $imagesDir);
$meta['live'] = true;

// Try to update gallery entry, but don't fail if there are errors
// (e.g., missing final image, missing title, etc.)
$galleryResult = update_gallery_entry($base, $meta, $imagesDir, $galleryDir);

// Always return success if JSON was updated, even if gallery operations failed
echo json_encode([
    'ok' => true,
    'json_updated' => true,
    'gallery_updated' => $galleryResult['ok'] ?? false,
    'gallery_error' => $galleryResult['ok'] ? null : ($galleryResult['error'] ?? 'Unknown error')
]);
exit;

