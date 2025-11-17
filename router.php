<?php
// Router for PHP built-in server
$requestUri = $_SERVER['REQUEST_URI'];
$parsedUrl = parse_url($requestUri);
$path = $parsedUrl['path'];

// Remove leading slash
$path = ltrim($path, '/');

// If accessing /admin without index.html, redirect to index.html (preserve query string)
if ($path === 'admin' || $path === 'admin/') {
    $queryString = isset($parsedUrl['query']) ? '?'.$parsedUrl['query'] : '';
    header('Location: /admin/index.html'.$queryString);
    exit;
}

// If file exists, serve it
if (file_exists(__DIR__ . '/' . $path)) {
    return false; // Let PHP serve the file
}

// If directory exists, try to serve index.html or index.php
if (is_dir(__DIR__ . '/' . $path)) {
    $indexFile = __DIR__ . '/' . $path . '/index.html';
    if (file_exists($indexFile)) {
        include $indexFile;
        exit;
    }
    $indexFile = __DIR__ . '/' . $path . '/index.php';
    if (file_exists($indexFile)) {
        include $indexFile;
        exit;
    }
}

// 404
http_response_code(404);
echo '404 Not Found';
exit;

