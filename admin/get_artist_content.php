<?php
declare(strict_types=1);

header('Content-Type: application/json');

$indexPath = dirname(__DIR__) . '/index.html';

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

// Extract author-short content
// Use a more robust pattern that handles nested divs
$patternShort = '/<div\s+class="author-short">(.*?)<\/div>\s*(?=<button|<div\s+class="author-content")/s';
$shortContent = '';
if (preg_match($patternShort, $indexContent, $matches)) {
    $shortContent = trim($matches[1]);
} else {
    // Fallback: match until closing div, but ensure we get the right one
    // Count opening and closing divs to find the matching closing tag
    $startPos = strpos($indexContent, '<div class="author-short">');
    if ($startPos !== false) {
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
        
        if ($endPos !== false) {
            $shortContent = trim(substr($indexContent, $startPos, $endPos - $startPos));
        }
    }
    
    // Final fallback to simple regex
    if (empty($shortContent)) {
        $patternShort = '/<div\s+class="author-short">(.*?)<\/div>/s';
        if (preg_match($patternShort, $indexContent, $matches)) {
            $shortContent = trim($matches[1]);
        }
    }
}

// Extract author-bio content
$patternBio = '/<div\s+class="author-bio">(.*?)<\/div>\s*(?=<)/s';
$bioContent = '';
if (preg_match($patternBio, $indexContent, $matches)) {
    $bioContent = trim($matches[1]);
} else {
    // Fallback without lookahead
    $patternBio = '/<div\s+class="author-bio">(.*?)<\/div>/s';
    if (preg_match($patternBio, $indexContent, $matches)) {
        $bioContent = trim($matches[1]);
    }
}

// Extract author-aktuelles content (now in author-aktuelles-wrapper with container and layout, outside header)
$patternAktuelles = '/<div\s+class="author-aktuelles-wrapper">\s*<div\s+class="container">\s*<div\s+class="author-aktuelles-layout">\s*<div\s+class="author-aktuelles-image-wrapper">.*?<\/div>\s*<div\s+class="author-aktuelles-text-wrapper">\s*<div\s+class="author-aktuelles">(.*?)<\/div>\s*<\/div>\s*<\/div>\s*<\/div>\s*<\/div>/s';
$aktuellesContent = '';
if (preg_match($patternAktuelles, $indexContent, $matches)) {
    $aktuellesContent = trim($matches[1]);
} else {
    // Fallback: try pattern without text-wrapper (for backwards compatibility)
    $patternAktuellesOld = '/<div\s+class="author-aktuelles-wrapper">\s*<div\s+class="container">\s*<div\s+class="author-aktuelles-layout">\s*<div\s+class="author-aktuelles-image-wrapper">.*?<\/div>\s*<div\s+class="author-aktuelles">(.*?)<\/div>\s*<\/div>\s*<\/div>\s*<\/div>/s';
    if (preg_match($patternAktuellesOld, $indexContent, $matches)) {
        $aktuellesContent = trim($matches[1]);
    } else {
        // Fallback: try pattern without layout wrapper (for backwards compatibility)
        $patternAktuellesSimple = '/<div\s+class="author-aktuelles-wrapper">\s*<div\s+class="container">\s*<div\s+class="author-aktuelles">(.*?)<\/div>\s*<\/div>\s*<\/div>/s';
        if (preg_match($patternAktuellesSimple, $indexContent, $matches)) {
            $aktuellesContent = trim($matches[1]);
        } else {
            // Final fallback: try to find author-aktuelles without wrapper
            $patternAktuelles = '/<div\s+class="author-aktuelles">(.*?)<\/div>/s';
            if (preg_match($patternAktuelles, $indexContent, $matches)) {
                $aktuellesContent = trim($matches[1]);
            }
        }
    }
}

// Extract page title from meta tag or title tag
$pageTitle = '';
$patternPageTitle = '/<meta\s+name="page-title"\s+content="([^"]+)"/i';
if (preg_match($patternPageTitle, $indexContent, $matches)) {
    $pageTitle = trim($matches[1]);
} else {
    // Fallback: get from title tag
    $patternTitle = '/<title>(.*?)<\/title>/s';
    if (preg_match($patternTitle, $indexContent, $matches)) {
        $pageTitle = trim($matches[1]);
    }
}

