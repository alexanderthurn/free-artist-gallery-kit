<?php
declare(strict_types=1);

require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/meta.php';

/**
 * Poll a variant prediction and process results if complete
 * 
 * @param string $variantJsonPath Full path to variant JSON metadata file
 * @return array Result array with 'ok' => true/false, 'completed' => bool, 'still_processing' => bool
 */
function poll_variant_prediction(string $variantJsonPath): array {
    $variantMeta = [];
    if (is_file($variantJsonPath)) {
        $content = @file_get_contents($variantJsonPath);
        if ($content !== false) {
            $decoded = json_decode($content, true);
            if (is_array($decoded)) {
                $variantMeta = $decoded;
            }
        }
    }
    
    $predictionUrl = $variantMeta['prediction_url'] ?? null;
    if (!$predictionUrl || !is_string($predictionUrl)) {
        return ['ok' => false, 'error' => 'prediction_url_not_found'];
    }
    
    try {
        $TOKEN = load_replicate_token();
    } catch (RuntimeException $e) {
        return ['ok' => false, 'error' => 'missing REPLICATE_API_TOKEN'];
    }
    
    // Make single GET request to check status
    $ch = curl_init($predictionUrl);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ["Authorization: Token $TOKEN"],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30
    ]);
    
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    
    if ($res === false || $httpCode >= 400) {
        // Network error - keep status as in_progress, retry next run
        return ['ok' => true, 'still_processing' => true, 'error' => 'poll_failed', 'detail' => $err ?: 'HTTP ' . $httpCode];
    }
    
    $resp = json_decode($res, true);
    if (!is_array($resp)) {
        return ['ok' => true, 'still_processing' => true, 'error' => 'invalid_response'];
    }
    
    $status = $resp['status'] ?? 'unknown';
    
    // Save the response immediately
    $variantMeta['replicate_response_raw'] = json_encode($resp, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $variantMeta['replicate_response'] = $resp;
    $variantMeta['timestamp'] = date('c');
    $variantMeta['prediction_status'] = $status;
    update_json_file($variantJsonPath, $variantMeta, false);
    
    if ($status === 'succeeded') {
        // Process the completed prediction
        return process_completed_variant_prediction($variantJsonPath, $resp);
    } elseif ($status === 'failed' || $status === 'canceled') {
        // Prediction failed - set status to wanted for retry
        $variantMeta['status'] = 'wanted';
        update_json_file($variantJsonPath, $variantMeta, false);
        return [
            'ok' => false,
            'error' => 'prediction_failed',
            'status' => $status,
            'detail' => $resp['error'] ?? 'Prediction failed'
        ];
    } else {
        // Still processing
        return ['ok' => true, 'still_processing' => true, 'status' => $status];
    }
}

/**
 * Process a completed variant prediction response
 * 
 * @param string $variantJsonPath Full path to variant JSON metadata file
 * @param array $resp Replicate API response
 * @return array Result array with variant data
 */
function process_completed_variant_prediction(string $variantJsonPath, array $resp): array {
    $variantMeta = [];
    if (is_file($variantJsonPath)) {
        $content = @file_get_contents($variantJsonPath);
        if ($content !== false) {
            $decoded = json_decode($content, true);
            if (is_array($decoded)) {
                $variantMeta = $decoded;
            }
        }
    }
    
    // Extract image from response
    $imgBytes = fetch_image_bytes($resp['output'] ?? null);
    if ($imgBytes === null) {
        if (is_array($resp['output']) && isset($resp['output']['images'][0])) {
            $imgBytes = fetch_image_bytes($resp['output']['images'][0]);
        }
    }
    
    if ($imgBytes === null) {
        $variantMeta['status'] = 'wanted';
        update_json_file($variantJsonPath, $variantMeta, false);
        return ['ok' => false, 'error' => 'failed_to_extract_image', 'response' => $resp];
    }
    
    // Save the generated variant image
    $targetPath = $variantMeta['target_path'] ?? null;
    if (!$targetPath || !is_string($targetPath)) {
        return ['ok' => false, 'error' => 'target_path_not_found'];
    }
    
    // Ensure target directory exists
    $targetDir = dirname($targetPath);
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0755, true);
    }
    
    file_put_contents($targetPath, $imgBytes);
    
    // Generate thumbnail only if not in variants/ directory
    $variantsDir = __DIR__ . '/variants';
    $variantsDirReal = realpath($variantsDir);
    $targetPathReal = realpath(dirname($targetPath));
    if ($variantsDirReal && $targetPathReal && strpos($targetPathReal, $variantsDirReal) === false) {
        // Not in variants directory - generate thumbnail
        $thumbPath = generate_thumbnail_path($targetPath);
        generate_thumbnail($targetPath, $thumbPath, 512, 1024);
    }
    
    // Update status to completed
    $variantMeta['status'] = 'completed';
    $variantMeta['completed_at'] = date('c');
    update_json_file($variantJsonPath, $variantMeta, false);
    
    return [
        'ok' => true,
        'completed' => true,
        'target_path' => $targetPath,
        'variant_name' => $variantMeta['variant_name'] ?? null
    ];
}

