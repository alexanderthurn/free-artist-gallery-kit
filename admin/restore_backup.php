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
$indexPath = dirname(__DIR__) . '/index.html';

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

// Read backup content
$backupContent = file_get_contents($backupPath);
if ($backupContent === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to read backup']);
    exit;
}

// Read current index.html
$indexContent = file_get_contents($indexPath);
if ($indexContent === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to read index.html']);
    exit;
}

if ($isJson) {
    // Restore from JSON backup
    $backupData = json_decode($backupContent, true);
    if ($backupData === null || !is_array($backupData)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Invalid JSON backup format']);
        exit;
    }
    
    // Restore from JSON backup - update index.html with backup data
    // Update short content
    $patternShort = '/<div\s+class="author-short">(.*?)<\/div>\s*(?=<button|<div\s+class="author-content")/s';
    if (preg_match($patternShort, $indexContent)) {
        $indexContent = preg_replace($patternShort, '<div class="author-short">' . ($backupData['short'] ?? '') . '</div>', $indexContent, 1);
    }
    
    // Update bio content
    $patternBio = '/<div\s+class="author-bio">(.*?)<\/div>\s*(?=<)/s';
    if (preg_match($patternBio, $indexContent)) {
        $indexContent = preg_replace($patternBio, '<div class="author-bio">' . ($backupData['bio'] ?? '') . '</div>', $indexContent, 1);
    }
    
    // Update title
    if (!empty($backupData['pageTitle'])) {
        $patternTitle = '/<title>(.*?)<\/title>/s';
        $newTitle = htmlspecialchars($backupData['pageTitle'], ENT_QUOTES, 'UTF-8');
        $indexContent = preg_replace($patternTitle, '<title>' . $newTitle . '</title>', $indexContent, 1);
        
        // Also update page-title meta tag
        $patternPageTitle = '/<meta\s+name="page-title"\s+content="[^"]*"/i';
        $newPageTitleMeta = '<meta name="page-title" content="' . htmlspecialchars($backupData['pageTitle'], ENT_QUOTES, 'UTF-8') . '">';
        if (preg_match($patternPageTitle, $indexContent)) {
            $indexContent = preg_replace($patternPageTitle, '<meta name="page-title" content="' . htmlspecialchars($backupData['pageTitle'], ENT_QUOTES, 'UTF-8') . '"', $indexContent, 1);
        }
    } elseif (!empty($backupData['name'])) {
        // Fallback: use old format for backward compatibility
        $patternTitle = '/<title>(.*?)<\/title>/s';
        $newTitle = 'Herzfabrik - ' . htmlspecialchars($backupData['name'], ENT_QUOTES, 'UTF-8');
        $indexContent = preg_replace($patternTitle, '<title>' . $newTitle . '</title>', $indexContent, 1);
    }
    
    // Update meta tags (similar to save_artist.php logic)
    // Email
    if (isset($backupData['email'])) {
        $patternEmail = '/<meta\s+name="artist-email"\s+content="[^"]*"/i';
        $newEmailMeta = '<meta name="artist-email" content="' . htmlspecialchars($backupData['email'], ENT_QUOTES, 'UTF-8') . '">';
        if (preg_match($patternEmail, $indexContent)) {
            $indexContent = preg_replace($patternEmail, '<meta name="artist-email" content="' . htmlspecialchars($backupData['email'], ENT_QUOTES, 'UTF-8') . '"', $indexContent, 1);
        }
    }
    
    // Domain
    if (isset($backupData['domain'])) {
        $patternDomain = '/<meta\s+name="site-domain"\s+content="[^"]*"/i';
        if (preg_match($patternDomain, $indexContent)) {
            $indexContent = preg_replace($patternDomain, '<meta name="site-domain" content="' . htmlspecialchars($backupData['domain'], ENT_QUOTES, 'UTF-8') . '"', $indexContent, 1);
        }
    }
    
    // Imprint fields
    $imprintFields = [
        'imprint-address' => $backupData['imprintAddress'] ?? '',
        'imprint-postal-code' => $backupData['imprintPostalCode'] ?? '',
        'imprint-city' => $backupData['imprintCity'] ?? '',
        'imprint-phone' => $backupData['imprintPhone'] ?? ''
    ];
    
    foreach ($imprintFields as $metaName => $value) {
        $patternImprint = '/<meta\s+name="' . preg_quote($metaName, '/') . '"\s+content="[^"]*"\s*>/i';
        $newImprintMeta = '<meta name="' . $metaName . '" content="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '">';
        if (preg_match($patternImprint, $indexContent)) {
            $indexContent = preg_replace($patternImprint, $newImprintMeta, $indexContent, 1);
        }
    }
    
    // CSS variables
    if (isset($backupData['colorPrimary']) || isset($backupData['colorPrimaryHover']) || isset($backupData['colorContrast']) || isset($backupData['colorPrimaryRgb'])) {
        $cssVars = [];
        if (isset($backupData['colorPrimary'])) $cssVars[] = '  --color-primary: ' . $backupData['colorPrimary'] . ';';
        if (isset($backupData['colorPrimaryHover'])) $cssVars[] = '  --color-primary-hover: ' . $backupData['colorPrimaryHover'] . ';';
        if (isset($backupData['colorContrast'])) $cssVars[] = '  --color-contrast: ' . $backupData['colorContrast'] . ';';
        if (isset($backupData['colorPrimaryRgb'])) $cssVars[] = '  --color-primary-rgb: ' . $backupData['colorPrimaryRgb'] . ';';
        
        $newStyleContent = "<style id=\"custom-css-variables\">\n    :root {\n" . implode("\n", $cssVars) . "\n    }\n  </style>";
        $patternStyle = '/<style\s+id="custom-css-variables">.*?<\/style>/s';
        if (preg_match($patternStyle, $indexContent)) {
            $indexContent = preg_replace($patternStyle, $newStyleContent, $indexContent, 1);
        }
    }
    
    // Write updated index.html
    if (!file_put_contents($indexPath, $indexContent)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Failed to restore backup']);
        exit;
    }
} else {
    // Old HTML format - restore directly
    if (!file_put_contents($indexPath, $backupContent)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Failed to restore backup']);
        exit;
    }
}

echo json_encode([
    'ok' => true,
    'message' => 'Backup restored successfully'
]);

