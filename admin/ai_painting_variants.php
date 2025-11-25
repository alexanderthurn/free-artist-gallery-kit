<?php
declare(strict_types=1);

require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/meta.php';

/**
 * Process AI painting variants generation
 * @param string $imageBaseName Base name of the image (e.g., "IMG_2106_2")
 * @param array|null $variantNames Optional array of variant names to generate. If null, generates all existing variants.
 * @return array Result array with 'ok' key and other data
 */
function process_ai_painting_variants(string $imageBaseName, ?array $variantNames = null): array {
    $imagesDir = __DIR__ . '/images';
    $variantsDir = __DIR__ . '/variants';
    
    if (!is_dir($variantsDir)) {
        mkdir($variantsDir, 0755, true);
    }
    
    // Find the _final image for this base
    $finalImage = null;
    $files = scandir($imagesDir) ?: [];
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        $fileStem = pathinfo($file, PATHINFO_FILENAME);
        if (strpos($fileStem, $imageBaseName.'_final') === 0) {
            $finalImage = $imagesDir . '/' . $file;
            break;
        }
    }
    
    if (!$finalImage || !is_file($finalImage)) {
        return ['ok' => false, 'error' => 'final_image_not_found', 'base' => $imageBaseName];
    }
    
    // Find all variant templates
    $variantTemplates = [];
    $variantFiles = scandir($variantsDir) ?: [];
    foreach ($variantFiles as $file) {
        if ($file === '.' || $file === '..') continue;
        $fileStem = pathinfo($file, PATHINFO_FILENAME);
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        
        // Skip JSON files
        if ($ext === 'json') continue;
        
        // Only process image files
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
            $variantTemplates[] = [
                'variant_name' => $fileStem,
                'variant_template' => $file,
                'variant_path' => $variantsDir . '/' . $file
            ];
        }
    }
    
    if (empty($variantTemplates)) {
        return ['ok' => false, 'error' => 'no_variant_templates_found'];
    }
    
    // Filter by variantNames if provided
    if ($variantNames !== null && is_array($variantNames)) {
        $variantTemplates = array_filter($variantTemplates, function($vt) use ($variantNames) {
            return in_array($vt['variant_name'], $variantNames, true);
        });
    }
    
    if (empty($variantTemplates)) {
        return ['ok' => false, 'error' => 'no_matching_variant_templates'];
    }
    
    // Load metadata to get dimensions
    $originalImageFile = $imageBaseName . '_original.jpg';
    $meta = load_meta($originalImageFile, $imagesDir);
    $width = $meta['width'] ?? null;
    $height = $meta['height'] ?? null;
    
    // Load or initialize ai_painting_variants object
    $jsonFile = find_json_file($imageBaseName, $imagesDir);
    if (!$jsonFile) {
        return ['ok' => false, 'error' => 'json_file_not_found'];
    }
    
    $jsonPath = $imagesDir . '/' . $jsonFile;
    $existingMeta = [];
    if (is_file($jsonPath)) {
        $content = @file_get_contents($jsonPath);
        if ($content !== false) {
            $decoded = json_decode($content, true);
            if (is_array($decoded)) {
                $existingMeta = $decoded;
            }
        }
    }
    
    $aiPaintingVariants = $existingMeta['ai_painting_variants'] ?? [];
    
    // Check if there's already a Replicate response for variants
    // If status is completed but image_generation_needed is set, regenerate
    $imageGenerationNeeded = $aiPaintingVariants['image_generation_needed'] ?? false;
    
    // Initialize variants array if not exists
    if (!isset($aiPaintingVariants['variants']) || !is_array($aiPaintingVariants['variants'])) {
        $aiPaintingVariants['variants'] = [];
        update_json_file($jsonPath, ['ai_painting_variants' => $aiPaintingVariants], false);
    }
    
    $finalMime = mime_content_type($finalImage);
    
    $started = 0;
    $errors = [];
    $variants = $aiPaintingVariants['variants'] ?? [];
    
    // Initialize active_variants if not exists
    if (!isset($aiPaintingVariants['active_variants']) || !is_array($aiPaintingVariants['active_variants'])) {
        $aiPaintingVariants['active_variants'] = [];
    }
    
    // Process each variant template
    foreach ($variantTemplates as $variantTemplate) {
        $variantName = $variantTemplate['variant_name'];
        $targetPath = $imagesDir . '/' . $imageBaseName . '_variant_' . $variantName . '.jpg';
        
        // Check if this variant is already tracked in variants
        $existingVariant = $variants[$variantName] ?? null;
        $existingStatus = $existingVariant['status'] ?? null;
        $existingPredictionUrl = $existingVariant['prediction_url'] ?? null;
        
        // If variant is already completed and target exists, skip
        if ($existingStatus === 'completed' && is_file($targetPath) && !$imageGenerationNeeded) {
            // Ensure it's in active_variants
            if (!in_array($variantName, $aiPaintingVariants['active_variants'], true)) {
                $aiPaintingVariants['active_variants'][] = $variantName;
            }
            continue;
        }
        
        // If variant has a prediction URL and is in_progress, it will be polled by background task
        if ($existingPredictionUrl && $existingStatus === 'in_progress') {
            // Ensure it's in active_variants
            if (!in_array($variantName, $aiPaintingVariants['active_variants'], true)) {
                $aiPaintingVariants['active_variants'][] = $variantName;
            }
            continue;
        }
        
        // Add variant to active_variants immediately
        if (!in_array($variantName, $aiPaintingVariants['active_variants'], true)) {
            $aiPaintingVariants['active_variants'][] = $variantName;
        }
        
        // Build prompt with dimensions info
        $dimensionsInfo = '';
        if ($width !== null && $height !== null && $width !== '' && $height !== '') {
            $dimensionsInfo = "\n\nPainting dimensions: {$width}cm (width) × {$height}cm (height).";
            $dimensionsInfo .= "\nPlace the painting at an appropriate scale relative to the room dimensions, considering its actual size.";
        }
        
        $promptFinal = <<<PROMPT
You are an image editor.

Task:
- Place the painting into the free space on the wall.
- Make sure that the painting looks exactly like the original image.
{$dimensionsInfo}
PROMPT;
        
        // Create variant entry immediately (like ai_image_by_corners.php does)
        // This ensures the variant is tracked even if the API call fails
        $variants[$variantName] = [
            'variant_name' => $variantName,
            'status' => 'in_progress',
            'started_at' => date('c'),
            'prediction_url' => null, // Will be updated after API call
            'prediction_id' => null,
            'prediction_status' => 'unknown',
            'target_path' => $targetPath,
            'variant_template_path' => $variantTemplate['variant_path'],
            'final_image_path' => $finalImage,
            'prompt' => $promptFinal,
            'prompt_final' => $promptFinal,
            'width' => $width,
            'height' => $height
        ];
        
        // Save immediately (non-blocking, like ai_image_by_corners.php)
        $aiPaintingVariants['variants'] = $variants;
        update_json_file($jsonPath, ['ai_painting_variants' => $aiPaintingVariants], false);
        
        // Now start the async generation (non-blocking)
        try {
            $TOKEN = load_replicate_token();
        } catch (RuntimeException $e) {
            $variants[$variantName]['status'] = 'wanted';
            $variants[$variantName]['error'] = 'missing REPLICATE_API_TOKEN';
            $aiPaintingVariants['variants'] = $variants;
            update_json_file($jsonPath, ['ai_painting_variants' => $aiPaintingVariants], false);
            $errors[] = ['variant' => $variantName, 'error' => 'missing REPLICATE_API_TOKEN'];
            continue;
        }
        
        // Load images
        $variantTemplatePath = $variantTemplate['variant_path'];
        if (!is_file($variantTemplatePath)) {
            $variants[$variantName]['status'] = 'wanted';
            $variants[$variantName]['error'] = 'variant_template_not_found';
            $aiPaintingVariants['variants'] = $variants;
            update_json_file($jsonPath, ['ai_painting_variants' => $aiPaintingVariants], false);
            $errors[] = ['variant' => $variantName, 'error' => 'variant_template_not_found'];
            continue;
        }
        
        $variantMime = mime_content_type($variantTemplatePath);
        if (!in_array($variantMime, ['image/jpeg', 'image/png', 'image/webp'])) {
            $variants[$variantName]['status'] = 'wanted';
            $variants[$variantName]['error'] = 'unsupported_variant_template_type';
            $aiPaintingVariants['variants'] = $variants;
            update_json_file($jsonPath, ['ai_painting_variants' => $aiPaintingVariants], false);
            $errors[] = ['variant' => $variantName, 'error' => 'unsupported_variant_template_type'];
            continue;
        }
        
        if (!in_array($finalMime, ['image/jpeg', 'image/png', 'image/webp'])) {
            $variants[$variantName]['status'] = 'wanted';
            $variants[$variantName]['error'] = 'unsupported_final_image_type';
            $aiPaintingVariants['variants'] = $variants;
            update_json_file($jsonPath, ['ai_painting_variants' => $aiPaintingVariants], false);
            $errors[] = ['variant' => $variantName, 'error' => 'unsupported_final_image_type'];
            continue;
        }
        
        // Upload images to Replicate file API (100MB limit for nano-banana-pro)
        try {
            $variantUrl = replicate_upload_file($TOKEN, $variantTemplatePath, 100 * 1024 * 1024); // 100MB limit
            $finalUrl = replicate_upload_file($TOKEN, $finalImage, 100 * 1024 * 1024); // 100MB limit
            error_log('AI Painting Variants: Images uploaded to Replicate - variant: ' . $variantUrl . ', final: ' . $finalUrl);
        } catch (Throwable $e) {
            error_log('AI Painting Variants: Failed to upload images to Replicate: ' . $e->getMessage());
            $variants[$variantName]['status'] = 'wanted';
            $variants[$variantName]['error'] = 'upload_failed';
            $variants[$variantName]['error_detail'] = $e->getMessage();
            $aiPaintingVariants['variants'] = $variants;
            update_json_file($jsonPath, ['ai_painting_variants' => $aiPaintingVariants], false);
            $errors[] = [
                'variant' => $variantName,
                'error' => 'upload_failed',
                'detail' => $e->getMessage()
            ];
            continue;
        }
        
        $payload = [
            'input' => [
                'prompt' => $promptFinal,
                'image_input' => [$variantUrl, $finalUrl],
                'aspect_ratio' => '1:1',
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
            // API call failed - mark variant as wanted for retry
            $variants[$variantName]['status'] = 'wanted';
            $variants[$variantName]['error'] = 'replicate_failed';
            $aiPaintingVariants['variants'] = $variants;
            update_json_file($jsonPath, ['ai_painting_variants' => $aiPaintingVariants], false);
            $errors[] = [
                'variant' => $variantName,
                'error' => 'replicate_failed',
                'detail' => $err ?: substr($res, 0, 500)
            ];
            continue;
        }
        
        $resp = json_decode($res, true);
        if (!is_array($resp) || !isset($resp['urls']['get'])) {
            $variants[$variantName]['status'] = 'wanted';
            $variants[$variantName]['error'] = 'invalid_prediction_response';
            $aiPaintingVariants['variants'] = $variants;
            update_json_file($jsonPath, ['ai_painting_variants' => $aiPaintingVariants], false);
            $errors[] = [
                'variant' => $variantName,
                'error' => 'invalid_prediction_response'
            ];
            continue;
        }
        
        $predictionUrl = $resp['urls']['get'];
        $predictionId = $resp['id'] ?? null;
        $status = $resp['status'] ?? 'unknown';
        
        $started++;
        // Update variant with prediction details
        $variants[$variantName]['prediction_url'] = $predictionUrl;
        $variants[$variantName]['prediction_id'] = $predictionId;
        $variants[$variantName]['prediction_status'] = $status;
        
        // Update metadata again with prediction URL
        $aiPaintingVariants['variants'] = $variants;
        update_json_file($jsonPath, ['ai_painting_variants' => $aiPaintingVariants], false);
    }
    
    return [
        'ok' => true,
        'started' => $started,
        'total' => count($variantTemplates),
        'errors' => $errors,
        'message' => $started > 0 ? "Started generation for {$started} variant(s)" : 'No variants started'
    ];
}

