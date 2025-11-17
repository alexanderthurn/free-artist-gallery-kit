<?php
declare(strict_types=1);

require __DIR__.'/utils.php';

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

$imagesDir = __DIR__.'/images/';
$imagePath = $imagesDir.$image;

if (!is_file($imagePath)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Image not found']);
    exit;
}

// Extract base name to find _final image
$base = extract_base_name($image);

// Find _final image
$finalImage = null;
$files = scandir($imagesDir) ?: [];
foreach ($files as $file) {
    if ($file === '.' || $file === '..') continue;
    $fileStem = pathinfo($file, PATHINFO_FILENAME);
    if (strpos($fileStem, $base.'_final') === 0) {
        $finalImage = $file;
        break;
    }
}

// If no _final found, try other extensions
if (!$finalImage) {
    $extensions = ['png', 'jpg', 'jpeg', 'webp'];
    foreach ($extensions as $e) {
        $testFile = $base.'_final.'.$e;
        if (is_file($imagesDir.$testFile)) {
            $finalImage = $testFile;
            break;
        }
    }
}

// Fallback to original if no final found
if (!$finalImage) {
    $finalImage = $image;
}

$finalPath = $imagesDir.$finalImage;

if (!is_file($finalPath)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Final image not found']);
    exit;
}

// Log the image path being used
error_log('AI Fill Form: Using image: ' . $finalPath);
error_log('AI Fill Form: Image filename: ' . $finalImage);

try {
    $token = load_replicate_token();
    
    // Get image info and encode to base64 (exactly like corners.php)
    [$imgW, $imgH] = getimagesize($finalPath);
    $mime = mime_content_type($finalPath);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'unsupported image type', 'mime' => $mime]);
        exit;
    }
    $imgB64 = base64_encode(file_get_contents($finalPath));
    
    $prompt = <<<PROMPT
Analysiere dieses Gemälde und beschreibe den Inhalt. Fokussiere dich auf das, was tatsächlich zu sehen ist - Personen, Objekte, Herzen, Szenen, Farben, etc.

Gib die Informationen als JSON-Objekt zurück:
- title: Ein beschreibender Titel frauf Deutsch (2-8 Wörter)
- description: Was ist im Gemälde zu sehen? Beschreibe den Inhalt auf Deutsch (2-3 Sätze, rein inhaltlich, nicht anfangen mit "Das Gemälde zeigt")
- tags: Relevante Tags auf Deutsch, getrennt durch Kommas
- width: Geschätzte Breite in cm (als String)
- height: Geschätzte Höhe in cm (als String). Achte auf das Seitenverhältnis: Wenn das Bild höher als breit ist, muss height > width sein.
- date: Erstellungsdatum im Format dd.mm.yyyy, falls sichtbar, sonst leer

