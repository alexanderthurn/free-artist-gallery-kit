<?php
declare(strict_types=1);

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
// Using the structure provided by the user
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
    // Replicate Call - using google/gemini-3-pro model
    $ch = curl_init("https://api.replicate.com/v1/models/google/gemini-3-pro/predictions");
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ["Authorization: Token $TOKEN", "Content-Type: application/json", "Prefer: wait"],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 300
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
    if (!is_array($resp)) {
        http_response_code(502);
        echo json_encode(['ok' => false, 'error' => 'invalid_json_response', 'sample' => substr($res, 0, 500)]);
        exit;
    }
    
    // Extract text from Replicate/Gemini response
    $outputText = '';
    if (isset($resp['output'])) {
        if (is_array($resp['output'])) {
            $outputText = implode(' ', $resp['output']);
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
    
    // Try to find JSON object in the output
    if (preg_match('/\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\}/s', $outputText, $matches)) {
        $cornersData = json_decode($matches[0], true);
    }
    
    // If no JSON found, try parsing the whole output as JSON
    if ($cornersData === null) {
        $cornersData = json_decode($outputText, true);
    }
    
    if (!is_array($cornersData) || !isset($cornersData['corners']) || !is_array($cornersData['corners'])) {
        http_response_code(502);
        echo json_encode([
            'ok' => false,
            'error' => 'invalid_corners_format',
            'output_text' => substr($outputText, 0, 1000),
            'parsed' => $cornersData
        ]);
        exit;
    }
    
    // Convert percentages to pixel coordinates
    $pixelCorners = [];
    foreach ($cornersData['corners'] as $corner) {
        if (!isset($corner['x']) || !isset($corner['y'])) {
            continue;
        }
        
        $xPercent = (float)$corner['x'];
        $yPercent = (float)$corner['y'];
        
        // Convert percentage to pixels
        $xPixel = round(($xPercent / 100) * $imgW);
        $yPixel = round(($yPercent / 100) * $imgH);
        
        $pixelCorners[] = [
            'x' => $xPixel,
            'y' => $yPixel,
            'x_percent' => $xPercent,
            'y_percent' => $yPercent,
            'label' => $corner['label'] ?? ''
        ];
    }
    
    // Ensure we have exactly 4 corners
    if (count($pixelCorners) !== 4) {
        http_response_code(502);
        echo json_encode([
            'ok' => false,
            'error' => 'invalid_corner_count',
            'count' => count($pixelCorners),
            'corners' => $pixelCorners
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
    
    echo json_encode([
        'ok' => true,
        'corners' => $resultCorners,
        'original_corners' => $resultCorners, // Alias for compatibility with free.html
        'corners_with_percentages' => $pixelCorners,
        'image_width' => $imgW,
        'image_height' => $imgH,
        'source' => $rel
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

