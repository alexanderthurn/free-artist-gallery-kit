<?php
// Simple multi-file upload handler
// Saves originals to admin/original/ and a working copy to admin/images/

declare(strict_types=1);

require_once __DIR__.'/utils.php';

function ensure_dir(string $path): void {
    if (!is_dir($path)) {
        mkdir($path, 0775, true);
    }
}

function sanitize_filename(string $name): string {
    $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
    return trim($name ?? '', '_');
}

function unique_path(string $dir, string $filename): string {
    $path = rtrim($dir, '/').'/'.$filename;
    if (!file_exists($path)) return $path;
    $ext = pathinfo($filename, PATHINFO_EXTENSION);
    $base = pathinfo($filename, PATHINFO_FILENAME);
    $i = 1;
    do {
        $candidate = rtrim($dir, '/').'/'.$base.'-'.$i.($ext ? '.'.$ext : '');
        $i++;
    } while (file_exists($candidate));
    return $candidate;
}

function is_allowed_image(string $tmpFile, string $originalName): bool {
    $allowedExt = ['jpg','jpeg','png','webp'];
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) return false;
    $info = @getimagesize($tmpFile);
    if ($info === false) return false;
    return in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method not allowed';
    exit;
}

if (!isset($_FILES['images'])) {
    http_response_code(400);
    echo 'No files uploaded';
    exit;
}

$dirImages = __DIR__.'/images';
ensure_dir($dirImages);

$files = $_FILES['images'];
$count = is_array($files['name']) ? count($files['name']) : 0;
$uploaded = 0;
$errors = [];

for ($i = 0; $i < $count; $i++) {
    if ($files['error'][$i] !== UPLOAD_ERR_OK) {
        $errors[] = $files['name'][$i].' (upload error '.$files['error'][$i].')';
        continue;
    }
    $tmp = $files['tmp_name'][$i];
    $name = sanitize_filename($files['name'][$i]);
    if ($name === '') {
        $errors[] = 'Invalid filename';
        continue;
    }
    if (!is_allowed_image($tmp, $name)) {
        $errors[] = $name.' (unsupported format)';
        continue;
    }

    // Save into images/ with _original postfix as JPG
    $stem = pathinfo($name, PATHINFO_FILENAME);
    // Avoid double _original if already present
    if (!preg_match('/_original$/i', $stem)) {
        $stem .= '_original';
    }
    $destName = $stem.'.jpg';
    $target = unique_path($dirImages, $destName);
    
    // Convert image to JPG (handles PNG, WEBP, JPG)
    if (!convert_to_jpg($tmp, $target)) {
        $errors[] = $name.' (failed to convert/save)';
        continue;
    }

    // Also create _final.jpg copy (overwrite if exists)
    $finalStem = preg_replace('/_original$/i', '', $stem);
    $finalName = $finalStem.'_final.jpg';
    $finalTarget = $dirImages.'/'.$finalName;
    
    // Remove existing _final if it exists
    if (file_exists($finalTarget)) {
        @unlink($finalTarget);
    }
    
    // Copy the JPG to _final
    copy($target, $finalTarget);

    $uploaded++;
}

$query = http_build_query([
    'uploaded' => $uploaded,
    'errors' => $errors ? count($errors) : 0,
]);

header('Location: admin.html?'.$query);
exit;


