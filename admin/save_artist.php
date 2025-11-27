<?php
declare(strict_types=1);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!isset($_POST['short']) || !isset($_POST['bio'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing required fields']);
    exit;
}

$shortContent = $_POST['short'];
$bioContent = $_POST['bio'];
$pageTitle = isset($_POST['pageTitle']) ? trim($_POST['pageTitle']) : '';
$artistName = isset($_POST['name']) ? trim($_POST['name']) : '';
$artistEmail = isset($_POST['email']) ? trim($_POST['email']) : '';
$siteDomain = isset($_POST['domain']) ? trim($_POST['domain']) : '';
$imprintAddress = isset($_POST['imprintAddress']) ? trim($_POST['imprintAddress']) : '';
$imprintPostalCode = isset($_POST['imprintPostalCode']) ? trim($_POST['imprintPostalCode']) : '';
$imprintCity = isset($_POST['imprintCity']) ? trim($_POST['imprintCity']) : '';
$imprintPhone = isset($_POST['imprintPhone']) ? trim($_POST['imprintPhone']) : '';
$colorPrimary = isset($_POST['colorPrimary']) ? trim($_POST['colorPrimary']) : '';
$colorPrimaryHover = isset($_POST['colorPrimaryHover']) ? trim($_POST['colorPrimaryHover']) : '';
$colorContrast = isset($_POST['colorContrast']) ? trim($_POST['colorContrast']) : '';
$colorPrimaryRgb = isset($_POST['colorPrimaryRgb']) ? trim($_POST['colorPrimaryRgb']) : '';

$indexPath = dirname(__DIR__) . '/index.html';
$backupDir = __DIR__ . '/backups';

// Read current index.html first (needed for update)
if (!file_exists($indexPath)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'index.html not found']);
    exit;
}

$indexContent = file_get_contents($indexPath);
if ($indexContent === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to read index.html']);
    exit;
}

// Prepare current data for comparison (before updating index.html)
$currentData = [
    'short' => trim($shortContent),
    'bio' => trim($bioContent),
    'pageTitle' => $pageTitle,
    'name' => $artistName,
    'email' => $artistEmail,
    'domain' => $siteDomain,
    'imprintAddress' => $imprintAddress,
    'imprintPostalCode' => $imprintPostalCode,
    'imprintCity' => $imprintCity,
    'imprintPhone' => $imprintPhone,
    'colorPrimary' => $colorPrimary,
    'colorPrimaryHover' => $colorPrimaryHover,
    'colorContrast' => $colorContrast,
    'colorPrimaryRgb' => $colorPrimaryRgb
];

// Check if content is identical to last backup (before updating index.html)
$shouldCreateBackup = true;
$backupFiles = [];
if (is_dir($backupDir)) {
    $files = scandir($backupDir) ?: [];
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        // Support both old HTML backups and new JSON backups
        if ((strpos($file, 'artist_') === 0 && pathinfo($file, PATHINFO_EXTENSION) === 'json') ||
            (strpos($file, 'index_') === 0 && pathinfo($file, PATHINFO_EXTENSION) === 'html')) {
            $filePath = $backupDir . '/' . $file;
            $backupFiles[] = [
                'filename' => $file,
                'path' => $filePath,
                'mtime' => filemtime($filePath)
            ];
        }
    }
}

// Sort by modification time (newest first)
usort($backupFiles, function($a, $b) {
    return $b['mtime'] - $a['mtime'];
});

// If there's a backup, compare content
if (!empty($backupFiles)) {
    $lastBackupPath = $backupFiles[0]['path'];
    $lastBackupExt = pathinfo($lastBackupPath, PATHINFO_EXTENSION);
    
    if ($lastBackupExt === 'json') {
        // New JSON format
        $backupContent = file_get_contents($lastBackupPath);
        if ($backupContent !== false) {
            $backupData = json_decode($backupContent, true);
            if ($backupData !== null && is_array($backupData)) {
                // Compare all fields
                $isUnchanged = true;
                foreach ($currentData as $key => $value) {
                    $backupValue = isset($backupData[$key]) ? $backupData[$key] : '';
                    // Normalize comparison
                    if (trim($value) !== trim($backupValue)) {
                        $isUnchanged = false;
                        break;
                    }
                }
                
                if ($isUnchanged) {
                    $shouldCreateBackup = false;
                }
            }
        }
    }
}

