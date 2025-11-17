<?php
declare(strict_types=1);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!isset($_POST['filename'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing filename']);
    exit;
}

$filename = basename($_POST['filename']);
$backupDir = __DIR__ . '/backups';
$backupPath = $backupDir . '/' . $filename;

// Validate filename (support both JSON and HTML backups)
$isJson = (strpos($filename, 'artist_') === 0 && pathinfo($filename, PATHINFO_EXTENSION) === 'json');
$isHtml = (strpos($filename, 'index_') === 0 && pathinfo($filename, PATHINFO_EXTENSION) === 'html');

if (!$isJson && !$isHtml) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid backup filename']);
    exit;
}

// Check if backup exists
if (!file_exists($backupPath)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Backup not found']);
    exit;
}

// Delete backup
if (!unlink($backupPath)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to delete backup']);
    exit;
}

echo json_encode([
    'ok' => true,
    'message' => 'Backup deleted successfully'
]);