/**
 * Generate a variant template (room image) using Replicate API (async)
 * This is for text-to-image generation of room templates
 * 
 * @param string $variantName Name of the variant (e.g., "arbeitszimmer")
 * @param string $prompt User's prompt describing the room
 * @param string $targetPath Path where the generated variant should be saved
 * @return array Result array with 'ok' => true/false and prediction info
 */
function generate_variant_template_async(
    string $variantName,
    string $prompt,
    string $targetPath
): array {
    try {
        $TOKEN = load_replicate_token();
    } catch (RuntimeException $e) {
        return ['ok' => false, 'error' => 'missing REPLICATE_API_TOKEN'];
    }
    
    $promptFinal = <<<PROMPT
  Erzeuge ein neues, fotorealistisches Bild eines Raumes. 
  Der Raum soll neutral gestaltet sein, die Deckenhöhe soll 2,5m sein. Der Raum soll genügend freie glatte Wandfläche bieten. Es darf kein Bild irgendwo hängen.
  Achte auf natürliche Beleuchtung und klare Linien.
  
  Raumbeschreibung des Nutzers:
  $prompt
PROMPT;
    
    $payload = [
        'input' => [
            'prompt' => $promptFinal,
            'output_format' => 'jpg'
        ]
    ];
    
    // Create prediction (without waiting)
    $ch = curl_init("https://api.replicate.com/v1/models/google/nano-banana-pro/predictions");
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
        return [
            'ok' => false,
            'error' => 'replicate_failed',
            'detail' => $err ?: $res,
            'http_code' => $httpCode
        ];
    }
    
    $resp = json_decode($res, true);
    if (!is_array($resp) || !isset($resp['urls']['get'])) {
        return ['ok' => false, 'error' => 'invalid_prediction_response', 'sample' => substr($res, 0, 500)];
    }
    
    $predictionUrl = $resp['urls']['get'];
    $predictionId = $resp['id'] ?? null;
    $status = $resp['status'] ?? 'unknown';
    
    // Save prediction info to variant JSON file
    $variantsDir = __DIR__ . '/variants';
    if (!is_dir($variantsDir)) {
        mkdir($variantsDir, 0755, true);
    }
    
    $variantJsonPath = $variantsDir . '/' . $variantName . '.json';
    $variantMeta = [
        'variant_name' => $variantName,
        'status' => 'in_progress',
        'started_at' => date('c'),
        'prediction_url' => $predictionUrl,
        'prediction_id' => $predictionId,
        'prediction_status' => $status,
        'timestamp' => date('c'),
        'prompt' => $prompt,
        'prompt_final' => $promptFinal,
        'target_path' => $targetPath,
        'filename' => basename($targetPath),
        'type' => 'template',
        'created_at' => date('c')
    ];
    
    require_once __DIR__ . '/meta.php';
    update_json_file($variantJsonPath, $variantMeta, false);
    
    return [
        'ok' => true,
        'prediction_started' => true,
        'prediction_url' => $predictionUrl,
        'prediction_id' => $predictionId,
        'variant_name' => $variantName
    ];
}