// Replace content in index.html
// Find author-short div - use a pattern that stops before the button
$patternShort = '/<div\s+class="author-short">(.*?)<\/div>\s*(?=<button|<div\s+class="author-content")/s';
if (!preg_match($patternShort, $indexContent, $matches)) {
    // Fallback: find the div by counting nested divs
    $startPos = strpos($indexContent, '<div class="author-short">');
    if ($startPos === false) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'author-short div not found in index.html']);
        exit;
    }
    
    $divStart = $startPos;
    $startPos += strlen('<div class="author-short">');
    $depth = 1;
    $pos = $startPos;
    $endPos = false;
    
    while ($pos < strlen($indexContent) && $depth > 0) {
        $nextOpen = strpos($indexContent, '<div', $pos);
        $nextClose = strpos($indexContent, '</div>', $pos);
        
        if ($nextClose === false) break;
        
        if ($nextOpen !== false && $nextOpen < $nextClose) {
            $depth++;
            $pos = $nextOpen + 4;
        } else {
            $depth--;
            if ($depth === 0) {
                $endPos = $nextClose;
                break;
            }
            $pos = $nextClose + 6;
        }
    }
    
    if ($endPos === false) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Could not find closing tag for author-short']);
        exit;
    }
    
    // Replace the entire div
    $indexContent = substr_replace($indexContent, '<div class="author-short">' . $shortContent . '</div>', $divStart, $endPos + 6 - $divStart);
} else {
    // Replace content inside author-short using regex
    $indexContent = preg_replace(
        $patternShort,
        '<div class="author-short">' . $shortContent . '</div>',
        $indexContent,
        1
    );
}

// Find author-bio div (non-greedy match to stop at closing div)
$patternBio = '/<div\s+class="author-bio">(.*?)<\/div>\s*(?=<)/s';
if (!preg_match($patternBio, $indexContent, $matches)) {
    // Try without lookahead as fallback
    $patternBio = '/<div\s+class="author-bio">(.*?)<\/div>/s';
    if (!preg_match($patternBio, $indexContent, $matches)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'author-bio div not found in index.html']);
        exit;
    }
}

// Replace content inside author-bio
$indexContent = preg_replace(
    $patternBio,
    '<div class="author-bio">' . $bioContent . '</div>',
    $indexContent,
    1
);

