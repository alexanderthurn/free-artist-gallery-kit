<?php
declare(strict_types=1);

// Continue execution even if user closes browser/connection
ignore_user_abort(true);

// Increase execution time limit for long-running operations (10 minutes)
set_time_limit(600);

require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/meta.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$imagesDir = __DIR__ . '/images';

// Preview mode - check lock file to prevent multiple simultaneous executions
$isPreview = isset($_GET['preview']) && $_GET['preview'] === '1';
$lockFile = sys_get_temp_dir() . '/herzfabrik_bg_tasks.lock';
$lockTimeout = 30; // Lock expires after 30 seconds

if ($isPreview) {
    // Check if lock exists and is still valid
    if (is_file($lockFile)) {
        $lockTime = filemtime($lockFile);
        if (time() - $lockTime < $lockTimeout) {
            // Lock still valid - return counts only without processing
            $counts = get_pending_tasks_count($imagesDir);
            echo json_encode([
                'ok' => true,
                'preview' => true,
                'locked' => true,
                'summary' => [
                    'variants' => $counts['variants'],
                    'ai' => $counts['ai'],
                    'gallery' => $counts['gallery']
                ]
            ]);
            exit;
        } else {
            // Lock expired, remove it
            @unlink($lockFile);
        }
    }
    
    // Create lock file before processing
    @touch($lockFile);
}

$results = [
    'ok' => true,
    'processed' => [],
    'skipped' => [],
    'errors' => []
];

if (!is_dir($imagesDir)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Images directory not found']);
    exit;
}

/**
 * Check if variant regeneration is needed
 */
function check_variant_regeneration_needed(string $baseName, string $jsonPath, string $imagesDir): bool {
    // Extract image filename from JSON path
    $imageFilename = basename($jsonPath, '.json');
    $meta = load_meta($imageFilename, $imagesDir);
    
    // Check status flag inside ai_painting_variants
    $aiPaintingVariants = $meta['ai_painting_variants'] ?? [];
    $status = $aiPaintingVariants['regeneration_status'] ?? null;
    if ($status === 'needed') {
        return true;
    }
    
    // Check if in progress and stale
    if ($status === 'in_progress' && !is_task_in_progress($meta, 'variant_regeneration')) {
        return true; // Stale, retry
    }
    
    // Variants are only regenerated when explicitly requested via regeneration_status flag
    // File modification time checks removed to prevent automatic regeneration when _final.jpg is updated
    
    return false;
}

/**
 * Check if AI generation is needed
 */
function check_ai_generation_needed(array $meta): array {
    $aiCorners = $meta['ai_corners'] ?? [];
    $aiFillForm = $meta['ai_fill_form'] ?? [];
    $cornersStatus = $aiCorners['status'] ?? null;
    $formStatus = $aiFillForm['status'] ?? null;
    $needs = ['corners' => false, 'form' => false];
    
    // Check corners status
    if ($cornersStatus === 'wanted') {
        $needs['corners'] = true;
    } elseif ($cornersStatus === 'in_progress') {
        // Check if there's a prediction URL - if so, we need to poll it
        $hasPredictionUrl = isset($aiCorners['prediction_url']) && 
                          is_string($aiCorners['prediction_url']);
        if ($hasPredictionUrl) {
            $needs['corners'] = true; // Has prediction URL, needs polling
        } elseif (!is_task_in_progress($meta, 'ai_corners')) {
            $needs['corners'] = true; // Stale, retry
        }
    }
    
    // Check form status
    if ($formStatus === 'wanted') {
        $needs['form'] = true;
    } elseif ($formStatus === 'in_progress') {
        // Check if there's a prediction URL - if so, we need to poll it
        $hasPredictionUrl = isset($aiFillForm['prediction_url']) && 
                          is_string($aiFillForm['prediction_url']);
        if ($hasPredictionUrl) {
            $needs['form'] = true; // Has prediction URL, needs polling
        } elseif (!is_task_in_progress($meta, 'ai_form')) {
            $needs['form'] = true; // Stale, retry
        }
    }
    
    return $needs;
}

/**
 * Process variant regeneration
 * Uses process_ai_painting_variants for async regeneration instead of synchronous regeneration
 */
