<?php
declare(strict_types=1);

// Continue execution even if user closes browser/connection
ignore_user_abort(true);

// Increase execution time limit for long-running predictions (10 minutes)
set_time_limit(600);

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

// Extract base name to find _original image (like corners_json.php)
$base = extract_base_name($image);

// Find _original image (preferred, like corners_json.php)
$originalImage = null;
$files = scandir($imagesDir) ?: [];
foreach ($files as $file) {
    if ($file === '.' || $file === '..') continue;
    $fileStem = pathinfo($file, PATHINFO_FILENAME);
    if (strpos($fileStem, $base.'_original') === 0) {
        $originalImage = $file;
        break;
    }
}

// If no _original found, try other extensions
if (!$originalImage) {
    $extensions = ['png', 'jpg', 'jpeg', 'webp'];
    foreach ($extensions as $e) {
        $testFile = $base.'_original.'.$e;
        if (is_file($imagesDir.$testFile)) {
            $originalImage = $testFile;
            break;
        }
    }
}

// Fallback to _final if no original found
if (!$originalImage) {
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        $fileStem = pathinfo($file, PATHINFO_FILENAME);
        if (strpos($fileStem, $base.'_final') === 0) {
            $originalImage = $file;
            break;
        }
    }
}

// Last fallback: use the provided image
if (!$originalImage) {
    $originalImage = $image;
}

$finalPath = $imagesDir.$originalImage;

if (!is_file($finalPath)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Image not found', 'path' => $finalPath]);
    exit;
}

// Determine JSON file path (same as image path but with .json extension)
$jsonPath = $finalPath . '.json';

// Log the image path being used
error_log('AI Fill Form: Using image: ' . $finalPath);
error_log('AI Fill Form: Image filename: ' . $finalImage);
error_log('AI Fill Form: JSON path: ' . $jsonPath);

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
    
    // Read and encode image
    $imageData = file_get_contents($finalPath);
    if ($imageData === false) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'failed to read image file']);
        exit;
    }
    
    $imgB64 = base64_encode($imageData);
    
    // Check if base64 encoding was successful
    if (empty($imgB64)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'failed to encode image']);
        exit;
    }
    
    // Log image size for debugging
    error_log('AI Fill Form: Image size: ' . strlen($imageData) . ' bytes, Base64 length: ' . strlen($imgB64));
    
    $prompt = <<<PROMPT
