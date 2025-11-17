<?php
// Script to convert all PNG images in admin/images to JPG
// Run this once to convert existing PNG files

declare(strict_types=1);

require_once __DIR__.'/utils.php';

$imagesDir = __DIR__ . '/variants';
if (!is_dir($imagesDir)) {
    echo "Images directory not found.\n";
    exit(1);
}

$files = scandir($imagesDir) ?: [];
$converted = 0;
$errors = [];

foreach ($files as $file) {
    if ($file === '.' || $file === '..') continue;
    
    $path = $imagesDir . '/' . $file;
    if (!is_file($path)) continue;
    
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if ($ext !== 'png') continue;
    
    // Generate JPG filename
    $stem = pathinfo($file, PATHINFO_FILENAME);
    $jpgPath = $imagesDir . '/' . $stem . '.jpg';
    
    // Convert PNG to JPG using Imagick or GD
    if (!convert_to_jpg($path, $jpgPath)) {
        $errors[] = $file . ' (failed to convert)';
        continue;
    }
    
    // Delete original PNG
    if (!unlink($path)) {
        $errors[] = $file . ' (failed to delete PNG)';
        continue;
    }
    
    $converted++;
    echo "Converted: $file -> $stem.jpg\n";
}

echo "\nConversion complete:\n";
echo "  Converted: $converted\n";
if (count($errors) > 0) {
    echo "  Errors: " . count($errors) . "\n";
    foreach ($errors as $error) {
        echo "    - $error\n";
    }
}