Antworte NUR mit einem JSON-Objekt in diesem Format:
{
  "title": "Titel hier",
  "description": "Beschreibung hier",
  "tags": "tag1, tag2, tag3",
  "width": "80",
  "height": "60",
  "date": "15.03.2024"
}
PROMPT;
    
    // Payload format as specified by API
    $payload = [
        'input' => [
            'images' => ["data:$mime;base64,$imgB64"],
            'prompt' => $prompt,
            'temperature' => 1,
            'dynamic_thinking' => false,
            'max_output_tokens' => 65535,
        ],
    ];
    
    // Replicate Call - exactly like corners.php
    $ch = curl_init("https://api.replicate.com/v1/models/google/gemini-2.5-flash/predictions");
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ["Authorization: Token $token", "Content-Type: application/json", "Prefer: wait"],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 300
    ]);
    $res = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    
    if ($res === false || $http >= 400) {
        http_response_code(502);
        echo json_encode(['ok' => false, 'error' => 'replicate_failed', 'detail' => $err ?: $res]);
        exit;
    }
    
    $resp = json_decode($res, true);
    
    // Extract text output from response (Gemini returns text, not image)
    $output = '';
    if (isset($resp['output'])) {
        if (is_array($resp['output'])) {
            $output = implode(' ', $resp['output']);
        } else {
            $output = (string)$resp['output'];
        }
    }
    
    if (empty($output)) {
        http_response_code(502);
        echo json_encode(['ok' => false, 'error' => 'empty_output', 'sample' => is_scalar($resp['output']) ? $resp['output'] : json_encode($resp['output'])]);
        exit;
    }
    
    // Try to extract JSON from the output
    $jsonMatch = null;
    // Try to find JSON object in the output
    if (preg_match('/\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\}/s', $output, $matches)) {
        $jsonMatch = json_decode($matches[0], true);
    }
    
    // Get current date in German format (dd.mm.yyyy)
    $currentDate = date('d.m.Y');
    
    // Get image dimensions to validate width/height
    $imageInfo = @getimagesize($finalPath);
    $imageWidth = $imageInfo ? $imageInfo[0] : null;
    $imageHeight = $imageInfo ? $imageInfo[1] : null;
    $isPortrait = $imageWidth && $imageHeight && $imageHeight > $imageWidth;
    $isLandscape = $imageWidth && $imageHeight && $imageWidth > $imageHeight;
    
    if ($jsonMatch && is_array($jsonMatch)) {
        // Use date from AI if available, otherwise use current date
        $date = trim($jsonMatch['date'] ?? '');
        if (empty($date)) {
            $date = $currentDate;
        }
        
        $width = trim($jsonMatch['width'] ?? '');
        $height = trim($jsonMatch['height'] ?? '');
        
        // Validate and correct width/height based on image aspect ratio
        if (!empty($width) && !empty($height) && $imageWidth && $imageHeight) {
            $widthNum = (float)$width;
            $heightNum = (float)$height;
            $imageRatio = $imageWidth / $imageHeight;
            $providedRatio = $widthNum / $heightNum;
            
            // If ratios don't match (more than 20% difference), correct them
            if (abs($imageRatio - $providedRatio) > 0.2) {
                if ($isPortrait && $widthNum >= $heightNum) {
                    // Portrait image but width >= height, swap them
                    $temp = $widthNum;
                    $widthNum = $heightNum;
                    $heightNum = $temp;
                } elseif ($isLandscape && $heightNum >= $widthNum) {
                    // Landscape image but height >= width, swap them
                    $temp = $widthNum;
                    $widthNum = $heightNum;
                    $heightNum = $temp;
                } else {
                    // Calculate one dimension based on the other to match image ratio
                    if ($widthNum > 0) {
                        $heightNum = round($widthNum / $imageRatio);
                    } elseif ($heightNum > 0) {
                        $widthNum = round($heightNum * $imageRatio);
                    }
                }
                $width = (string)round($widthNum);
                $height = (string)round($heightNum);
            }
        }
        
        echo json_encode([
            'ok' => true,
            'title' => trim($jsonMatch['title'] ?? ''),
            'description' => trim($jsonMatch['description'] ?? ''),
            'tags' => trim($jsonMatch['tags'] ?? ''),
            'date' => $date,
            'width' => $width,
            'height' => $height
        ]);
    } else {
        // Fallback: Try to parse text response
        $title = '';
        $description = '';
        $tags = '';
        $date = $currentDate; // Default to current date
        $width = '';
        $height = '';
        
        // Try to extract dimensions from text (e.g., "80 x 60" or "80x60")
        if (preg_match('/(\d+)\s*[x×]\s*(\d+)/i', $output, $m)) {
            $width = trim($m[1]);
            $height = trim($m[2]);
            
            // Validate and correct width/height based on image aspect ratio
            if ($imageWidth && $imageHeight) {
                $widthNum = (float)$width;
                $heightNum = (float)$height;
                $imageRatio = $imageWidth / $imageHeight;
                $providedRatio = $widthNum / $heightNum;
                
                // If ratios don't match (more than 20% difference), correct them
                if (abs($imageRatio - $providedRatio) > 0.2) {
                    if ($isPortrait && $widthNum >= $heightNum) {
                        // Portrait image but width >= height, swap them
                        $temp = $widthNum;
                        $widthNum = $heightNum;
                        $heightNum = $temp;
                    } elseif ($isLandscape && $heightNum >= $widthNum) {
                        // Landscape image but height >= width, swap them
                        $temp = $widthNum;
                        $widthNum = $heightNum;
                        $heightNum = $temp;
                    } else {
                        // Calculate one dimension based on the other to match image ratio
                        if ($widthNum > 0) {
                            $heightNum = round($widthNum / $imageRatio);
                        } elseif ($heightNum > 0) {
                            $widthNum = round($heightNum * $imageRatio);
                        }
                    }
                    $width = (string)round($widthNum);
                    $height = (string)round($heightNum);
                }
            }
        }
        
        // Simple text parsing fallback
        if (preg_match('/title[:\s]+([^\n]+)/i', $output, $m)) {
            $title = trim($m[1]);
        }
        if (preg_match('/description[:\s]+([^\n]+(?:\n[^\n]+)*)/i', $output, $m)) {
            $description = trim($m[1]);
        }
        if (preg_match('/tags[:\s]+([^\n]+)/i', $output, $m)) {
            $tags = trim($m[1]);
        }
        if (preg_match('/datum[:\s]+([^\n]+)/i', $output, $m)) {
            $extractedDate = trim($m[1]);
            // Validate date format (dd.mm.yyyy)
            if (preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $extractedDate)) {
                $date = $extractedDate;
            }
        }
        
        echo json_encode([
            'ok' => true,
            'title' => $title,
            'description' => $description,
            'tags' => $tags,
            'date' => $date,
            'width' => $width,
            'height' => $height
        ]);
    }
    
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
    exit;
}
