<?php
declare(strict_types=1);

// Ensure no output before JSON
ob_start();

require_once __DIR__.'/utils.php';

// Configuration parameters
$GALLERY_MAX_WIDTH = 1536;
$GALLERY_MAX_HEIGHT = 1536;
$UPLOAD_MAX_WIDTH = 1536;
$UPLOAD_MAX_HEIGHT = 1536;
$THUMBNAIL_MAX_WIDTH = 286;
$THUMBNAIL_MAX_HEIGHT = 1024;

// Clear any output buffer
ob_clean();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

// Return immediately and process in background
echo json_encode(['ok' => true, 'message' => 'Processing started in background']);

// Flush output to client
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    // Fallback: close connection and continue processing
    if (ob_get_level()) {
        ob_end_flush();
    }
    flush();
    if (function_exists('apache_setenv')) {
        @apache_setenv('no-gzip', 1);
    }
    @ini_set('zlib.output_compression', 0);
}

// Get action parameter
$action = $_POST['action'] ?? 'both';
$preview = isset($_POST['preview']) && $_POST['preview'] === '1';

// If preview mode, return what will be processed
if ($preview) {
    $previewData = preview_processing($action);
    ob_clean(); // Ensure no extra output
    echo json_encode([
        'ok' => true,
        'preview' => true,
        'data' => $previewData
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// Return immediately and process in background
ob_clean(); // Ensure no extra output
echo json_encode(['ok' => true, 'message' => 'Processing started in background'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

// Flush output to client
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    // Fallback: close connection and continue processing
    if (ob_get_level()) {
        ob_end_flush();
    }
    flush();
    if (function_exists('apache_setenv')) {
        @apache_setenv('no-gzip', 1);
    }
    @ini_set('zlib.output_compression', 0);
}

// Start background processing
process_images($action);

/**
 * Preview what will be processed (without actually processing)
 */
function preview_processing(string $action): array {
    global $GALLERY_MAX_WIDTH, $GALLERY_MAX_HEIGHT, $UPLOAD_MAX_WIDTH, $UPLOAD_MAX_HEIGHT;
    global $THUMBNAIL_MAX_WIDTH, $THUMBNAIL_MAX_HEIGHT;
    
    $result = [
        'optimize' => [
            'gallery' => ['images' => [], 'thumbnails' => []],
            'upload' => ['images' => [], 'thumbnails' => []]
        ],
        'cleanup' => ['files_to_delete' => []]
    ];
    
    if ($action === 'optimize' || $action === 'both') {
        // Preview gallery images
        $galleryDir = dirname(__DIR__).'/img/gallery/';
        if (is_dir($galleryDir)) {
            $galleryPreview = preview_directory($galleryDir, $GALLERY_MAX_WIDTH, $GALLERY_MAX_HEIGHT, $THUMBNAIL_MAX_WIDTH, $THUMBNAIL_MAX_HEIGHT);
            $result['optimize']['gallery'] = $galleryPreview;
        }
        
        // Preview upload images
        $uploadDir = dirname(__DIR__).'/img/upload/';
        if (is_dir($uploadDir)) {
            $uploadPreview = preview_directory($uploadDir, $UPLOAD_MAX_WIDTH, $UPLOAD_MAX_HEIGHT, $THUMBNAIL_MAX_WIDTH, $THUMBNAIL_MAX_HEIGHT);
            $result['optimize']['upload'] = $uploadPreview;
        }
    }
    
    if ($action === 'cleanup' || $action === 'both') {
        $result['cleanup'] = preview_cleanup();
    }
    
    return $result;
}

/**
 * Preview directory processing
 */
function preview_directory(string $dir, int $maxWidth, int $maxHeight, int $thumbMaxWidth, int $thumbMaxHeight): array {
    $files = scandir($dir) ?: [];
    $images = [];
    $thumbnails = [];
    
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        
        $filePath = $dir.$file;
        
        // Skip directories, JSON files, and existing thumbnails
        if (is_dir($filePath)) continue;
        if (pathinfo($file, PATHINFO_EXTENSION) === 'json') continue;
        if (strpos($file, '_thumb.') !== false) continue;
        
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) continue;
        
        // Get image dimensions
        $imageInfo = @getimagesize($filePath);
        if ($imageInfo === false) continue;
        
        $currentWidth = $imageInfo[0];
        $currentHeight = $imageInfo[1];
        $needsResize = $currentWidth > $maxWidth || $currentHeight > $maxHeight;
        
        // Calculate new dimensions if resize needed
        $newWidth = $currentWidth;
        $newHeight = $currentHeight;
        if ($needsResize) {
            $scale = min($maxWidth / $currentWidth, $maxHeight / $currentHeight);
            $newWidth = (int) floor($currentWidth * $scale);
            $newHeight = (int) floor($currentHeight * $scale);
        }
        
        // Calculate thumbnail dimensions
        $thumbScale = min($thumbMaxWidth / $currentWidth, $thumbMaxHeight / $currentHeight);
        $thumbWidth = (int) floor($currentWidth * $thumbScale);
        $thumbHeight = (int) floor($currentHeight * $thumbScale);
        
        $thumbPath = generate_thumbnail_path($filePath);
        $thumbExists = is_file($thumbPath);
        
        // Check if thumbnail needs to be updated
        $needsThumbUpdate = false;
        $thumbAction = 'create';
        
        if ($thumbExists) {
            // Check thumbnail dimensions
            $thumbInfo = @getimagesize($thumbPath);
            if ($thumbInfo !== false) {
                $thumbCurrentWidth = $thumbInfo[0];
                $thumbCurrentHeight = $thumbInfo[1];
                
                // Check if dimensions match expected (allow 1px tolerance for rounding)
                $widthMatch = abs($thumbCurrentWidth - $thumbWidth) <= 1;
                $heightMatch = abs($thumbCurrentHeight - $thumbHeight) <= 1;
                
                // Check if source image is newer than thumbnail
                $sourceMtime = filemtime($filePath);
                $thumbMtime = filemtime($thumbPath);
                $sourceIsNewer = $sourceMtime > $thumbMtime;
                
                // Needs update if dimensions don't match OR source is newer
                if (!$widthMatch || !$heightMatch || $sourceIsNewer) {
                    $needsThumbUpdate = true;
                    $thumbAction = 'regenerate';
                } else {
                    $thumbAction = 'skip'; // Thumbnail is up to date
                }
            } else {
                // Thumbnail file exists but can't read dimensions - regenerate
                $needsThumbUpdate = true;
                $thumbAction = 'regenerate';
            }
        }
        
        $images[] = [
            'file' => $file,
            'current_size' => $currentWidth . 'x' . $currentHeight,
            'needs_resize' => $needsResize,
            'new_size' => $needsResize ? ($newWidth . 'x' . $newHeight) : null
        ];
        
        $thumbnails[] = [
            'file' => basename($thumbPath),
            'source' => $file,
            'size' => $thumbWidth . 'x' . $thumbHeight,
            'exists' => $thumbExists,
            'action' => $thumbAction,
            'needs_update' => $needsThumbUpdate
        ];
    }
    
    return ['images' => $images, 'thumbnails' => $thumbnails];
}

/**
 * Preview cleanup operations
 */
function preview_cleanup(): array {
    $galleryDir = dirname(__DIR__).'/img/gallery/';
    $imagesDir = __DIR__.'/images/';
    
    $filesToDelete = [];
    
    if (!is_dir($galleryDir)) {
        return ['files_to_delete' => []];
    }
    
    $galleryFiles = scandir($galleryDir) ?: [];
    $livePaintings = [];
    
    // First pass: identify live paintings from JSON files
    foreach ($galleryFiles as $file) {
        if ($file === '.' || $file === '..') continue;
        if (pathinfo($file, PATHINFO_EXTENSION) !== 'json') continue;
        
        $jsonPath = $galleryDir.$file;
        $jsonContent = file_get_contents($jsonPath);
        $meta = json_decode($jsonContent, true);
        
        if (is_array($meta) && isset($meta['original_filename'])) {
            $originalFilename = $meta['original_filename'];
            $base = pathinfo($file, PATHINFO_FILENAME);
            $livePaintings[$base] = $originalFilename;
        }
    }
    
    // Second pass: check each live painting and mark for deletion if orphaned
    foreach ($livePaintings as $galleryBase => $originalFilename) {
        // Check if corresponding admin file exists
        $adminFiles = scandir($imagesDir) ?: [];
        $hasAdminFile = false;
        
        foreach ($adminFiles as $adminFile) {
            if ($adminFile === '.' || $adminFile === '..') continue;
            if (strpos($adminFile, $originalFilename) === 0) {
                $hasAdminFile = true;
                break;
            }
        }
        
        // If no admin file exists, mark for deletion
        if (!$hasAdminFile) {
            $filesToDelete[] = [
                'file' => $galleryBase,
                'reason' => 'No corresponding admin file found',
                'type' => 'painting_entry'
            ];
            continue;
        }
        
        // Check for missing artifacts
        $hasJson = is_file($galleryDir.$galleryBase.'.json');
        $hasImage = false;
        
        $imageExtensions = ['jpg', 'jpeg', 'png', 'webp'];
        foreach ($imageExtensions as $ext) {
            if (is_file($galleryDir.$galleryBase.'.'.$ext)) {
                $hasImage = true;
                break;
            }
        }
        
        if (($hasJson && !$hasImage) || (!$hasJson && $hasImage)) {
            $filesToDelete[] = [
                'file' => $galleryBase,
                'reason' => 'Missing artifacts (JSON without image or image without JSON)',
                'type' => 'painting_entry'
            ];
        }
    }
    
    // Third pass: find orphaned images without JSON files
    $knownBases = array_keys($livePaintings);
    $imageExtensions = ['jpg', 'jpeg', 'png', 'webp'];
    
    foreach ($galleryFiles as $file) {
        if ($file === '.' || $file === '..') continue;
        
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (!in_array($ext, $imageExtensions)) continue;
        
        // Skip thumbnails
        if (strpos($file, '_thumb.') !== false) continue;
        
        $fileStem = pathinfo($file, PATHINFO_FILENAME);
        
        // Check if this image belongs to a known painting
        $belongsToKnownPainting = false;
        foreach ($knownBases as $galleryBase) {
            if (strpos($fileStem, $galleryBase) === 0) {
                $belongsToKnownPainting = true;
                break;
            }
        }
        
        // If it doesn't belong to a known painting, check if it has a JSON file
        if (!$belongsToKnownPainting && !is_file($galleryDir.$fileStem.'.json')) {
            // Extract base name
            $baseName = preg_replace('/_variant_.*$/', '', $fileStem);
            
            // Check if admin file exists
            $adminFiles = scandir($imagesDir) ?: [];
            $hasAdminFile = false;
            
            foreach ($adminFiles as $adminFile) {
                if ($adminFile === '.' || $adminFile === '..') continue;
                if (strpos($adminFile, $baseName) === 0) {
                    $hasAdminFile = true;
                    break;
                }
            }
            
            if (!$hasAdminFile) {
                $filesToDelete[] = [
                    'file' => $file,
                    'reason' => 'Orphaned image without JSON or admin file',
                    'type' => 'image'
                ];
                
                // Also mark thumbnail for deletion
                $thumbPath = generate_thumbnail_path($galleryDir.$file);
                if (is_file($thumbPath)) {
                    $filesToDelete[] = [
                        'file' => basename($thumbPath),
                        'reason' => 'Thumbnail of orphaned image',
                        'type' => 'thumbnail'
                    ];
                }
            }
        }
    }
    
    return ['files_to_delete' => $filesToDelete];
}

/**
 * Main processing function
 */
function process_images(string $action): void {
    global $GALLERY_MAX_WIDTH, $GALLERY_MAX_HEIGHT, $UPLOAD_MAX_WIDTH, $UPLOAD_MAX_HEIGHT;
    global $THUMBNAIL_MAX_WIDTH, $THUMBNAIL_MAX_HEIGHT;
    
    if ($action === 'optimize' || $action === 'both') {
        // Process gallery images
        $galleryDir = dirname(__DIR__).'/img/gallery/';
        if (is_dir($galleryDir)) {
            process_directory($galleryDir, $GALLERY_MAX_WIDTH, $GALLERY_MAX_HEIGHT, $THUMBNAIL_MAX_WIDTH, $THUMBNAIL_MAX_HEIGHT);
        }
        
        // Process upload images
        $uploadDir = dirname(__DIR__).'/img/upload/';
        if (is_dir($uploadDir)) {
            process_directory($uploadDir, $UPLOAD_MAX_WIDTH, $UPLOAD_MAX_HEIGHT, $THUMBNAIL_MAX_WIDTH, $THUMBNAIL_MAX_HEIGHT);
        }
    }
    
    if ($action === 'cleanup' || $action === 'both') {
        cleanup_orphaned_files();
    }
}

/**
 * Process all images in a directory
 */
function process_directory(string $dir, int $maxWidth, int $maxHeight, int $thumbMaxWidth, int $thumbMaxHeight): void {
    $files = scandir($dir) ?: [];
    
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        
        $filePath = $dir.$file;
        
        // Skip directories, JSON files, and existing thumbnails
        if (is_dir($filePath)) continue;
        if (pathinfo($file, PATHINFO_EXTENSION) === 'json') continue;
        if (strpos($file, '_thumb.') !== false) continue;
        
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) continue;
        
        // Resize full image if needed
        resize_image_max($filePath, $maxWidth, $maxHeight);
        
        // Generate thumbnail (only if needed)
        $thumbPath = generate_thumbnail_path($filePath);
        
        // Check if thumbnail needs to be generated/updated
        $needsThumbnail = true;
        if (is_file($thumbPath)) {
            // Get source image dimensions
            $imageInfo = @getimagesize($filePath);
            if ($imageInfo !== false) {
                $srcW = $imageInfo[0];
                $srcH = $imageInfo[1];
                
                // Calculate expected thumbnail dimensions
                $thumbScale = min($thumbMaxWidth / $srcW, $thumbMaxHeight / $srcH);
                $expectedThumbW = (int) floor($srcW * $thumbScale);
                $expectedThumbH = (int) floor($srcH * $thumbScale);
                
                // Get actual thumbnail dimensions
                $thumbInfo = @getimagesize($thumbPath);
                if ($thumbInfo !== false) {
                    $thumbW = $thumbInfo[0];
                    $thumbH = $thumbInfo[1];
                    
                    // Check if dimensions match (allow 1px tolerance)
                    $widthMatch = abs($thumbW - $expectedThumbW) <= 1;
                    $heightMatch = abs($thumbH - $expectedThumbH) <= 1;
                    
                    // Check if source is newer than thumbnail
                    $sourceMtime = filemtime($filePath);
                    $thumbMtime = filemtime($thumbPath);
                    $sourceIsNewer = $sourceMtime > $thumbMtime;
                    
                    // Only regenerate if dimensions don't match OR source is newer
                    if ($widthMatch && $heightMatch && !$sourceIsNewer) {
                        $needsThumbnail = false; // Thumbnail is up to date
                    }
                }
            }
        }
        
        if ($needsThumbnail) {
            generate_thumbnail($filePath, $thumbPath, $thumbMaxWidth, $thumbMaxHeight);
        }
    }
}