Analysiere dieses Gemälde und beschreibe den Inhalt für Kunstinteressierte. Fokussiere dich auf das, was tatsächlich zu sehen ist - Personen, Objekte, Herzen, Szenen, Farben, etc.

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
    
    // Payload format for gemini-3-pro
    $payload = [
        'input' => [
            'images' => ["data:$mime;base64,$imgB64"],
            'max_output_tokens' => 65535,
            'prompt' => $prompt,
            'temperature' => 1,
            'thinking_level' => 'low',
            'top_p' => 0.95,
            'videos' => []
        ],
    ];
    
    // Step 1: Create prediction (without waiting)
    $ch = curl_init("https://api.replicate.com/v1/models/google/gemini-3-pro/predictions");
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ["Authorization: Token $token", "Content-Type: application/json"],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 30
    ]);
    
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    
    if ($res === false || $httpCode >= 400) {
        error_log('AI Fill Form: Replicate API error - HTTP ' . $httpCode . ': ' . ($err ?: substr($res, 0, 1000)));
        http_response_code(502);
        echo json_encode([
            'ok' => false,
            'error' => 'replicate_failed',
            'detail' => $err ?: $res,
            'http_code' => $httpCode
        ]);
        exit;
    }
    
    $resp = json_decode($res, true);
    if (!is_array($resp)) {
        error_log('AI Fill Form: Invalid JSON response from Replicate: ' . substr($res, 0, 1000));
        http_response_code(502);
        echo json_encode(['ok' => false, 'error' => 'invalid_prediction_response', 'sample' => substr($res, 0, 500)]);
        exit;
    }
    
    // Log the response for debugging
    error_log('AI Fill Form: Replicate prediction created: ' . json_encode([
        'id' => $resp['id'] ?? 'unknown',
        'status' => $resp['status'] ?? 'unknown',
        'error' => $resp['error'] ?? null
    ]));
    
    if (!isset($resp['urls']['get'])) {
        error_log('AI Fill Form: Missing prediction URL in response: ' . json_encode($resp));
        http_response_code(502);
        echo json_encode(['ok' => false, 'error' => 'invalid_prediction_response', 'response' => $resp]);
        exit;
    }
    
    $predictionUrl = $resp['urls']['get'];
    $status = $resp['status'] ?? 'unknown';
    
    // Step 2: Poll until prediction completes (max 10 minutes)
    $maxAttempts = 120; // 120 attempts * 5 seconds = 10 minutes max
    $attempt = 0;
    
    while (in_array($status, ['starting', 'processing']) && $attempt < $maxAttempts) {
        sleep(5); // Wait 5 seconds between polls
        $attempt++;
        
        $ch = curl_init($predictionUrl);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => ["Authorization: Token $token"],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30
        ]);
        
        $res = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($res === false || $httpCode >= 400) {
            continue; // Retry on error
        }
        
        $resp = json_decode($res, true);
        if (!is_array($resp)) {
            continue; // Retry on invalid JSON
        }
        
        $status = $resp['status'] ?? 'unknown';
        
        // If completed or failed, break the loop
        if ($status === 'succeeded' || $status === 'failed' || $status === 'canceled') {
            break;
        }
    }
    
    // Step 3: Save the complete Replicate response FIRST (before any processing)
    // This happens regardless of success/failure status
    $existingJson = [];
    if (is_file($jsonPath)) {
        $existingJson = json_decode(file_get_contents($jsonPath), true);
        if (!is_array($existingJson)) {
            $existingJson = [];
        }
    }
    
    // Store the complete Replicate response as string (even if invalid/failed)
    $existingJson['ai_fill_form'] = [
        'replicate_response_raw' => json_encode($resp, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'replicate_response' => $resp, // Also store as array for easier access
        'timestamp' => date('c'),
        'status' => $status,
        'attempts' => $attempt
    ];
    
    // Save immediately - BEFORE any processing or validation
    file_put_contents($jsonPath, json_encode($existingJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    
    // Step 4: Check final status
    if ($status !== 'succeeded') {
        http_response_code(502);
        echo json_encode([
            'ok' => false,
            'error' => 'prediction_not_completed',
            'status' => $status,
            'detail' => $resp['error'] ?? 'Prediction did not complete in time',
            'attempts' => $attempt
        ]);
        exit;
    }
    
    // Extract text output from response (Gemini returns text, not image)
    $output = '';
    if (isset($resp['output'])) {
        if (is_array($resp['output'])) {
            $output = implode(' ', $resp['output']);
        } else {
            $output = (string)$resp['output'];
        }
    }
    
    // Update JSON with output text (even if empty)
    $existingJson['ai_fill_form']['output_text'] = $output;
    $existingJson['ai_fill_form']['output_empty'] = empty($output);
    file_put_contents($jsonPath, json_encode($existingJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    
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
        
        // Update JSON with extracted form data
        $existingJson['ai_fill_form']['extracted_data'] = [
            'title' => trim($jsonMatch['title'] ?? ''),
            'description' => trim($jsonMatch['description'] ?? ''),
            'tags' => trim($jsonMatch['tags'] ?? ''),
            'date' => $date,
            'width' => $width,
            'height' => $height
        ];
        file_put_contents($jsonPath, json_encode($existingJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        
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
        
        // Update JSON with extracted form data (fallback parsing)
        $existingJson['ai_fill_form']['extracted_data'] = [
            'title' => $title,
            'description' => $description,
            'tags' => $tags,
            'date' => $date,
            'width' => $width,
            'height' => $height,
            'parsing_method' => 'fallback'
        ];
        file_put_contents($jsonPath, json_encode($existingJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        
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