function process_variant_regeneration(string $baseName, string $jsonPath): array {
    $imagesDir = dirname($jsonPath);
    $variantsDir = __DIR__ . '/variants';
    
    // Set status to in_progress
    update_task_status($jsonPath, 'variant_regeneration', 'in_progress');
    
    try {
        // Find all existing variant files for this image base
        $existingVariants = [];
        $files = scandir($imagesDir) ?: [];
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            $fileStem = pathinfo($file, PATHINFO_FILENAME);
            // Check if this is a variant file: {base}_variant_{variantName}.jpg
            $pattern = '/^' . preg_quote($baseName, '/') . '_variant_(.+)$/';
            if (preg_match($pattern, $fileStem, $matches)) {
                $variantName = $matches[1];
                // Find the corresponding template in variants directory
                $variantTemplatePath = $variantsDir . '/' . $variantName . '.jpg';
                if (is_file($variantTemplatePath)) {
                    $existingVariants[] = $variantName;
                }
            }
        }
        
        if (empty($existingVariants)) {
            update_task_status($jsonPath, 'variant_regeneration', 'completed');
            return ['ok' => true, 'result' => ['regenerated' => 0, 'message' => 'No existing variants found']];
        }
        
        // Use process_ai_painting_variants to regenerate variants asynchronously
        require_once __DIR__ . '/ai_painting_variants.php';
        $result = process_ai_painting_variants($baseName, $existingVariants);
        
        if ($result['ok']) {
            // Regeneration started - will be completed asynchronously
            // Mark as completed since we've started the async process
            update_task_status($jsonPath, 'variant_regeneration', 'completed');
            return [
                'ok' => true,
                'result' => [
                    'regenerated' => $result['started'] ?? 0,
                    'total' => count($existingVariants),
                    'message' => $result['message'] ?? 'Variants regeneration started'
                ]
            ];
        } else {
            update_task_status($jsonPath, 'variant_regeneration', 'needed'); // Retry
            return ['ok' => false, 'error' => $result['error'] ?? 'Unknown error'];
        }
    } catch (Throwable $e) {
        update_task_status($jsonPath, 'variant_regeneration', 'needed'); // Retry
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Process AI corners generation
 */
function process_ai_corners(string $baseName, string $jsonPath): array {
    $imagesDir = __DIR__ . '/images';
    
    // Load metadata to check for prediction URL
    $imageFilename = basename($jsonPath, '.json');
    $meta = load_meta($imageFilename, $imagesDir);
    
    // Check if prediction URL exists and status is in_progress
    $aiCorners = $meta['ai_corners'] ?? [];
    $predictionUrl = $aiCorners['prediction_url'] ?? null;
    $cornersStatus = $aiCorners['status'] ?? null;
    
    if ($predictionUrl && is_string($predictionUrl) && $cornersStatus === 'in_progress') {
        // Poll the prediction once
        require_once __DIR__ . '/ai_calc_corners.php';
        $pollResult = poll_corner_prediction($jsonPath, 1.0);
        
        if (isset($pollResult['still_processing']) && $pollResult['still_processing']) {
            // Still processing - skip for now, will check again next run
            return ['ok' => true, 'still_processing' => true, 'message' => 'Prediction still in progress'];
        }
        
        if (isset($pollResult['completed']) && $pollResult['completed']) {
            // Prediction completed - corners are saved, now create final image
            $originalImage = null;
            $files = scandir($imagesDir) ?: [];
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') continue;
                $fileStem = pathinfo($file, PATHINFO_FILENAME);
                if (strpos($fileStem, $baseName.'_original') === 0) {
                    $originalImage = $imagesDir . '/' . $file;
                    break;
                }
            }
            
            if (!$originalImage || !is_file($originalImage)) {
                return ['ok' => false, 'error' => 'Original image not found'];
            }
            
            // Process the corners to create final image
            // calculate_corners() will use the cached succeeded response
            require_once __DIR__ . '/ai_image_by_corners.php';
            $imagePath = 'admin/images/' . basename($originalImage);
            $result = process_ai_image_by_corners($imagePath, 1.0);
            
            if ($result['ok']) {
                return ['ok' => true, 'result' => $result];
            } else {
                return ['ok' => false, 'error' => $result['error'] ?? 'Unknown error'];
            }
        }
        
        // Polling failed or prediction failed
        return ['ok' => false, 'error' => $pollResult['error'] ?? 'Polling failed'];
    }
    
    // Check if image generation is needed (flag set) or status is completed
    $imageGenerationNeeded = $aiCorners['image_generation_needed'] ?? false;
    
    if ($imageGenerationNeeded || $cornersStatus === 'completed') {
        $cornersUsed = $aiCorners['corners_used'] ?? null;
        $replicateResponse = $aiCorners['replicate_response'] ?? null;
        
        // If we have a Replicate response but no corners_used yet, process it first
        if ($replicateResponse && is_array($replicateResponse) && 
            isset($replicateResponse['status']) && 
            $replicateResponse['status'] === 'succeeded' &&
            (!$cornersUsed || !is_array($cornersUsed) || count($cornersUsed) !== 4)) {
            // Process the Replicate response to extract corners
            require_once __DIR__ . '/ai_calc_corners.php';
            $processResult = process_completed_corner_prediction($jsonPath, $replicateResponse, $aiCorners['offset_percent'] ?? 1.0);
            if (!$processResult['ok']) {
                return ['ok' => false, 'error' => 'Failed to process Replicate response: ' . ($processResult['error'] ?? 'Unknown')];
            }
            // Reload meta to get updated corners_used
            $meta = load_meta($imageFilename, $imagesDir);
            $aiCorners = $meta['ai_corners'] ?? [];
            $cornersUsed = $aiCorners['corners_used'] ?? null;
        }
        
        if ($cornersUsed && is_array($cornersUsed) && count($cornersUsed) === 4) {
            // Regenerate final image using the completed corners
            $originalImage = null;
            $files = scandir($imagesDir) ?: [];
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') continue;
                $fileStem = pathinfo($file, PATHINFO_FILENAME);
                if (strpos($fileStem, $baseName.'_original') === 0) {
                    $originalImage = $imagesDir . '/' . $file;
                    break;
                }
            }
            
            if (!$originalImage || !is_file($originalImage)) {
                return ['ok' => false, 'error' => 'Original image not found'];
            }
            
            // Process the corners to regenerate final image
            require_once __DIR__ . '/ai_image_by_corners.php';
            $imagePath = 'admin/images/' . basename($originalImage);
            $result = process_ai_image_by_corners($imagePath, $aiCorners['offset_percent'] ?? 1.0);
            
            if ($result['ok']) {
                // Clear the flag and set status to completed after successful generation
                $aiCorners['image_generation_needed'] = false;
                $aiCorners['status'] = 'completed';
                update_json_file($jsonPath, ['ai_corners' => $aiCorners], false);
                
                return ['ok' => true, 'result' => $result, 'message' => 'Final image regenerated'];
            } else {
                return ['ok' => false, 'error' => $result['error'] ?? 'Unknown error'];
            }
        }
    }
    
    // No prediction URL exists - start new prediction
    $originalImage = null;
    $files = scandir($imagesDir) ?: [];
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        $fileStem = pathinfo($file, PATHINFO_FILENAME);
        if (strpos($fileStem, $baseName.'_original') === 0) {
            $originalImage = $imagesDir . '/' . $file;
            break;
        }
    }
    
    if (!$originalImage || !is_file($originalImage)) {
        return ['ok' => false, 'error' => 'Original image not found'];
    }
    
    // Set status to in_progress
    update_task_status($jsonPath, 'ai_corners', 'in_progress');
    
    try {
        // Call function directly - it will start prediction and return early
        require_once __DIR__ . '/ai_image_by_corners.php';
        $imagePath = 'admin/images/' . basename($originalImage);
        $result = process_ai_image_by_corners($imagePath, 1.0);
        
        if ($result['ok']) {
            if (isset($result['prediction_started']) && $result['prediction_started']) {
                // Prediction started - will be polled in next run
                return ['ok' => true, 'prediction_started' => true, 'message' => 'Prediction started'];
            }
            // Status is already updated to completed in process_ai_image_by_corners
            return ['ok' => true, 'result' => $result];
        } else {
            // Status is already updated to wanted in process_ai_image_by_corners
            return ['ok' => false, 'error' => $result['error'] ?? 'Unknown error'];
        }
    } catch (Throwable $e) {
        update_task_status($jsonPath, 'ai_corners', 'wanted'); // Retry
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Process AI form fill
 */
function process_ai_form(string $baseName, string $jsonPath): array {
    $imagesDir = __DIR__ . '/images';
    
    // Load metadata to check for prediction URL
    $imageFilename = basename($jsonPath, '.json');
    $meta = load_meta($imageFilename, $imagesDir);
    
    // Check if prediction URL exists (regardless of status - could be wanted or in_progress)
    $aiFillForm = $meta['ai_fill_form'] ?? [];
    $predictionUrl = $aiFillForm['prediction_url'] ?? null;
    $formStatus = $aiFillForm['status'] ?? null;
    
    // If prediction URL exists, poll it (even if status is 'wanted' - prediction might have been started elsewhere)
    if ($predictionUrl && is_string($predictionUrl)) {
        // Update status to in_progress if it's still wanted (prediction was started but status wasn't updated)
        if ($formStatus === 'wanted') {
            update_task_status($jsonPath, 'ai_form', 'in_progress');
        }
        
        // Poll the prediction once
        require_once __DIR__ . '/ai_fill_form.php';
        $pollResult = poll_form_prediction($jsonPath);
        
        if (isset($pollResult['still_processing']) && $pollResult['still_processing']) {
            // Still processing - skip for now, will check again next run
            return ['ok' => true, 'still_processing' => true, 'message' => 'Prediction still in progress'];
        }
        
        if (isset($pollResult['completed']) && $pollResult['completed']) {
            // Prediction completed - form data already saved by poll_form_prediction
            return ['ok' => true, 'result' => $pollResult, 'completed' => true];
        }
        
        // Polling failed or prediction failed
        return ['ok' => false, 'error' => $pollResult['error'] ?? 'Polling failed'];
    }
    
    // No prediction URL exists - start new prediction
    // Find _final or _original image
    $imageFile = null;
    $files = scandir($imagesDir) ?: [];
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        $fileStem = pathinfo($file, PATHINFO_FILENAME);
        if (strpos($fileStem, $baseName.'_final') === 0) {
            $imageFile = $file;
            break;
        }
    }
    
    if (!$imageFile) {
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            $fileStem = pathinfo($file, PATHINFO_FILENAME);
            if (strpos($fileStem, $baseName.'_original') === 0) {
                $imageFile = $file;
                break;
            }
        }
    }
    
    if (!$imageFile) {
        return ['ok' => false, 'error' => 'Image not found'];
    }
    
    // Set status to in_progress
    update_task_status($jsonPath, 'ai_form', 'in_progress');
    
    try {
        // Call function directly - it will start prediction and return early
        require_once __DIR__ . '/ai_fill_form.php';
        $result = process_ai_fill_form($imageFile);
        
        if ($result['ok']) {
            if (isset($result['prediction_started']) && $result['prediction_started']) {
                // Prediction started - will be polled in next run
                return ['ok' => true, 'prediction_started' => true, 'message' => 'Prediction started'];
            }
            // Status is already updated to completed in process_ai_fill_form
            return ['ok' => true, 'result' => $result];
        } else {
            // Status is already updated to wanted in process_ai_fill_form
            return ['ok' => false, 'error' => $result['error'] ?? 'Unknown error'];
        }
    } catch (Throwable $e) {
        update_task_status($jsonPath, 'ai_form', 'wanted'); // Retry
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Poll and process AI painting variants generation (background task handler)
 */
function poll_ai_painting_variants(string $baseName, string $jsonPath): array {
    $imagesDir = __DIR__ . '/images';
    
    // Load metadata to check for painting variants status
    $imageFilename = basename($jsonPath, '.json');
    $meta = load_meta($imageFilename, $imagesDir);
    
    $aiPaintingVariants = $meta['ai_painting_variants'] ?? [];
    $variants = $aiPaintingVariants['variants'] ?? [];
    
    // Check if there are variants that need polling (in_progress with prediction_url)
    // Don't require global status to be in_progress - poll individual variants regardless
    if (!empty($variants)) {
        $allCompleted = true;
        $anyInProgress = false;
        
        foreach ($variants as $variantName => $variantInfo) {
            $variantStatus = $variantInfo['status'] ?? null;
            $predictionUrl = $variantInfo['prediction_url'] ?? null;
            
            if ($variantStatus === 'in_progress' && $predictionUrl) {
                // Poll this variant directly from Replicate API
                try {
                    $TOKEN = load_replicate_token();
                } catch (RuntimeException $e) {
                    $anyInProgress = true;
                    continue;
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
                    $anyInProgress = true;
                    continue;
                }
                
                $resp = json_decode($res, true);
                if (!is_array($resp)) {
                    $anyInProgress = true;
                    continue;
                }
                
                $predictionStatus = $resp['status'] ?? 'unknown';
                
                // Update variant info with latest response
                $variants[$variantName]['prediction_status'] = $predictionStatus;
                $variants[$variantName]['replicate_response_raw'] = json_encode($resp, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $variants[$variantName]['replicate_response'] = $resp;
                $variants[$variantName]['timestamp'] = date('c');
                
                if ($predictionStatus === 'succeeded') {
                    // Process completed prediction
                    $targetPath = $variantInfo['target_path'] ?? null;
                    if ($targetPath && is_string($targetPath)) {
                        // Extract image from response
                        require_once __DIR__ . '/utils.php';
                        $imgBytes = fetch_image_bytes($resp['output'] ?? null);
                        if ($imgBytes === null) {
                            if (is_array($resp['output']) && isset($resp['output']['images'][0])) {
                                $imgBytes = fetch_image_bytes($resp['output']['images'][0]);
                            }
                        }
                        
                        if ($imgBytes !== null) {
                            // Ensure target directory exists
                            $targetDir = dirname($targetPath);
                            if (!is_dir($targetDir)) {
                                mkdir($targetDir, 0755, true);
                            }
                            
                            file_put_contents($targetPath, $imgBytes);
                            
                            // Generate thumbnail
                            $thumbPath = generate_thumbnail_path($targetPath);
                            generate_thumbnail($targetPath, $thumbPath, 512, 1024);
                            
                            // Mark as completed
                            $variants[$variantName]['status'] = 'completed';
                            $variants[$variantName]['completed_at'] = date('c');
                        } else {
                            // Failed to extract image
                            $variants[$variantName]['status'] = 'wanted';
                            $allCompleted = false;
                        }
                    } else {
                        // No target path
                        $variants[$variantName]['status'] = 'wanted';
                        $allCompleted = false;
                    }
                } elseif ($predictionStatus === 'failed' || $predictionStatus === 'canceled') {
                    // Prediction failed - set status to error
                    $variants[$variantName]['status'] = 'error';
                    $allCompleted = false;
                } else {
                    // Still processing
                    $anyInProgress = true;
                }
            } elseif ($variantStatus === 'completed') {
                // Already completed
            } else {
                $allCompleted = false;
                if ($variantStatus === 'in_progress') {
                    $anyInProgress = true;
                }
            }
        }
        
        // Update painting metadata
        $aiPaintingVariants['variants'] = $variants;
        if ($allCompleted) {
            $aiPaintingVariants['image_generation_needed'] = false;
        }
        update_json_file($jsonPath, ['ai_painting_variants' => $aiPaintingVariants], false);
        
        if ($anyInProgress) {
            return ['ok' => true, 'still_processing' => true, 'message' => 'Variants still in progress'];
        }
        
        if ($allCompleted) {
            return ['ok' => true, 'completed' => true, 'message' => 'All variants completed'];
        }
    }
    
    // Don't automatically generate variants - only process existing ones
    // Variants should only be generated when explicitly requested via add_variant_to_queue.php
    // or when manually triggered through the UI
    
    return ['ok' => true, 'skipped' => true, 'message' => 'No action needed'];
}

/**
 * Process individual variant predictions (from variants/ directory)
 */
function process_individual_variants(): array {
    $variantsDir = __DIR__ . '/variants';
    if (!is_dir($variantsDir)) {
        return ['ok' => true, 'processed' => 0, 'message' => 'Variants directory not found'];
    }
    
    $files = scandir($variantsDir) ?: [];
    $processed = 0;
    $errors = [];
    
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        if (pathinfo($file, PATHINFO_EXTENSION) !== 'json') continue;
        
        $variantJsonPath = $variantsDir . '/' . $file;
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
        
        $status = $variantMeta['status'] ?? null;
        $predictionUrl = $variantMeta['prediction_url'] ?? null;
        
        // Only process variants that are in_progress and have a prediction URL
        if ($status === 'in_progress' && $predictionUrl) {
            require_once __DIR__ . '/ai_variant_by_prompt.php';
            $pollResult = poll_variant_prediction($variantJsonPath);
            
            if (isset($pollResult['completed']) && $pollResult['completed']) {
                $processed++;
            } elseif (isset($pollResult['still_processing']) && $pollResult['still_processing']) {
                // Still processing - skip for now
                continue;
            } else {
                $errors[] = [
                    'variant' => $variantMeta['variant_name'] ?? $file,
                    'error' => $pollResult['error'] ?? 'Unknown error'
                ];
            }
        }
    }
    
    return [
        'ok' => true,
        'processed' => $processed,
        'errors' => $errors
    ];
}

/**
 * Process variant generation (create variants that are in active_variants but don't exist as files)
 * Uses process_ai_painting_variants for async generation instead of synchronous generation
 */
function process_variant_generation(string $baseName, string $jsonPath, string $imagesDir): array {
    // Load metadata to get active variants
    $meta = [];
    if (is_file($jsonPath)) {
        $content = @file_get_contents($jsonPath);
        if ($content !== false) {
            $decoded = json_decode($content, true);
            if (is_array($decoded)) {
                $meta = $decoded;
            }
        }
    }
    
    // Get active_variants from ai_painting_variants object
    $aiPaintingVariants = $meta['ai_painting_variants'] ?? [];
    $activeVariants = isset($aiPaintingVariants['active_variants']) && is_array($aiPaintingVariants['active_variants']) 
        ? $aiPaintingVariants['active_variants'] 
        : [];
    
    if (empty($activeVariants)) {
        return ['ok' => true, 'created' => [], 'errors' => []];
    }
    
    // Find missing variants (those in active_variants but don't exist as files)
    $missingVariants = [];
    foreach ($activeVariants as $variantName) {
        $variantFile = $baseName . '_variant_' . $variantName . '.jpg';
        $variantPath = $imagesDir . '/' . $variantFile;
        
        // Check if file doesn't exist
        if (!is_file($variantPath)) {
            // Check if variant is already being tracked in variants object (async generation in progress)
            $trackedVariants = $aiPaintingVariants['variants'] ?? [];
            $variantTracked = isset($trackedVariants[$variantName]);
            $variantStatus = $variantTracked ? ($trackedVariants[$variantName]['status'] ?? null) : null;
            
            // Only add to missing if not tracked or if tracked but not in progress (failed/wanted)
            if (!$variantTracked || ($variantStatus !== 'in_progress' && $variantStatus !== 'completed')) {
                $missingVariants[] = $variantName;
            }
        }
    }
    
    if (empty($missingVariants)) {
        return ['ok' => true, 'created' => [], 'errors' => []];
    }
    
    // Use process_ai_painting_variants to start async generation for missing variants
    require_once __DIR__ . '/ai_painting_variants.php';
    $result = process_ai_painting_variants($baseName, $missingVariants);
    
    if ($result['ok']) {
        $created = [];
        $errors = [];
        
        // Extract started variants and errors from result
        if (isset($result['started']) && $result['started'] > 0) {
            // Variants were started - they will be processed asynchronously
            // We can't return the actual created files yet, but we can return the variant names that were started
            $created = $missingVariants; // All missing variants were attempted
        }
        
        if (!empty($result['errors'])) {
            foreach ($result['errors'] as $error) {
                $errors[] = ($error['variant'] ?? 'unknown') . ': ' . ($error['error'] ?? 'Unknown error');
            }
        }
        
        return [
            'ok' => true,
            'created' => $created,
            'errors' => $errors,
            'message' => $result['message'] ?? 'Variants generation started'
        ];
    } else {
        return [
            'ok' => false,
            'error' => $result['error'] ?? 'Unknown error',
            'created' => [],
            'errors' => []
        ];
    }
}

/**
 * Clean up orphaned variant files (files that exist but aren't in active_variants)
 */
function cleanup_orphaned_variants(string $baseName, string $jsonPath, string $imagesDir): array {
    $cleaned = [];
    $errors = [];
    
    // Load metadata to get active variants
    $meta = [];
    if (is_file($jsonPath)) {
        $content = @file_get_contents($jsonPath);
        if ($content !== false) {
            $decoded = json_decode($content, true);
            if (is_array($decoded)) {
                $meta = $decoded;
            }
        }
    }
    
    // Get active_variants from ai_painting_variants object
    $aiPaintingVariants = $meta['ai_painting_variants'] ?? [];
    $activeVariants = isset($aiPaintingVariants['active_variants']) && is_array($aiPaintingVariants['active_variants']) 
        ? $aiPaintingVariants['active_variants'] 
        : [];
    
    // Find all variant files for this base
    $files = scandir($imagesDir) ?: [];
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        $fileStem = pathinfo($file, PATHINFO_FILENAME);
        
        // Check if this is a variant file (not a thumbnail)
        $pattern = '/^' . preg_quote($baseName, '/') . '_variant_(.+)$/';
        if (preg_match($pattern, $fileStem, $matches) && strpos($fileStem, '_thumb') === false) {
            $variantName = $matches[1];
            
            // If variant is not in active list, delete it
            if (!in_array($variantName, $activeVariants, true)) {
                $filePath = $imagesDir . '/' . $file;
                if (is_file($filePath)) {
                    if (@unlink($filePath)) {
                        $cleaned[] = $file;
                        
                        // Also delete thumbnail if it exists
                        $pathInfo = pathinfo($filePath);
                        $thumbPath = $pathInfo['dirname'] . '/' . $pathInfo['filename'] . '_thumb.' . $pathInfo['extension'];
                        if (is_file($thumbPath)) {
                            @unlink($thumbPath);
                        }
                    } else {
                        $errors[] = $file;
                    }
                }
            }
        }
    }
    
    return ['ok' => true, 'cleaned' => $cleaned, 'errors' => $errors];
}

