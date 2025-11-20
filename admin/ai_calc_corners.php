<?php
declare(strict_types=1);

// Continue execution even if user closes browser/connection
ignore_user_abort(true);

// Increase execution time limit for long-running predictions (10 minutes)
set_time_limit(600);

require_once __DIR__ . '/utils.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $TOKEN = load_replicate_token();
} catch (RuntimeException $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'missing REPLICATE_API_TOKEN']);
    exit;
}

// ---- Eingabe ----
$rel = $_POST['image_path'] ?? '';
if ($rel === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'image_path required']);
    exit;
}

// Get offset from POST (default: 1.0 percent)
$offsetPercent = isset($_POST['offset']) ? (float)$_POST['offset'] : 1.0;
$offsetPercent = max(0, min(10, $offsetPercent)); // Clamp between 0 and 10 percent

$abs = $rel;
if ($rel[0] !== '/' && !preg_match('#^[a-z]+://#i', $rel)) {
    $abs = dirname(__DIR__) . '/' . ltrim($rel, '/');
}
if (!is_file($abs)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'image not found', 'path' => $abs]);
    exit;
}

[$imgW, $imgH] = getimagesize($abs);
$mime = mime_content_type($abs);
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'unsupported image type', 'mime' => $mime]);
    exit;
}
$imgB64 = base64_encode(file_get_contents($abs));

// ---- Check for cached Replicate response ----
$jsonPath = $abs . '.json';
$cachedResponse = null;
if (is_file($jsonPath)) {
    $existingJson = json_decode(file_get_contents($jsonPath), true);
    if (is_array($existingJson) && isset($existingJson['corner_detection']) && 
        isset($existingJson['corner_detection']['replicate_response']) && 
        is_array($existingJson['corner_detection']['replicate_response'])) {
        // Use cached Replicate response
        $cachedResponse = $existingJson['corner_detection']['replicate_response'];
    }
}

// If we have cached response, use it and skip API call
// But always recalculate corners (don't cache computed values)
if ($cachedResponse !== null && isset($cachedResponse['status']) && $cachedResponse['status'] === 'succeeded') {
    // Use cached response and skip to processing (will recalculate corners)
    $resp = $cachedResponse;
    $status = $resp['status'];
    goto process_cached_response;
}

// ---- Prompt for corner detection ----
$prompt = <<<PROMPT
Analyze this image and identify the four corners of the painting canvas (excluding frame, wall, mat, glass, shadows).

Return the coordinates as percentages relative to the image dimensions in JSON format:
{
  "corners": [
    {"x": 10.5, "y": 15.2, "label": "top-left"},
    {"x": 89.3, "y": 14.8, "label": "top-right"},
    {"x": 88.7, "y": 85.1, "label": "bottom-right"},
    {"x": 11.2, "y": 84.9, "label": "bottom-left"}
  ]
}

The coordinates should be percentages (0-100) where:
- x: horizontal position as percentage of image width
- y: vertical position as percentage of image height
- Order: top-left, top-right, bottom-right, bottom-left

Return ONLY valid JSON, no other text.
PROMPT;

// ---- Replicate API Call (Google Gemini 3 Pro) ----
// Using the structure for gemini-3-pro
$payload = [
    'input' => [
        'images' => ["data:$mime;base64,$imgB64"],
        'max_output_tokens' => 65535,
        'prompt' => $prompt,
        'temperature' => 1,
        'thinking_level' => 'low',
        'top_p' => 0.95,
        'videos' => []
    ]
];

