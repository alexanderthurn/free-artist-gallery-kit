<?php
declare(strict_types=1);

require_once __DIR__ . '/meta.php';

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

$imagesDir = __DIR__ . '/images/';
$imagePath = $imagesDir . $image;

if (!is_file($imagePath)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Image not found']);
    exit;
}

// Extract base name to find _original image
$base = extract_base_name($image);

// Find _original image file
$originalImageFile = null;
$files = scandir($imagesDir) ?: [];
foreach ($files as $file) {
    if ($file === '.' || $file === '..') continue;
    $fileStem = pathinfo($file, PATHINFO_FILENAME);
    if (preg_match('/^' . preg_quote($base, '/') . '_original\.(jpg|jpeg|png|webp)$/i', $fileStem)) {
        $originalImageFile = $file;
        break;
    }
}

if (!$originalImageFile) {
    http_response_code(404);
    echo json_encode([
        'ok' => false, 
        'error' => 'Original image not found',
        'base' => $base,
        'image' => $image
    ]);
    exit;
}

// Load or create metadata for the _original image
$meta = load_meta($originalImageFile, $imagesDir);

// If metadata doesn't exist, create it with basic info
if (empty($meta)) {
    $updates = [
        'original_filename' => $base,
        'frame_type' => 'white',
        'sold' => false
    ];
    
    // Get image dimensions if possible
    $originalImagePath = $imagesDir . $originalImageFile;
    if (is_file($originalImagePath)) {
        $imageInfo = @getimagesize($originalImagePath);
        if ($imageInfo !== false) {
            $updates['image_dimensions'] = [
                'width' => $imageInfo[0],
                'height' => $imageInfo[1]
            ];
        }
    }
    
    $result = save_meta($originalImageFile, $updates, $imagesDir, false);
    if (!$result['ok']) {
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'error' => 'Failed to create metadata: ' . ($result['error'] ?? 'Unknown error')
        ]);
        exit;
    }
    
    // Reload metadata
    $meta = load_meta($originalImageFile, $imagesDir);
}

// Set form status to wanted using update_task_status
$metaPath = get_meta_path($originalImageFile, $imagesDir);
update_task_status($metaPath, 'ai_form', 'wanted');

echo json_encode([
    'ok' => true,
    'message' => 'AI form fill flag set. Will be processed by background tasks.'
]);