/**
 * Process gallery publishing (use existing optimize_images logic)
 */
function process_gallery_publishing(string $baseName, string $jsonPath): array {
    try {
        // Call function directly instead of HTTP
        require_once __DIR__ . '/optimize_images.php';
        process_images('both', false); // Process both optimize and gallery
        
        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

// Main processing loop - Sequential processing:
// 1. First: Process all AI corners tasks
// 2. Second: Process all AI form tasks
// 3. Third: Process variant generation/regeneration

$files = scandir($imagesDir) ?: [];
$processedCount = 0;
$maxProcessPerRun = 10; // Limit to prevent timeout

error_log('[Background Tasks] Scanning images directory: ' . $imagesDir);
error_log('[Background Tasks] Found ' . count($files) . ' files in directory');
error_log('[Background Tasks] Max tasks per run: ' . $maxProcessPerRun);

// Collect all JSON files first
$jsonFiles = [];
foreach ($files as $file) {
    if ($file === '.' || $file === '..') continue;
    if (pathinfo($file, PATHINFO_EXTENSION) !== 'json') continue;
    
    // Check if it's an _original.json file
    $stem = pathinfo($file, PATHINFO_FILENAME);
    if (!preg_match('/_original\.jpg$/', $stem)) {
        continue;
    }
    
    $baseName = preg_replace('/_original\.jpg$/', '', $stem);
    $jsonPath = $imagesDir . '/' . $file;
    
    $imageFilename = basename($file, '.json');
    $meta = load_meta($imageFilename, $imagesDir);
    if (empty($meta)) continue;
    
    $jsonFiles[] = [
        'baseName' => $baseName,
        'jsonPath' => $jsonPath,
        'meta' => $meta,
        'file' => $file
    ];
}

$totalJsonFiles = count($jsonFiles);
error_log('[Background Tasks] Found ' . $totalJsonFiles . ' valid JSON files to process');

// ============================================
// PHASE 1: Process all AI corners tasks first
// ============================================
error_log('[Background Tasks] === PHASE 1: Processing AI corners tasks ===');
foreach ($jsonFiles as $item) {
    if ($processedCount >= $maxProcessPerRun) {
        error_log('[Background Tasks] Processing limit reached (' . $maxProcessPerRun . '), stopping phase 1');
        break;
    }
    
    $baseName = $item['baseName'];
    $jsonPath = $item['jsonPath'];
    $meta = $item['meta'];
    
    $aiNeeds = check_ai_generation_needed($meta);
    if (!$aiNeeds['corners']) {
        continue;
    }
    
    $aiCorners = $meta['ai_corners'] ?? [];
    $cornersStatus = $aiCorners['status'] ?? null;
    error_log('[Background Tasks] ' . $baseName . ': AI corners status: ' . ($cornersStatus ?? 'null'));
    
    // Check if there's a prediction URL - if so, we should poll it even if task is in_progress
    $hasPredictionUrl = isset($aiCorners['prediction_url']) && 
                        is_string($aiCorners['prediction_url']);
    
    // Only skip if in_progress AND not stale AND no prediction_url (meaning it's actively being processed synchronously)
    if ($cornersStatus === 'in_progress' && is_task_in_progress($meta, 'ai_corners') && !$hasPredictionUrl) {
        error_log('[Background Tasks] ' . $baseName . ': Skipping AI corners (already in progress, no prediction URL)');
        $originalImageFile = null;
        $files = scandir($imagesDir) ?: [];
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            $fileStem = pathinfo($file, PATHINFO_FILENAME);
            if (strpos($fileStem, $baseName.'_original') === 0) {
                $originalImageFile = $file;
                break;
            }
        }
        $results['skipped'][] = [
            'base' => $baseName,
            'task' => 'ai_corners',
            'reason' => 'in_progress',
            'image' => $originalImageFile ?? $baseName . '_original.jpg',
            'ai_task' => 'corner_detection',
            'status' => $cornersStatus
        ];
        continue;
    }
    
    // Find original image filename for reporting
    $originalImageFile = null;
    $files = scandir($imagesDir) ?: [];
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        $fileStem = pathinfo($file, PATHINFO_FILENAME);
        if (strpos($fileStem, $baseName.'_original') === 0) {
            $originalImageFile = $file;
            break;
        }
    }
    
    error_log('[Background Tasks] ' . $baseName . ': Processing AI corners (image: ' . ($originalImageFile ?? $baseName . '_original.jpg') . ')');
    $result = process_ai_corners($baseName, $jsonPath);
    if ($result['ok']) {
        if (isset($result['still_processing']) && $result['still_processing']) {
            error_log('[Background Tasks] ' . $baseName . ': AI corners still processing, will check again next run');
            $results['skipped'][] = [
                'base' => $baseName,
                'task' => 'ai_corners',
                'reason' => 'still_processing',
                'image' => $originalImageFile ?? $baseName . '_original.jpg',
                'ai_task' => 'corner_detection'
            ];
            continue;
        }
        error_log('[Background Tasks] ' . $baseName . ': AI corners completed successfully');
        $results['processed'][] = [
            'base' => $baseName,
            'task' => 'ai_corners',
            'status' => 'success',
            'image' => $originalImageFile ?? $baseName . '_original.jpg',
            'ai_task' => 'corner_detection',
            'description' => 'AI corner detection and image cropping'
        ];
        $processedCount++;
        // Reload meta after corners processing (it may have changed)
        $imageFilename = basename($jsonPath, '.json');
        $imagesDir = dirname($jsonPath);
        $item['meta'] = load_meta($imageFilename, $imagesDir);
    } else {
        error_log('[Background Tasks] ' . $baseName . ': AI corners failed: ' . ($result['error'] ?? 'Unknown error'));
        $results['errors'][] = [
            'base' => $baseName,
            'task' => 'ai_corners',
            'error' => $result['error'],
            'image' => $originalImageFile ?? $baseName . '_original.jpg',
            'ai_task' => 'corner_detection'
        ];
    }
}