try {
    // Step 1: Create prediction (without waiting)
    $ch = curl_init("https://api.replicate.com/v1/models/google/gemini-3-pro/predictions");
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ["Authorization: Token $TOKEN", "Content-Type: application/json"],
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
    if (!is_array($resp) || !isset($resp['urls']['get'])) {
        http_response_code(502);
        echo json_encode(['ok' => false, 'error' => 'invalid_prediction_response', 'sample' => substr($res, 0, 500)]);
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
            CURLOPT_HTTPHEADER => ["Authorization: Token $TOKEN"],
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
        
        // If completed or failed, save response IMMEDIATELY and break the loop
        if ($status === 'succeeded' || $status === 'failed' || $status === 'canceled') {
            // Save the response IMMEDIATELY after receiving it (before any further processing)
            $existingJson = [];
            if (is_file($jsonPath)) {
                $existingJson = json_decode(file_get_contents($jsonPath), true);
                if (!is_array($existingJson)) {
                    $existingJson = [];
                }
            }
            
            // Store ONLY the complete Replicate response (no computed values)
            $existingJson['corner_detection'] = [
                'replicate_response_raw' => json_encode($resp, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'replicate_response' => $resp, // Also store as array for easier access
                'timestamp' => date('c'),
                'status' => $status,
                'attempts' => $attempt
            ];
            
            // Save immediately - RIGHT AFTER receiving response, before any processing
            // Use LOCK_EX to ensure atomic write
            file_put_contents($jsonPath, json_encode($existingJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
            
            break;
        }
    }
    
    // Label for processing cached response
    process_cached_response:
    
    // Step 4: Check final status and extract output
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
    
    // Extract text from Replicate/Gemini response
    $outputText = '';
    if (isset($resp['output'])) {
        if (is_array($resp['output'])) {
            // Join without spaces to avoid breaking JSON (numbers can be split across array elements)
            $outputText = implode('', $resp['output']);
        } else {
            $outputText = (string)$resp['output'];
        }
    }
    
    if (empty($outputText)) {
        http_response_code(502);
        echo json_encode(['ok' => false, 'error' => 'empty_output', 'response' => $resp]);
        exit;
    }
    
    // Try to extract JSON from the response
    $cornersData = null;
    
    // Step 1: Try to extract JSON from code blocks (```json ... ```)
    // Match code blocks with proper brace matching
    if (preg_match('/```(?:json)?\s*(\{.*)\s*```/s', $outputText, $matches)) {
        $jsonStr = $matches[1];
        
        // Find the complete JSON object by matching braces
        $braceCount = 0;
        $jsonEnd = 0;
        $inString = false;
        $escapeNext = false;
        
        for ($i = 0; $i < strlen($jsonStr); $i++) {
            $char = $jsonStr[$i];
            
            if ($escapeNext) {
                $escapeNext = false;
                continue;
            }
            
            if ($char === '\\') {
                $escapeNext = true;
                continue;
            }
            
            if ($char === '"' && !$escapeNext) {
                $inString = !$inString;
                continue;
            }
            
            if (!$inString) {
                if ($char === '{') {
                    $braceCount++;
                } elseif ($char === '}') {
                    $braceCount--;
                    if ($braceCount === 0) {
                        $jsonEnd = $i + 1;
                        break;
                    }
                }
            }
        }
        
        if ($braceCount === 0 && $jsonEnd > 0) {
            $jsonStr = substr($jsonStr, 0, $jsonEnd);
            // Clean up the JSON string - handle various split cases from array joining
            
            // Fix split quotes in keys: "y" "\": 87.6 -> "y": 87.6
            $jsonStr = preg_replace('/"(\w+)"\s*"\s*\\\?":/', '"$1":', $jsonStr);
            
            // Fix split quotes in values: "label\":" "top-left" -> "label":"top-left"
            $jsonStr = preg_replace('/:\s*"\s*"([^"]+)"/', ': "$1"', $jsonStr);
            
            // Fix split numbers: "y\": 8" "7.6 -> "y": 87.6
            $jsonStr = preg_replace('/:\s*(\d+)"\s*"(\d+\.\d+)/', ': $1$2', $jsonStr);
            $jsonStr = preg_replace('/:\s*(\d+\.\d+)"\s*"(\d+)/', ': $1$2', $jsonStr);
            
            // Remove spaces in numeric values (e.g., "87. 5" -> "87.5")
            $jsonStr = preg_replace('/(\d+)\s+\.\s*(\d+)/', '$1.$2', $jsonStr);
            $jsonStr = preg_replace('/(\d+)\s*\.\s+(\d+)/', '$1.$2', $jsonStr);
            $jsonStr = preg_replace('/(\d+)\s+(\d+\.\d+)/', '$1$2', $jsonStr);
            $jsonStr = preg_replace('/(\d+\.\d+)\s+(\d+)/', '$1$2', $jsonStr);
            
            // Fix any remaining quote splits
            $jsonStr = preg_replace('/"\s*"([^"]+)"/', '"$1"', $jsonStr);
            
            $cornersData = json_decode($jsonStr, true);
        }
    }
    
    // Step 2: If that fails, try parsing the whole output as JSON
    if ($cornersData === null || !is_array($cornersData)) {
        $cleanedOutput = $outputText;
        // Clean up spaces in numeric values and split numbers
        $cleanedOutput = preg_replace('/(\d+)\s+\.\s*(\d+)/', '$1.$2', $cleanedOutput);
        $cleanedOutput = preg_replace('/(\d+)\s*\.\s+(\d+)/', '$1.$2', $cleanedOutput);
        $cleanedOutput = preg_replace('/(\d+)\s+(\d+\.\d+)/', '$1$2', $cleanedOutput);
        $cleanedOutput = preg_replace('/(\d+\.\d+)\s+(\d+)/', '$1$2', $cleanedOutput);
        $cleanedOutput = preg_replace('/"\s+"([^"]+)"/', '"$1"', $cleanedOutput);
        $cornersData = json_decode($cleanedOutput, true);
    }
    
    // Step 3: If that fails, try to extract JSON using brace matching
    if ($cornersData === null || !is_array($cornersData)) {
        // Find JSON object boundaries by matching braces properly
        $jsonStart = strpos($outputText, '{');
        if ($jsonStart !== false) {
            $braceCount = 0;
            $jsonEnd = $jsonStart;
            $inString = false;
            $escapeNext = false;
            
            for ($i = $jsonStart; $i < strlen($outputText); $i++) {
                $char = $outputText[$i];
                
                if ($escapeNext) {
                    $escapeNext = false;
                    continue;
                }
                
                if ($char === '\\') {
                    $escapeNext = true;
                    continue;
                }
                
                if ($char === '"' && !$escapeNext) {
                    $inString = !$inString;
                    continue;
                }
                
                if (!$inString) {
                    if ($char === '{') {
                        $braceCount++;
                    } elseif ($char === '}') {
                        $braceCount--;
                        if ($braceCount === 0) {
                            $jsonEnd = $i + 1;
                            break;
                        }
                    }
                }
            }
            
            if ($braceCount === 0 && $jsonEnd > $jsonStart) {
                $jsonStr = substr($outputText, $jsonStart, $jsonEnd - $jsonStart);
                // Clean up spaces in numeric values and split numbers
                $jsonStr = preg_replace('/(\d+)\s+\.\s*(\d+)/', '$1.$2', $jsonStr);
                $jsonStr = preg_replace('/(\d+)\s*\.\s+(\d+)/', '$1.$2', $jsonStr);
                $jsonStr = preg_replace('/(\d+)\s+(\d+\.\d+)/', '$1$2', $jsonStr);
                $jsonStr = preg_replace('/(\d+\.\d+)\s+(\d+)/', '$1$2', $jsonStr);
                $jsonStr = preg_replace('/"\s+"([^"]+)"/', '"$1"', $jsonStr);
                $cornersData = json_decode($jsonStr, true);
            }
        }
    }
    
    // Step 4: Last resort - try regex extraction
    if ($cornersData === null || !is_array($cornersData)) {
        if (preg_match('/(\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\})/s', $outputText, $matches)) {
            foreach ($matches as $match) {
                // Clean up spaces in numeric values and split numbers
                $cleanedMatch = preg_replace('/(\d+)\s+\.\s*(\d+)/', '$1.$2', $match);
                $cleanedMatch = preg_replace('/(\d+)\s*\.\s+(\d+)/', '$1.$2', $cleanedMatch);
                $cleanedMatch = preg_replace('/(\d+)\s+(\d+\.\d+)/', '$1$2', $cleanedMatch);
                $cleanedMatch = preg_replace('/(\d+\.\d+)\s+(\d+)/', '$1$2', $cleanedMatch);
                $cleanedMatch = preg_replace('/"\s+"([^"]+)"/', '"$1"', $cleanedMatch);
                $decoded = json_decode($cleanedMatch, true);
                if (is_array($decoded) && isset($decoded['corners'])) {
                    $cornersData = $decoded;
                    break;
                }
            }
        }
    }
    
    if (!is_array($cornersData) || !isset($cornersData['corners']) || !is_array($cornersData['corners'])) {
        http_response_code(502);
        echo json_encode([
            'ok' => false,
            'error' => 'invalid_corners_format',
            'output_text' => substr($outputText, 0, 1000),
            'parsed' => $cornersData,
            'output_length' => strlen($outputText)
        ]);
        exit;
    }
    
    // Ensure we have exactly 4 corners in the parsed data
    if (count($cornersData['corners']) !== 4) {
        http_response_code(502);
        echo json_encode([
            'ok' => false,
            'error' => 'invalid_corner_count_in_response',
            'count' => count($cornersData['corners']),
            'raw_corners' => $cornersData['corners'],
            'output_text' => substr($outputText, 0, 500)
        ]);
        exit;
    }
    
    // Convert percentages to pixel coordinates
    // Offset to move corners inward (to account for painting border/inset)
    // Offset is set from POST parameter above (default: 1.0 percent)
    // The offset percentage is based on the painting dimensions, not the image dimensions
    
    // First, convert all corners from percentages to pixels (without offset)
    $rawPixelCorners = [];
    $cornerIndex = 0;
    foreach ($cornersData['corners'] as $corner) {
        // Normalize keys by trimming whitespace (handles cases like " y" instead of "y")
        $normalizedCorner = [];
        foreach ($corner as $key => $value) {
            $normalizedKey = trim($key);
            $normalizedCorner[$normalizedKey] = $value;
        }
        
        // Try to get x and y values, checking both normalized and original keys
        $xPercent = null;
        $yPercent = null;
        
        if (isset($normalizedCorner['x'])) {
            $xPercent = (float)$normalizedCorner['x'];
        } elseif (isset($corner['x'])) {
            $xPercent = (float)$corner['x'];
        }
        
        if (isset($normalizedCorner['y'])) {
            $yPercent = (float)$normalizedCorner['y'];
        } elseif (isset($corner['y'])) {
            $yPercent = (float)$corner['y'];
        }
        
        if ($xPercent === null || $yPercent === null) {
            http_response_code(502);
            echo json_encode([
                'ok' => false,
                'error' => 'missing_corner_coordinates',
                'corner' => $corner,
                'normalized_corner' => $normalizedCorner,
                'all_corners' => $cornersData['corners']
            ]);
            exit;
        }
        
        // Convert percentage to pixels (without offset yet)
        $xPixel = round(($xPercent / 100) * $imgW);
        $yPixel = round(($yPercent / 100) * $imgH);
        
        $rawPixelCorners[] = [
            'x' => $xPixel,
            'y' => $yPixel,
            'x_percent' => $xPercent,
            'y_percent' => $yPercent,
            'label' => trim(strtolower($normalizedCorner['label'] ?? $corner['label'] ?? ''))
        ];
        $cornerIndex++;
    }
    
    // Calculate painting dimensions based on corner positions
    // top-left (0), top-right (1), bottom-right (2), bottom-left (3)
    $topLeft = $rawPixelCorners[0];
    $topRight = $rawPixelCorners[1];
    $bottomRight = $rawPixelCorners[2];
    $bottomLeft = $rawPixelCorners[3];
    
    // Calculate average width (top and bottom edges)
    $topWidth = sqrt(pow($topRight['x'] - $topLeft['x'], 2) + pow($topRight['y'] - $topLeft['y'], 2));
    $bottomWidth = sqrt(pow($bottomRight['x'] - $bottomLeft['x'], 2) + pow($bottomRight['y'] - $bottomLeft['y'], 2));
    $paintingWidth = ($topWidth + $bottomWidth) / 2;
    
    // Calculate average height (left and right edges)
    $leftHeight = sqrt(pow($bottomLeft['x'] - $topLeft['x'], 2) + pow($bottomLeft['y'] - $topLeft['y'], 2));
    $rightHeight = sqrt(pow($bottomRight['x'] - $topRight['x'], 2) + pow($bottomRight['y'] - $topRight['y'], 2));
    $paintingHeight = ($leftHeight + $rightHeight) / 2;
    
    // Calculate offset in pixels based on painting dimensions
    $offsetX = ($offsetPercent / 100) * $paintingWidth;
    $offsetY = ($offsetPercent / 100) * $paintingHeight;
    
    // Apply offset to move corners inward based on corner position
    // 0: top-left -> move right and down
    // 1: top-right -> move left and down
    // 2: bottom-right -> move left and up
    // 3: bottom-left -> move right and up
    $pixelCorners = [];
    foreach ($rawPixelCorners as $idx => $corner) {
        $xPixel = $corner['x'];
        $yPixel = $corner['y'];
        
        switch ($idx) {
            case 0: // top-left
                $xPixel += $offsetX; // Move right
                $yPixel += $offsetY; // Move down
                break;
            case 1: // top-right
                $xPixel -= $offsetX; // Move left
                $yPixel += $offsetY; // Move down
                break;
            case 2: // bottom-right
                $xPixel -= $offsetX; // Move left
                $yPixel -= $offsetY; // Move up
                break;
            case 3: // bottom-left
                $xPixel += $offsetX; // Move right
                $yPixel -= $offsetY; // Move up
                break;
        }
        
        // Ensure pixels stay within image bounds
        $xPixel = max(0, min($imgW - 1, round($xPixel)));
        $yPixel = max(0, min($imgH - 1, round($yPixel)));
        
        // Convert back to percentages for storage
        $xPercent = ($xPixel / $imgW) * 100;
        $yPercent = ($yPixel / $imgH) * 100;
        
        $pixelCorners[] = [
            'x' => $xPixel,
            'y' => $yPixel,
            'x_percent' => $xPercent,
            'y_percent' => $yPercent,
            'label' => $corner['label']
        ];
    }
    
    // Ensure we have exactly 4 corners after processing
    if (count($pixelCorners) !== 4) {
        http_response_code(502);
        echo json_encode([
            'ok' => false,
            'error' => 'invalid_corner_count_after_processing',
            'count' => count($pixelCorners),
            'corners' => $pixelCorners,
            'raw_corners_count' => count($cornersData['corners'])
        ]);
        exit;
    }
    
    // Return in the same format as corners.php (array of [x, y] pairs)
    $resultCorners = [
        [$pixelCorners[0]['x'], $pixelCorners[0]['y']], // top-left
        [$pixelCorners[1]['x'], $pixelCorners[1]['y']], // top-right
        [$pixelCorners[2]['x'], $pixelCorners[2]['y']], // bottom-right
        [$pixelCorners[3]['x'], $pixelCorners[3]['y']]  // bottom-left
    ];
    
    // Save offset to JSON file (for tracking which offset was used)
    $existingJson = [];
    if (is_file($jsonPath)) {
        $existingJson = json_decode(file_get_contents($jsonPath), true);
        if (!is_array($existingJson)) {
            $existingJson = [];
        }
    }
    
    // Update corner_detection with offset used
    if (isset($existingJson['corner_detection'])) {
        $existingJson['corner_detection']['offset_percent'] = $offsetPercent;
        file_put_contents($jsonPath, json_encode($existingJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
    
    echo json_encode([
        'ok' => true,
        'corners' => $resultCorners,
        'original_corners' => $resultCorners, // Alias for compatibility with free.html
        'corners_with_percentages' => $pixelCorners,
        'image_width' => $imgW,
        'image_height' => $imgH,
        'source' => $rel,
        'offset_percent' => $offsetPercent,
        'cached' => false
    ]);
    
} catch (RuntimeException $e) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'replicate_failed', 'detail' => $e->getMessage()]);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'unexpected_error', 'detail' => $e->getMessage()]);
    exit;
}