// Extract artist name from meta tag or title tag
$artistName = '';
$patternArtistName = '/<meta\s+name="artist-name"\s+content="([^"]+)"/i';
if (preg_match($patternArtistName, $indexContent, $matches)) {
    $artistName = trim($matches[1]);
} else {
    // Fallback: parse from title tag (format: "Herzfabrik - Annegret Thurn")
    $patternTitle = '/<title>(.*?)<\/title>/s';
    if (preg_match($patternTitle, $indexContent, $matches)) {
        $title = trim($matches[1]);
        if (preg_match('/Herzfabrik\s*-\s*(.+)/', $title, $titleMatches)) {
            $artistName = trim($titleMatches[1]);
        }
    }
}

// Extract email from meta tag
$artistEmail = '';
$patternEmail = '/<meta\s+name="artist-email"\s+content="([^"]+)"/i';
if (preg_match($patternEmail, $indexContent, $matches)) {
    $artistEmail = trim($matches[1]);
}

// Extract domain from meta tag
$siteDomain = '';
$patternDomain = '/<meta\s+name="site-domain"\s+content="([^"]+)"/i';
if (preg_match($patternDomain, $indexContent, $matches)) {
    $siteDomain = trim($matches[1]);
}

// Extract CSS variables from style tag
$colorPrimary = '#7A3A45';
$colorPrimaryHover = '#8B4A55';
$colorContrast = '#F3D9B1';
$colorPrimaryRgb = '122, 58, 69';

$patternStyle = '/<style\s+id="custom-css-variables">(.*?)<\/style>/s';
if (preg_match($patternStyle, $indexContent, $matches)) {
    $styleContent = $matches[1];
    
    // Extract --color-primary
    if (preg_match('/--color-primary:\s*([^;]+);/', $styleContent, $colorMatches)) {
        $colorPrimary = trim($colorMatches[1]);
    }
    
    // Extract --color-primary-hover
    if (preg_match('/--color-primary-hover:\s*([^;]+);/', $styleContent, $colorMatches)) {
        $colorPrimaryHover = trim($colorMatches[1]);
    }
    
    // Extract --color-contrast
    if (preg_match('/--color-contrast:\s*([^;]+);/', $styleContent, $colorMatches)) {
        $colorContrast = trim($colorMatches[1]);
    }
    
    // Extract --color-primary-rgb
    if (preg_match('/--color-primary-rgb:\s*([^;]+);/', $styleContent, $colorMatches)) {
        $colorPrimaryRgb = trim($colorMatches[1]);
    }
}

// Extract imprint information from meta tags
$imprintAddress = '';
$imprintPostalCode = '';
$imprintCity = '';
$imprintPhone = '';

$patternImprintAddress = '/<meta\s+name="imprint-address"\s+content="([^"]*)"/i';
if (preg_match($patternImprintAddress, $indexContent, $matches)) {
    $imprintAddress = trim($matches[1]);
}

$patternImprintPostalCode = '/<meta\s+name="imprint-postal-code"\s+content="([^"]*)"/i';
if (preg_match($patternImprintPostalCode, $indexContent, $matches)) {
    $imprintPostalCode = trim($matches[1]);
}

$patternImprintCity = '/<meta\s+name="imprint-city"\s+content="([^"]*)"/i';
if (preg_match($patternImprintCity, $indexContent, $matches)) {
    $imprintCity = trim($matches[1]);
}

$patternImprintPhone = '/<meta\s+name="imprint-phone"\s+content="([^"]*)"/i';
if (preg_match($patternImprintPhone, $indexContent, $matches)) {
    $imprintPhone = trim($matches[1]);
}

// Count available alternative portrait files in img/upload/ directory
$uploadDir = dirname(__DIR__) . '/img/upload/';
$alternativeImageCount = 0;
if (is_dir($uploadDir)) {
    // Check for artist-alternative-1.jpg, artist-alternative-2.jpg, etc. (up to 10)
    for ($i = 1; $i <= 10; $i++) {
        $filename = "artist-alternative-{$i}.jpg";
        $filePath = $uploadDir . $filename;
        if (file_exists($filePath)) {
            $alternativeImageCount = $i;
        } else {
            break; // Stop at first missing file
        }
    }
}

echo json_encode([
    'ok' => true,
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
    'colorPrimaryRgb' => $colorPrimaryRgb,
    'alternativeImageCount' => $alternativeImageCount,
    'short' => $shortContent,
    'bio' => $bioContent,
    'aktuelles' => $aktuellesContent
]);