// ============================================
// PHASE 2: Process all AI form tasks
// ============================================
error_log('[Background Tasks] === PHASE 2: Processing AI form tasks ===');
foreach ($jsonFiles as $item) {
    if ($processedCount >= $maxProcessPerRun) {
        error_log('[Background Tasks] Processing limit reached (' . $maxProcessPerRun . '), stopping phase 2');
        break;
    }
    
    $baseName = $item['baseName'];
    $jsonPath = $item['jsonPath'];
    $meta = $item['meta'];
    
    $aiNeeds = check_ai_generation_needed($meta);
    if (!$aiNeeds['form']) {
        continue;
    }
    
    $aiFillForm = $meta['ai_fill_form'] ?? [];
    $formStatus = $aiFillForm['status'] ?? null;
    error_log('[Background Tasks] ' . $baseName . ': AI form status: ' . ($formStatus ?? 'null'));
    
    // Check if there's a prediction URL - if so, we should poll it even if task is in_progress
    $hasPredictionUrl = isset($aiFillForm['prediction_url']) && 
                        is_string($aiFillForm['prediction_url']);
    
    // Only skip if in_progress AND not stale AND no prediction_url (meaning it's actively being processed synchronously)
    if ($formStatus === 'in_progress' && is_task_in_progress($meta, 'ai_form') && !$hasPredictionUrl) {
        error_log('[Background Tasks] ' . $baseName . ': Skipping AI form (already in progress, no prediction URL)');
        $imageFile = null;
        $files = scandir($imagesDir) ?: [];
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            $fileStem = pathinfo($file, PATHINFO_FILENAME);
            if (strpos($fileStem, $baseName.'_final') === 0) {
                $imageFile = $file;
                break;
            }
        }
        if (!$imageFile) {
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') continue;
                $fileStem = pathinfo($file, PATHINFO_FILENAME);
                if (strpos($fileStem, $baseName.'_original') === 0) {
                    $imageFile = $file;
                    break;
                }
            }
        }
        $results['skipped'][] = [
            'base' => $baseName,
            'task' => 'ai_form',
            'reason' => 'in_progress',
            'image' => $imageFile ?? $baseName . '_final.jpg',
            'ai_task' => 'form_filling',
            'status' => $formStatus
        ];
        continue;
    }
    
    // Find image filename for reporting
    $imageFile = null;
    $files = scandir($imagesDir) ?: [];
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        $fileStem = pathinfo($file, PATHINFO_FILENAME);
        if (strpos($fileStem, $baseName.'_final') === 0) {
            $imageFile = $file;
            break;
        }
    }
    if (!$imageFile) {
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            $fileStem = pathinfo($file, PATHINFO_FILENAME);
            if (strpos($fileStem, $baseName.'_original') === 0) {
                $imageFile = $file;
                break;
            }
        }
    }
    
    error_log('[Background Tasks] ' . $baseName . ': Processing AI form (image: ' . ($imageFile ?? $baseName . '_final.jpg') . ')');
    $result = process_ai_form($baseName, $jsonPath);
    if ($result['ok']) {
        if (isset($result['still_processing']) && $result['still_processing']) {
            error_log('[Background Tasks] ' . $baseName . ': AI form still processing, will check again next run');
            $results['skipped'][] = [
                'base' => $baseName,
                'task' => 'ai_form',
                'reason' => 'still_processing',
                'image' => $imageFile ?? $baseName . '_final.jpg',
                'ai_task' => 'form_filling'
            ];
            continue;
        }
        $extractedFields = isset($result['result']) ? array_keys($result['result']) : [];
        error_log('[Background Tasks] ' . $baseName . ': AI form completed successfully (extracted fields: ' . implode(', ', $extractedFields) . ')');
        $results['processed'][] = [
            'base' => $baseName,
            'task' => 'ai_form',
            'status' => 'success',
            'image' => $imageFile ?? $baseName . '_final.jpg',
            'ai_task' => 'form_filling',
            'description' => 'AI form filling (title, description, tags, dimensions, date)',
            'extracted_fields' => $extractedFields
        ];
        $processedCount++;
    } else {
        error_log('[Background Tasks] ' . $baseName . ': AI form failed: ' . ($result['error'] ?? 'Unknown error'));
        $results['errors'][] = [
            'base' => $baseName,
            'task' => 'ai_form',
            'error' => $result['error'],
            'image' => $imageFile ?? $baseName . '_final.jpg',
            'ai_task' => 'form_filling'
        ];
    }
}