/**
 * Resize image to maximum dimensions, maintaining aspect ratio
 * Overwrites original if resizing is needed
 */
function resize_image_max(string $path, int $maxWidth, int $maxHeight): void {
    $src = image_create_from_any($path);
    if (!$src) return;
    
    $srcW = imagesx($src);
    $srcH = imagesy($src);
    
    // Check if resizing is needed
    if ($srcW <= $maxWidth && $srcH <= $maxHeight) {
        imagedestroy($src);
        return;
    }
    
    // Calculate new dimensions maintaining aspect ratio
    $scale = min($maxWidth / $srcW, $maxHeight / $srcH);
    $newW = (int) floor($srcW * $scale);
    $newH = (int) floor($srcH * $scale);
    
    // Create resized image
    $dst = imagecreatetruecolor($newW, $newH);
    
    // Preserve transparency for PNG
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if ($ext === 'png') {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 255, 255, 255, 127);
        imagefill($dst, 0, 0, $transparent);
    }
    
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $srcW, $srcH);
    
    // Save over original
    image_save_as($path, $dst);
    
    imagedestroy($src);
    imagedestroy($dst);
}

/**
 * Generate thumbnail from source image
 */
function generate_thumbnail(string $sourcePath, string $thumbPath, int $maxWidth, int $maxHeight): void {
    $src = image_create_from_any($sourcePath);
    if (!$src) return;
    
    $srcW = imagesx($src);
    $srcH = imagesy($src);
    
    // Calculate thumbnail dimensions maintaining aspect ratio
    $scale = min($maxWidth / $srcW, $maxHeight / $srcH);
    $newW = (int) floor($srcW * $scale);
    $newH = (int) floor($srcH * $scale);
    
    // Create thumbnail
    $dst = imagecreatetruecolor($newW, $newH);
    
    // Preserve transparency for PNG
    $ext = strtolower(pathinfo($thumbPath, PATHINFO_EXTENSION));
    if ($ext === 'png') {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 255, 255, 255, 127);
        imagefill($dst, 0, 0, $transparent);
    }
    
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $srcW, $srcH);
    
    // Save thumbnail
    image_save_as($thumbPath, $dst);
    
    imagedestroy($src);
    imagedestroy($dst);
}

