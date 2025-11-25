<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$jsonFilename = isset($_POST['filename']) ? basename((string)$_POST['filename']) : '';
$jsonContent = isset($_POST['content']) ? (string)$_POST['content'] : '';

if ($jsonFilename === '' || $jsonContent === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing filename or content']);
    exit;
}

// Ensure filename ends with .json
if (substr($jsonFilename, -5) !== '.json') {
    $jsonFilename .= '.json';
}

$imagesDir = __DIR__.'/images/';
$jsonPath = $imagesDir . $jsonFilename;

// Validate that the file exists
if (!is_file($jsonPath)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'JSON file not found']);
    exit;
}

// Validate JSON content
$decoded = json_decode($jsonContent, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON: ' . json_last_error_msg()]);
    exit;
}

// Save JSON file with pretty formatting
$formattedJson = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$ok = file_put_contents($jsonPath, $formattedJson, LOCK_EX) !== false;

if (!$ok) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to write JSON file']);
    exit;
}

echo json_encode(['ok' => true]);
exit;