// ============================================
// PHASE 2.5: Process AI painting variants
// ============================================
error_log('[Background Tasks] === PHASE 2.5: Processing AI painting variants ===');
foreach ($jsonFiles as $item) {
    if ($processedCount >= $maxProcessPerRun) {
        error_log('[Background Tasks] Processing limit reached (' . $maxProcessPerRun . '), stopping phase 2.5');
        break;
    }
    
    $baseName = $item['baseName'];
    $jsonPath = $item['jsonPath'];
    $meta = $item['meta'];
    
    // Reload meta to get latest status
    $imageFilename = basename($jsonPath, '.json');
    $meta = load_meta($imageFilename, $imagesDir);
    
    $aiPaintingVariants = $meta['ai_painting_variants'] ?? [];
    $variants = $aiPaintingVariants['variants'] ?? [];
    
    // Check if there are any variants that need polling (in_progress with prediction_url)
    $hasInProgressVariants = false;
    foreach ($variants as $variantInfo) {
        $variantStatus = $variantInfo['status'] ?? null;
        $predictionUrl = $variantInfo['prediction_url'] ?? null;
        if ($variantStatus === 'in_progress' && $predictionUrl) {
            $hasInProgressVariants = true;
            break;
        }
    }
    
    // Get aggregate status by iterating over variants
    require_once __DIR__ . '/utils.php';
    $statusInfo = get_ai_painting_variants_status($aiPaintingVariants);
    $status = $statusInfo['status'];
    
    // Skip if not in_progress/wanted/null AND no variants need polling
    if (($status !== 'in_progress' && $status !== 'wanted' && $status !== null) && !$hasInProgressVariants) {
        continue;
    }
    
    error_log('[Background Tasks] ' . $baseName . ': Processing AI painting variants (status: ' . ($status ?? 'null') . ', has_in_progress: ' . ($hasInProgressVariants ? 'yes' : 'no') . ')');
    $result = poll_ai_painting_variants($baseName, $jsonPath);
    
    if ($result['ok']) {
        if (isset($result['still_processing']) && $result['still_processing']) {
            error_log('[Background Tasks] ' . $baseName . ': AI painting variants still processing, will check again next run');
            $results['skipped'][] = [
                'base' => $baseName,
                'task' => 'ai_painting_variants',
                'reason' => 'still_processing'
            ];
            continue;
        }
        if (isset($result['completed']) && $result['completed']) {
            error_log('[Background Tasks] ' . $baseName . ': AI painting variants completed successfully');
            $results['processed'][] = [
                'base' => $baseName,
                'task' => 'ai_painting_variants',
                'status' => 'success'
            ];
            $processedCount++;
        } elseif (isset($result['started']) && $result['started']) {
            error_log('[Background Tasks] ' . $baseName . ': AI painting variants started (' . $result['started'] . ' variants)');
            $results['processed'][] = [
                'base' => $baseName,
                'task' => 'ai_painting_variants',
                'status' => 'started',
                'started' => $result['started']
            ];
        }
    } else {
        error_log('[Background Tasks] ' . $baseName . ': AI painting variants failed: ' . ($result['error'] ?? 'Unknown error'));
        $results['errors'][] = [
            'base' => $baseName,
            'task' => 'ai_painting_variants',
            'error' => $result['error']
        ];
    }
}