/**
 * Generate thumbnail path from source path
 */
function generate_thumbnail_path(string $sourcePath): string {
    $pathInfo = pathinfo($sourcePath);
    $dir = $pathInfo['dirname'];
    $filename = $pathInfo['filename'];
    $ext = $pathInfo['extension'];
    return $dir.'/'.$filename.'_thumb.'.$ext;
}

/**
 * Clean up orphaned files in gallery
 */
function cleanup_orphaned_files(): void {
    $galleryDir = dirname(__DIR__).'/img/gallery/';
    $imagesDir = __DIR__.'/images/';
    
    if (!is_dir($galleryDir)) return;
    
    $galleryFiles = scandir($galleryDir) ?: [];
    $livePaintings = [];
    
    // First pass: identify live paintings from JSON files
    foreach ($galleryFiles as $file) {
        if ($file === '.' || $file === '..') continue;
        if (pathinfo($file, PATHINFO_EXTENSION) !== 'json') continue;
        
        $jsonPath = $galleryDir.$file;
        $jsonContent = file_get_contents($jsonPath);
        $meta = json_decode($jsonContent, true);
        
        if (is_array($meta) && isset($meta['original_filename'])) {
            $originalFilename = $meta['original_filename'];
            $base = pathinfo($file, PATHINFO_FILENAME);
            $livePaintings[$base] = $originalFilename;
        }
    }
    
    // Second pass: check each live painting and delete if orphaned
    foreach ($livePaintings as $galleryBase => $originalFilename) {
        // Check if corresponding admin file exists
        $adminFiles = scandir($imagesDir) ?: [];
        $hasAdminFile = false;
        
        foreach ($adminFiles as $adminFile) {
            if ($adminFile === '.' || $adminFile === '..') continue;
            // Check if file starts with original_filename
            if (strpos($adminFile, $originalFilename) === 0) {
                $hasAdminFile = true;
                break;
            }
        }
        
        // If no admin file exists, delete gallery entry
        if (!$hasAdminFile) {
            delete_gallery_entry($galleryDir, $galleryBase);
            continue;
        }
        
        // Check for missing artifacts (JSON without image, or image without JSON)
        $hasJson = is_file($galleryDir.$galleryBase.'.json');
        $hasImage = false;
        
        $imageExtensions = ['jpg', 'jpeg', 'png', 'webp'];
        foreach ($imageExtensions as $ext) {
            if (is_file($galleryDir.$galleryBase.'.'.$ext)) {
                $hasImage = true;
                break;
            }
        }
        
        // If JSON exists but no image, or image exists but no JSON, delete entry
        if (($hasJson && !$hasImage) || (!$hasJson && $hasImage)) {
            delete_gallery_entry($galleryDir, $galleryBase);
        }
    }
    
    // Third pass: find orphaned images without JSON files
    // Get all known gallery base names
    $knownBases = array_keys($livePaintings);
    
    $imageExtensions = ['jpg', 'jpeg', 'png', 'webp'];
    foreach ($galleryFiles as $file) {
        if ($file === '.' || $file === '..') continue;
        
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (!in_array($ext, $imageExtensions)) continue;
        
        // Skip thumbnails
        if (strpos($file, '_thumb.') !== false) continue;
        
        $fileStem = pathinfo($file, PATHINFO_FILENAME);
        
        // Check if this image belongs to a known painting (starts with known base)
        $belongsToKnownPainting = false;
        foreach ($knownBases as $galleryBase) {
            if (strpos($fileStem, $galleryBase) === 0) {
                $belongsToKnownPainting = true;
                break;
            }
        }
        
        // If it doesn't belong to a known painting, check if it has a JSON file
        if (!$belongsToKnownPainting && !is_file($galleryDir.$fileStem.'.json')) {
            // Extract base name (remove _variant_* suffix if present)
            $baseName = preg_replace('/_variant_.*$/', '', $fileStem);
            
            // Check if admin file exists for this base
            $adminFiles = scandir($imagesDir) ?: [];
            $hasAdminFile = false;
            
            foreach ($adminFiles as $adminFile) {
                if ($adminFile === '.' || $adminFile === '..') continue;
                if (strpos($adminFile, $baseName) === 0) {
                    $hasAdminFile = true;
                    break;
                }
            }
            
            // If no admin file, delete this orphaned image and its thumbnail
            if (!$hasAdminFile) {
                @unlink($galleryDir.$file);
                // Also delete thumbnail if it exists
                $thumbPath = generate_thumbnail_path($galleryDir.$file);
                if (is_file($thumbPath)) {
                    @unlink($thumbPath);
                }
            }
        }
    }
}

/**
 * Delete a gallery entry and all associated files (image, JSON, variants, thumbnails)
 */
function delete_gallery_entry(string $galleryDir, string $base): void {
    $files = scandir($galleryDir) ?: [];
    
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        
        $fileStem = pathinfo($file, PATHINFO_FILENAME);
        
        // Check if file belongs to this entry (starts with base name)
        if (strpos($fileStem, $base) === 0) {
            $filePath = $galleryDir.$file;
            if (is_file($filePath)) {
                @unlink($filePath);
            }
        }
    }
}