// Update page title tag
if (!empty($pageTitle)) {
    $patternTitle = '/<title>(.*?)<\/title>/s';
    $newTitle = htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8');
    if (preg_match($patternTitle, $indexContent)) {
        $indexContent = preg_replace($patternTitle, '<title>' . $newTitle . '</title>', $indexContent, 1);
    } else {
        // If title tag doesn't exist, add it in head section
        $indexContent = preg_replace('/(<head[^>]*>)/i', '$1' . "\n  <title>" . $newTitle . "</title>", $indexContent, 1);
    }
    
    // Also add page-title meta tag
    $patternPageTitle = '/<meta\s+name="page-title"\s+content="[^"]*"/i';
    $newPageTitleMeta = '<meta name="page-title" content="' . htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') . '">';
    if (preg_match($patternPageTitle, $indexContent)) {
        $indexContent = preg_replace($patternPageTitle, '<meta name="page-title" content="' . htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') . '"', $indexContent, 1);
    } else {
        // Add after viewport meta tag
        $indexContent = preg_replace('/(<meta\s+name="viewport"[^>]*>)/i', '$1' . "\n  " . $newPageTitleMeta, $indexContent, 1);
    }
}

// Update artist name (for imprint pages)
if (!empty($artistName)) {
    // Also add artist-name meta tag for imprint pages
    $patternArtistName = '/<meta\s+name="artist-name"\s+content="[^"]*"/i';
    $newArtistNameMeta = '<meta name="artist-name" content="' . htmlspecialchars($artistName, ENT_QUOTES, 'UTF-8') . '">';
    if (preg_match($patternArtistName, $indexContent)) {
        $indexContent = preg_replace($patternArtistName, '<meta name="artist-name" content="' . htmlspecialchars($artistName, ENT_QUOTES, 'UTF-8') . '"', $indexContent, 1);
    } else {
        // Add after email meta tag
        $indexContent = preg_replace('/(<meta\s+name="artist-email"[^>]*>)/i', '$1' . "\n  " . $newArtistNameMeta, $indexContent, 1);
    }
}

// Update or create email meta tag
if (!empty($artistEmail)) {
    $patternEmail = '/<meta\s+name="artist-email"\s+content="[^"]*"/i';
    $newEmailMeta = '<meta name="artist-email" content="' . htmlspecialchars($artistEmail, ENT_QUOTES, 'UTF-8') . '">';
    if (preg_match($patternEmail, $indexContent)) {
        // Update existing meta tag
        $indexContent = preg_replace($patternEmail, '<meta name="artist-email" content="' . htmlspecialchars($artistEmail, ENT_QUOTES, 'UTF-8') . '"', $indexContent, 1);
    } else {
        // Add new meta tag after viewport meta tag
        $indexContent = preg_replace('/(<meta\s+name="viewport"[^>]*>)/i', '$1' . "\n  " . $newEmailMeta, $indexContent, 1);
    }
}

// Update or create domain meta tag
if (!empty($siteDomain)) {
    $patternDomain = '/<meta\s+name="site-domain"\s+content="[^"]*"/i';
    $newDomainMeta = '<meta name="site-domain" content="' . htmlspecialchars($siteDomain, ENT_QUOTES, 'UTF-8') . '">';
    if (preg_match($patternDomain, $indexContent)) {
        // Update existing meta tag
        $indexContent = preg_replace($patternDomain, '<meta name="site-domain" content="' . htmlspecialchars($siteDomain, ENT_QUOTES, 'UTF-8') . '"', $indexContent, 1);
    } else {
        // Add new meta tag after email meta tag
        $indexContent = preg_replace('/(<meta\s+name="artist-email"[^>]*>)/i', '$1' . "\n  " . $newDomainMeta, $indexContent, 1);
    }
}

// Update or create imprint meta tags (always update, even if empty)
// Process in order: address, postal-code, city, phone
$imprintFields = [
    'imprint-address' => $imprintAddress,
    'imprint-postal-code' => $imprintPostalCode,
    'imprint-city' => $imprintCity,
    'imprint-phone' => $imprintPhone
];

$lastInsertedMeta = null;

foreach ($imprintFields as $metaName => $value) {
    // Escape value for use in regex replacement
    $escapedValue = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    // Pattern to check if meta tag exists (matches content="anything" or content="")
    $patternImprint = '/<meta\s+name="' . preg_quote($metaName, '/') . '"\s+content="[^"]*"\s*>/i';
    $newImprintMeta = '<meta name="' . $metaName . '" content="' . $escapedValue . '">';
    
    if (preg_match($patternImprint, $indexContent)) {
        // Update existing meta tag - match the full tag including closing >
        $patternImprintFull = '/<meta\s+name="' . preg_quote($metaName, '/') . '"\s+content="[^"]*"\s*>/i';
        $indexContent = preg_replace($patternImprintFull, $newImprintMeta, $indexContent, 1);
        $lastInsertedMeta = $newImprintMeta;
    } else {
        // Add new meta tag - find insertion point
        $insertAfterPattern = '';
        
        // If we just inserted a meta tag, insert after it
        if ($lastInsertedMeta && preg_match('/' . preg_quote($lastInsertedMeta, '/') . '/', $indexContent)) {
            $insertAfterPattern = '/' . preg_quote($lastInsertedMeta, '/') . '/';
        } else {
            // Check if any existing imprint meta tag exists (in order)
            foreach ($imprintFields as $checkName => $checkValue) {
                if ($checkName === $metaName) break; // Don't check ourselves
                $checkPattern = '/<meta\s+name="' . preg_quote($checkName, '/') . '"[^>]*>/i';
                if (preg_match($checkPattern, $indexContent, $lastMatch)) {
                    $insertAfterPattern = '/' . preg_quote($lastMatch[0], '/') . '/';
                    break;
                }
            }
        }
        
        // If no imprint meta tag found, add after domain or email
        if (!$insertAfterPattern) {
            if (preg_match('/(<meta\s+name="site-domain"[^>]*>)/i', $indexContent)) {
                $insertAfterPattern = '/(<meta\s+name="site-domain"[^>]*>)/i';
            } elseif (preg_match('/(<meta\s+name="artist-email"[^>]*>)/i', $indexContent)) {
                $insertAfterPattern = '/(<meta\s+name="artist-email"[^>]*>)/i';
            } else {
                // Fallback: add after viewport meta tag
                $insertAfterPattern = '/(<meta\s+name="viewport"[^>]*>)/i';
            }
        }
        
        if ($insertAfterPattern) {
            $indexContent = preg_replace($insertAfterPattern, '$1' . "\n  " . $newImprintMeta, $indexContent, 1);
            $lastInsertedMeta = $newImprintMeta;
        }
    }
}

// Update CSS variables in style tag
if (!empty($colorPrimary) || !empty($colorPrimaryHover) || !empty($colorContrast) || !empty($colorPrimaryRgb)) {
    $patternStyle = '/<style\s+id="custom-css-variables">.*?<\/style>/s';
    
    // Use provided values or keep defaults
    $finalColorPrimary = !empty($colorPrimary) ? htmlspecialchars($colorPrimary, ENT_QUOTES, 'UTF-8') : '#7A3A45';
    $finalColorPrimaryHover = !empty($colorPrimaryHover) ? htmlspecialchars($colorPrimaryHover, ENT_QUOTES, 'UTF-8') : '#8B4A55';
    $finalColorContrast = !empty($colorContrast) ? htmlspecialchars($colorContrast, ENT_QUOTES, 'UTF-8') : '#F3D9B1';
    $finalColorPrimaryRgb = !empty($colorPrimaryRgb) ? htmlspecialchars($colorPrimaryRgb, ENT_QUOTES, 'UTF-8') : '122, 58, 69';
    
    $newStyleTag = '<style id="custom-css-variables">' . "\n    :root {\n      --color-primary: " . $finalColorPrimary . ";\n      --color-primary-hover: " . $finalColorPrimaryHover . ";\n      --color-contrast: " . $finalColorContrast . ";\n      --color-primary-rgb: " . $finalColorPrimaryRgb . ";\n    }\n  </style>";
    
    if (preg_match($patternStyle, $indexContent)) {
        // Update existing style tag
        $indexContent = preg_replace($patternStyle, $newStyleTag, $indexContent, 1);
    } else {
        // Add new style tag after styles.css link
        $indexContent = preg_replace('/(<link\s+rel="stylesheet"\s+href="styles\.css">)/i', '$1' . "\n  " . $newStyleTag, $indexContent, 1);
    }
}

// Helper function to generate URL from domain (adds https:// if not present)
function generateUrl($domain, $path = '/') {
    if (empty($domain)) {
        return '';
    }
    $domain = trim($domain);
    // Remove trailing slash from domain
    $domain = rtrim($domain, '/');
    // Add https:// if not present
    if (strpos($domain, 'http://') !== 0 && strpos($domain, 'https://') !== 0) {
        $domain = 'https://' . $domain;
    }
    return $domain . $path;
}

// Helper function to strip HTML tags and limit length
function stripHtmlAndLimit($html, $maxLength = 160) {
    // Remove HTML tags
    $text = strip_tags($html);
    // Decode HTML entities
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // Remove extra whitespace
    $text = preg_replace('/\s+/', ' ', $text);
    $text = trim($text);
    // Limit length
    if (mb_strlen($text) > $maxLength) {
        $text = mb_substr($text, 0, $maxLength - 3) . '...';
    }
    return $text;
}

// Generate SEO and social media meta tags from existing fields
if (!empty($siteDomain)) {
    $baseUrl = generateUrl($siteDomain, '/');
    $imageUrl = generateUrl($siteDomain, '/img/upload/artist.jpg');
    
    // Generate description from author-short (strip HTML, limit to 160 chars)
    $metaDescription = '';
    if (!empty($shortContent)) {
        $metaDescription = stripHtmlAndLimit($shortContent, 160);
    }
    
    // Update or create description meta tag
    if (!empty($metaDescription)) {
        $patternDescription = '/<meta\s+name="description"\s+content="[^"]*"/i';
        $newDescriptionMeta = '<meta name="description" content="' . htmlspecialchars($metaDescription, ENT_QUOTES, 'UTF-8') . '">';
        if (preg_match($patternDescription, $indexContent)) {
            $indexContent = preg_replace($patternDescription, '<meta name="description" content="' . htmlspecialchars($metaDescription, ENT_QUOTES, 'UTF-8') . '"', $indexContent, 1);
        } else {
            // Add after page-title meta tag or after viewport
            if (preg_match('/(<meta\s+name="page-title"[^>]*>)/i', $indexContent)) {
                $indexContent = preg_replace('/(<meta\s+name="page-title"[^>]*>)/i', '$1' . "\n  " . $newDescriptionMeta, $indexContent, 1);
            } else {
                $indexContent = preg_replace('/(<meta\s+name="viewport"[^>]*>)/i', '$1' . "\n  " . $newDescriptionMeta, $indexContent, 1);
            }
        }
    }
    
    // Update or create canonical link
    $patternCanonical = '/<link\s+rel="canonical"\s+href="[^"]*"/i';
    $newCanonicalLink = '<link rel="canonical" href="' . htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') . '">';
    if (preg_match($patternCanonical, $indexContent)) {
        $indexContent = preg_replace($patternCanonical, '<link rel="canonical" href="' . htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') . '"', $indexContent, 1);
    } else {
        // Add after description meta tag or after page-title
        if (preg_match('/(<meta\s+name="description"[^>]*>)/i', $indexContent)) {
            $indexContent = preg_replace('/(<meta\s+name="description"[^>]*>)/i', '$1' . "\n  " . $newCanonicalLink, $indexContent, 1);
        } elseif (preg_match('/(<meta\s+name="page-title"[^>]*>)/i', $indexContent)) {
            $indexContent = preg_replace('/(<meta\s+name="page-title"[^>]*>)/i', '$1' . "\n  " . $newCanonicalLink, $indexContent, 1);
        } else {
            $indexContent = preg_replace('/(<meta\s+name="viewport"[^>]*>)/i', '$1' . "\n  " . $newCanonicalLink, $indexContent, 1);
        }
    }
    
    // Open Graph meta tags
    $ogTags = [];
    
    if (!empty($pageTitle)) {
        $ogTags['og:title'] = $pageTitle;
    }
    
    if (!empty($metaDescription)) {
        $ogTags['og:description'] = $metaDescription;
    }
    
    if (!empty($baseUrl)) {
        $ogTags['og:url'] = $baseUrl;
    }
    
    if (!empty($imageUrl)) {
        $ogTags['og:image'] = $imageUrl;
    }
    
    // og:site_name from domain (without https://)
    $siteName = $siteDomain;
    if (strpos($siteName, 'https://') === 0) {
        $siteName = substr($siteName, 8);
    } elseif (strpos($siteName, 'http://') === 0) {
        $siteName = substr($siteName, 7);
    }
    $siteName = rtrim($siteName, '/');
    $ogTags['og:site_name'] = $siteName;
    
    $ogTags['og:locale'] = 'de_DE';
    $ogTags['og:type'] = 'website';
    
    // Update or create Open Graph meta tags
    foreach ($ogTags as $property => $content) {
        $patternOg = '/<meta\s+property="' . preg_quote($property, '/') . '"\s+content="[^"]*"/i';
        $newOgMeta = '<meta property="' . htmlspecialchars($property, ENT_QUOTES, 'UTF-8') . '" content="' . htmlspecialchars($content, ENT_QUOTES, 'UTF-8') . '">';
        if (preg_match($patternOg, $indexContent)) {
            $indexContent = preg_replace($patternOg, '<meta property="' . htmlspecialchars($property, ENT_QUOTES, 'UTF-8') . '" content="' . htmlspecialchars($content, ENT_QUOTES, 'UTF-8') . '"', $indexContent, 1);
        } else {
            // Add after canonical link or after description
            if (preg_match('/(<link\s+rel="canonical"[^>]*>)/i', $indexContent)) {
                $indexContent = preg_replace('/(<link\s+rel="canonical"[^>]*>)/i', '$1' . "\n  " . $newOgMeta, $indexContent, 1);
            } elseif (preg_match('/(<meta\s+name="description"[^>]*>)/i', $indexContent)) {
                $indexContent = preg_replace('/(<meta\s+name="description"[^>]*>)/i', '$1' . "\n  " . $newOgMeta, $indexContent, 1);
            } else {
                $indexContent = preg_replace('/(<meta\s+name="viewport"[^>]*>)/i', '$1' . "\n  " . $newOgMeta, $indexContent, 1);
            }
        }
    }
    
    // Twitter Card meta tags
    $twitterTags = [];
    
    if (!empty($pageTitle)) {
        $twitterTags['twitter:title'] = $pageTitle;
    }
    
    if (!empty($metaDescription)) {
        $twitterTags['twitter:description'] = $metaDescription;
    }
    
    if (!empty($baseUrl)) {
        $twitterTags['twitter:url'] = $baseUrl;
    }
    
    if (!empty($imageUrl)) {
        $twitterTags['twitter:image'] = $imageUrl;
    }
    
    $twitterTags['twitter:card'] = 'summary_large_image';
    
    // Update or create Twitter Card meta tags
    foreach ($twitterTags as $name => $content) {
        $patternTwitter = '/<meta\s+name="' . preg_quote($name, '/') . '"\s+content="[^"]*"/i';
        $newTwitterMeta = '<meta name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '" content="' . htmlspecialchars($content, ENT_QUOTES, 'UTF-8') . '">';
        if (preg_match($patternTwitter, $indexContent)) {
            $indexContent = preg_replace($patternTwitter, '<meta name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '" content="' . htmlspecialchars($content, ENT_QUOTES, 'UTF-8') . '"', $indexContent, 1);
        } else {
            // Add after last og: tag or after canonical
            if (preg_match('/(<meta\s+property="og:locale"[^>]*>)/i', $indexContent)) {
                $indexContent = preg_replace('/(<meta\s+property="og:locale"[^>]*>)/i', '$1' . "\n  " . $newTwitterMeta, $indexContent, 1);
            } elseif (preg_match('/(<link\s+rel="canonical"[^>]*>)/i', $indexContent)) {
                $indexContent = preg_replace('/(<link\s+rel="canonical"[^>]*>)/i', '$1' . "\n  " . $newTwitterMeta, $indexContent, 1);
            } else {
                $indexContent = preg_replace('/(<meta\s+name="viewport"[^>]*>)/i', '$1' . "\n  " . $newTwitterMeta, $indexContent, 1);
            }
        }
    }
}

// Write updated content back to index.html (ALWAYS, even if no backup is created)
if (!file_put_contents($indexPath, $indexContent)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to write index.html']);
    exit;
}

// Create backup only if content has changed
$backupFilename = null;
if ($shouldCreateBackup) {
    // Ensure backup directory exists
    if (!is_dir($backupDir)) {
        if (!mkdir($backupDir, 0755, true)) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Failed to create backup directory']);
            exit;
        }
    }
    
    // Create JSON backup
    $timestamp = time();
    $backupFilename = 'artist_' . $timestamp . '.json';
    $backupPath = $backupDir . '/' . $backupFilename;
    
    // Create backup data structure
    $backupData = [
        'timestamp' => $timestamp,
        'short' => trim($shortContent),
        'bio' => trim($bioContent),
        'pageTitle' => $pageTitle,
        'name' => $artistName,
        'email' => $artistEmail,
        'domain' => $siteDomain,
        'imprintAddress' => $imprintAddress,
        'imprintPostalCode' => $imprintPostalCode,
        'imprintCity' => $imprintCity,
        'imprintPhone' => $imprintPhone,
        'colorPrimary' => $colorPrimary,
        'colorPrimaryHover' => $colorPrimaryHover,
        'colorContrast' => $colorContrast,
        'colorPrimaryRgb' => $colorPrimaryRgb
    ];
    
    $backupJson = json_encode($backupData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    
    if (!file_put_contents($backupPath, $backupJson)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Failed to create backup']);
        exit;
    }
    
    // Clean up old backups (keep only 100 most recent JSON backups)
    $backupFilesToClean = [];
    if (is_dir($backupDir)) {
        $files = scandir($backupDir) ?: [];
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            if (strpos($file, 'artist_') === 0 && pathinfo($file, PATHINFO_EXTENSION) === 'json') {
                $filePath = $backupDir . '/' . $file;
                $backupFilesToClean[] = [
                    'filename' => $file,
                    'path' => $filePath,
                    'mtime' => filemtime($filePath)
                ];
            }
        }
    }
    
    // Sort by modification time (newest first)
    usort($backupFilesToClean, function($a, $b) {
        return $b['mtime'] - $a['mtime'];
    });
    
    // Remove backups beyond the 100 limit
    if (count($backupFilesToClean) > 100) {
        $toRemove = array_slice($backupFilesToClean, 100);
        foreach ($toRemove as $backup) {
            @unlink($backup['path']);
        }
    }
}

// Also update meta tags in imprint.html and dataprivacy.html
$imprintPath = dirname(__DIR__) . '/imprint.html';
$dataprivacyPath = dirname(__DIR__) . '/dataprivacy.html';

foreach ([$imprintPath, $dataprivacyPath] as $filePath) {
    if (file_exists($filePath)) {
        $fileContent = file_get_contents($filePath);
        if ($fileContent !== false) {
            // Update or create email meta tag
            $patternEmail = '/<meta\s+name="artist-email"\s+content="[^"]*"/i';
            $newEmailMeta = '<meta name="artist-email" content="' . htmlspecialchars($artistEmail, ENT_QUOTES, 'UTF-8') . '">';
            if (preg_match($patternEmail, $fileContent)) {
                $fileContent = preg_replace($patternEmail, '<meta name="artist-email" content="' . htmlspecialchars($artistEmail, ENT_QUOTES, 'UTF-8') . '"', $fileContent, 1);
            } else {
                // Add after viewport meta tag
                $fileContent = preg_replace('/(<meta\s+name="viewport"[^>]*>)/i', '$1' . "\n  " . $newEmailMeta, $fileContent, 1);
            }
            
            // Update or create artist-name meta tag
            if (!empty($artistName)) {
                $patternArtistName = '/<meta\s+name="artist-name"\s+content="[^"]*"/i';
                $newArtistNameMeta = '<meta name="artist-name" content="' . htmlspecialchars($artistName, ENT_QUOTES, 'UTF-8') . '">';
                if (preg_match($patternArtistName, $fileContent)) {
                    $fileContent = preg_replace($patternArtistName, '<meta name="artist-name" content="' . htmlspecialchars($artistName, ENT_QUOTES, 'UTF-8') . '"', $fileContent, 1);
                } else {
                    // Add after email meta tag
                    $fileContent = preg_replace('/(<meta\s+name="artist-email"[^>]*>)/i', '$1' . "\n  " . $newArtistNameMeta, $fileContent, 1);
                }
            }
            
            // Update or create domain meta tag
            $patternDomain = '/<meta\s+name="site-domain"\s+content="[^"]*"/i';
            $newDomainMeta = '<meta name="site-domain" content="' . htmlspecialchars($siteDomain, ENT_QUOTES, 'UTF-8') . '">';
            if (preg_match($patternDomain, $fileContent)) {
                $fileContent = preg_replace($patternDomain, '<meta name="site-domain" content="' . htmlspecialchars($siteDomain, ENT_QUOTES, 'UTF-8') . '"', $fileContent, 1);
            } else {
                // Add after email meta tag
                $fileContent = preg_replace('/(<meta\s+name="artist-email"[^>]*>)/i', '$1' . "\n  " . $newDomainMeta, $fileContent, 1);
            }
            
            // Update or create imprint meta tags
            foreach ($imprintFields as $metaName => $value) {
                $patternImprint = '/<meta\s+name="' . preg_quote($metaName, '/') . '"\s+content="[^"]*"\s*>/i';
                $newImprintMeta = '<meta name="' . $metaName . '" content="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '">';
                if (preg_match($patternImprint, $fileContent)) {
                    $fileContent = preg_replace($patternImprint, $newImprintMeta, $fileContent, 1);
                } else {
                    // Add after domain meta tag
                    $fileContent = preg_replace('/(<meta\s+name="site-domain"[^>]*>)/i', '$1' . "\n  " . $newImprintMeta, $fileContent, 1);
                }
            }
            
            file_put_contents($filePath, $fileContent);
        }
    }
}

if ($shouldCreateBackup) {
    echo json_encode([
        'ok' => true,
        'backup' => $backupFilename,
        'message' => 'Content saved successfully'
    ]);
} else {
    echo json_encode([
        'ok' => true,
        'unchanged' => true,
        'message' => 'Keine Änderungen erkannt. Inhalt ist identisch zum letzten Backup. index.html wurde trotzdem aktualisiert.'
    ]);
}