// ============================================
// PHASE 2.6: Process individual variant predictions
// ============================================
error_log('[Background Tasks] === PHASE 2.6: Processing individual variant predictions ===');
$variantResult = process_individual_variants();
if ($variantResult['ok'] && $variantResult['processed'] > 0) {
    error_log('[Background Tasks] Processed ' . $variantResult['processed'] . ' individual variant(s)');
    $results['processed'][] = [
        'task' => 'individual_variants',
        'status' => 'success',
        'processed' => $variantResult['processed']
    ];
    if (!empty($variantResult['errors'])) {
        foreach ($variantResult['errors'] as $error) {
            $results['errors'][] = [
                'task' => 'individual_variants',
                'error' => $error['error'],
                'variant' => $error['variant']
            ];
        }
    }
}

// ============================================
// PHASE 3: Process variant generation/regeneration (only if no AI tasks pending)
// ============================================
error_log('[Background Tasks] === PHASE 3: Processing variant tasks ===');
foreach ($jsonFiles as $item) {
    if ($processedCount >= $maxProcessPerRun) {
        error_log('[Background Tasks] Processing limit reached (' . $maxProcessPerRun . '), stopping phase 3');
        break;
    }
    
    $baseName = $item['baseName'];
    $jsonPath = $item['jsonPath'];
    $meta = $item['meta'];
    
    // Reload meta to get latest status after AI processing
    $imageFilename = basename($jsonPath, '.json');
    $imagesDir = dirname($jsonPath);
    $meta = load_meta($imageFilename, $imagesDir);
    
    // Check if there are still pending AI tasks - if so, skip variants
    $aiNeeds = check_ai_generation_needed($meta);
    if ($aiNeeds['corners'] || $aiNeeds['form']) {
        error_log('[Background Tasks] ' . $baseName . ': Skipping variants (AI tasks still pending)');
        continue;
    }
    
    // Check variant generation (create variants that are in active_variants but don't exist)
    $aiPaintingVariants = $meta['ai_painting_variants'] ?? [];
    $activeVariants = $aiPaintingVariants['active_variants'] ?? [];
    $trackedVariants = $aiPaintingVariants['variants'] ?? [];
    if (!empty($activeVariants) && is_array($activeVariants)) {
        // Check if any variant files are missing
        // But skip variants that are already being tracked in variants object (they're being processed async)
        $missingVariants = [];
        foreach ($activeVariants as $variantName) {
            $variantFile = $baseName . '_variant_' . $variantName . '.jpg';
            $variantPath = $imagesDir . '/' . $variantFile;
            if (!is_file($variantPath)) {
                // Check if this variant is already being tracked in variants object
                $variantTracked = isset($trackedVariants[$variantName]);
                $variantStatus = $variantTracked ? ($trackedVariants[$variantName]['status'] ?? null) : null;
                
                // Only add to missing if not tracked or if tracked but not in progress (failed/wanted)
                if (!$variantTracked || ($variantStatus !== 'in_progress' && $variantStatus !== 'completed')) {
                    $missingVariants[] = $variantName;
                }
            }
        }
        
        if (!empty($missingVariants)) {
            error_log('[Background Tasks] ' . $baseName . ': Missing ' . count($missingVariants) . ' variant(s): ' . implode(', ', $missingVariants));
            
            // Find final image filename for reporting
            $finalImageFile = null;
            $files = scandir($imagesDir) ?: [];
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') continue;
                $fileStem = pathinfo($file, PATHINFO_FILENAME);
                if (strpos($fileStem, $baseName.'_final') === 0) {
                    $finalImageFile = $file;
                    break;
                }
            }
            
            error_log('[Background Tasks] ' . $baseName . ': Processing variant generation for ' . count($missingVariants) . ' missing variant(s)');
            $result = process_variant_generation($baseName, $jsonPath, $imagesDir);
            if ($result['ok']) {
                if (!empty($result['created'])) {
                    error_log('[Background Tasks] ' . $baseName . ': Successfully created ' . count($result['created']) . ' variant(s): ' . implode(', ', $result['created']));
                    $results['processed'][] = [
                        'base' => $baseName,
                        'task' => 'variant_generation',
                        'status' => 'success',
                        'image' => $finalImageFile ?? $baseName . '_final.jpg',
                        'created' => count($result['created']),
                        'variants' => $result['created']
                    ];
                    $processedCount++;
                }
                if (!empty($result['errors'])) {
                    error_log('[Background Tasks] ' . $baseName . ': Variant generation errors: ' . json_encode($result['errors']));
                    $results['errors'][] = [
                        'base' => $baseName,
                        'task' => 'variant_generation',
                        'error' => 'Some variants failed',
                        'image' => $finalImageFile ?? $baseName . '_final.jpg',
                        'details' => $result['errors']
                    ];
                }
            } else {
                error_log('[Background Tasks] ' . $baseName . ': Variant generation failed: ' . ($result['error'] ?? 'Unknown error'));
                $results['errors'][] = [
                    'base' => $baseName,
                    'task' => 'variant_generation',
                    'error' => $result['error'] ?? 'Unknown error',
                    'image' => $finalImageFile ?? $baseName . '_final.jpg'
                ];
            }
            continue; // Skip regeneration check if we just generated variants
        }
    }
    
    // Check variant regeneration (regenerate existing variants if _final is newer)
    if (check_variant_regeneration_needed($baseName, $jsonPath, $imagesDir)) {
        error_log('[Background Tasks] ' . $baseName . ': Variant regeneration needed');
        if (is_task_in_progress($meta, 'variant_regeneration')) {
            error_log('[Background Tasks] ' . $baseName . ': Skipping variant regeneration (already in progress)');
            $results['skipped'][] = ['base' => $baseName, 'task' => 'variant_regeneration', 'reason' => 'in_progress'];
        } else {
            error_log('[Background Tasks] ' . $baseName . ': Processing variant regeneration');
            $result = process_variant_regeneration($baseName, $jsonPath);
            if ($result['ok']) {
                error_log('[Background Tasks] ' . $baseName . ': Variant regeneration completed successfully');
                $results['processed'][] = ['base' => $baseName, 'task' => 'variant_regeneration', 'status' => 'success'];
                $processedCount++;
            } else {
                error_log('[Background Tasks] ' . $baseName . ': Variant regeneration failed: ' . ($result['error'] ?? 'Unknown error'));
                $results['errors'][] = ['base' => $baseName, 'task' => 'variant_regeneration', 'error' => $result['error']];
            }
        }
    }
    
    // Clean up orphaned variants (files that exist but aren't in active_variants)
    $cleanupResult = cleanup_orphaned_variants($baseName, $jsonPath, $imagesDir);
    if (!empty($cleanupResult['cleaned'])) {
        error_log('[Background Tasks] ' . $baseName . ': Cleaned up ' . count($cleanupResult['cleaned']) . ' orphaned variant file(s): ' . implode(', ', $cleanupResult['cleaned']));
        $results['processed'][] = ['base' => $baseName, 'task' => 'variant_cleanup', 'status' => 'success', 'cleaned' => count($cleanupResult['cleaned'])];
    }
    if (!empty($cleanupResult['errors'])) {
        error_log('[Background Tasks] ' . $baseName . ': Variant cleanup errors: ' . json_encode($cleanupResult['errors']));
        $results['errors'][] = ['base' => $baseName, 'task' => 'variant_cleanup', 'error' => 'Failed to delete some files', 'files' => $cleanupResult['errors']];
    }
}

error_log('[Background Tasks] Finished processing. Total JSON files checked: ' . $totalJsonFiles);
error_log('[Background Tasks] Tasks processed: ' . $processedCount . ' / ' . $maxProcessPerRun);

// Add summary with detailed breakdown
$aiTasks = array_filter($results['processed'], function($item) {
    return in_array($item['task'], ['ai_corners', 'ai_form']);
});
$aiErrors = array_filter($results['errors'], function($item) {
    return in_array($item['task'], ['ai_corners', 'ai_form']);
});
$aiSkipped = array_filter($results['skipped'], function($item) {
    return in_array($item['task'], ['ai_corners', 'ai_form']);
});

$results['summary'] = [
    'processed_count' => count($results['processed']),
    'skipped_count' => count($results['skipped']),
    'error_count' => count($results['errors']),
    'ai_tasks' => [
        'processed' => count($aiTasks),
        'errors' => count($aiErrors),
        'skipped' => count($aiSkipped),
        'total' => count($aiTasks) + count($aiErrors) + count($aiSkipped)
    ],
    'images_affected' => array_unique(array_merge(
        array_column($results['processed'], 'base'),
        array_column($results['errors'], 'base'),
        array_column($results['skipped'], 'base')
    ))
];

error_log('[Background Tasks] Summary:');
error_log('[Background Tasks]   - Processed: ' . $results['summary']['processed_count']);
error_log('[Background Tasks]   - Skipped: ' . $results['summary']['skipped_count']);
error_log('[Background Tasks]   - Errors: ' . $results['summary']['error_count']);
error_log('[Background Tasks]   - AI Tasks: ' . $results['summary']['ai_tasks']['processed'] . ' processed, ' . $results['summary']['ai_tasks']['errors'] . ' errors, ' . $results['summary']['ai_tasks']['skipped'] . ' skipped');
error_log('[Background Tasks]   - Images affected: ' . count($results['summary']['images_affected']) . ' (' . implode(', ', $results['summary']['images_affected']) . ')');

// Remove lock file if it was set
if ($isPreview && is_file($lockFile)) {
    @unlink($lockFile);
}

// Always output JSON response
echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);


