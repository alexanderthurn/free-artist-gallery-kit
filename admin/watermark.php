<?php
// watermark.php
header('Content-Type: application/json; charset=utf-8');

$response = [
    'success' => false,
    'error'   => null,
    'output'  => null,
];

try {
    if (!extension_loaded('imagick')) {
        throw new Exception('Imagick extension not loaded');
    }

    $file    = $_GET['file'] ?? null;
    $text    = $_GET['text'] ?? null;

    if (!$file || !$text) {
        throw new Exception('Missing required parameters: file, text');
    }

    // optionale Parameter
    $fontSize   = isset($_GET['size']) ? (int)$_GET['size'] : 24;
    $opacity    = isset($_GET['opacity']) ? (float)$_GET['opacity'] : 0.5; // 0..1
    $margin     = isset($_GET['margin']) ? (int)$_GET['margin'] : 20;
    $font       = $_GET['font'] ?? null;

    $baseDir   = __DIR__;
    $inputPath = realpath($baseDir . '/' . $file);

    if ($inputPath === false || strpos($inputPath, $baseDir) !== 0) {
        throw new Exception('Invalid file path');
    }
    if (!file_exists($inputPath)) {
        throw new Exception('File not found');
    }

    $pathInfo   = pathinfo($inputPath);
    $outputPath = $pathInfo['dirname'] . '/' . $pathInfo['filename'] . '_watermark.' . $pathInfo['extension'];

    $img = new Imagick($inputPath);
    $img->setImageAlphaChannel(Imagick::ALPHACHANNEL_ACTIVATE);

    // === WASSERZEICHEN MODUS: unten rechts ===
    $imgWidth  = $img->getImageWidth();
    $imgHeight = $img->getImageHeight();
    
    $draw = new ImagickDraw();
    // Schriftgröße relativ zur Bildgröße für bessere Skalierung
    $autoFontSize = max(16, min(32, (int)($imgWidth * 0.02)));
    $draw->setFontSize($autoFontSize);
    if ($font) {
        $draw->setFont($font);
    }

    // Textmaße holen
    $metrics    = $img->queryFontMetrics($draw, $text);
    $textWidth  = $metrics['textWidth'];
    $textHeight = $metrics['textHeight'];
    $textAscent = $metrics['ascender'];
    
    // Position unten rechts
    $padding = 15;
    $boxPadding = 4; // Kleinerer Rahmen
    $x = $imgWidth - $textWidth - $margin - $boxPadding;
    $y = $imgHeight - $margin;
    
    // Erstelle einen halbtransparenten Hintergrund-Box für bessere Lesbarkeit
    $boxWidth = $textWidth + ($boxPadding * 2);
    $boxHeight = $textHeight + ($boxPadding * 2);
    $boxX = $imgWidth - $boxWidth - $margin;
    $boxY = $imgHeight - $boxHeight - $margin;
    
    // Zeichne einen kräftigeren dunklen Hintergrund mit abgerundeten Ecken
    $bgDraw = new ImagickDraw();
    $bgColor = new ImagickPixel('rgba(0, 0, 0, 0.0)'); // 75% schwarz - kräftiger
    $bgDraw->setFillColor($bgColor);
    $bgDraw->setStrokeWidth(0);
    // Verwende rectangle statt roundRectangle für Kompatibilität
   // $bgDraw->rectangle($boxX, $boxY, $boxX + $boxWidth, $boxY + $boxHeight);
    $img->drawImage($bgDraw);
    
    // Weiße Schrift ohne schwarzen Rand, aber mit subtilem Schatten für Tiefe
    $fillColor = new ImagickPixel('white');
    $fillColor->setColorValue(Imagick::COLOR_ALPHA, 1); // 95% deckend
    $draw->setFillColor($fillColor);
    
    // Subtiler Schatten statt schwarzer Rand
    $draw->setStrokeColor('rgba(0, 0, 0, 0.0)');
    $draw->setStrokeWidth(0.0);
    
    // Text positionieren (zentriert im Box)
    $textX = $boxX + $boxPadding;
    $textY = $boxY + $boxPadding + $textAscent;

    $img->annotateImage($draw, $textX, $textY, 0, $text);
    
    $bgDraw->destroy();

    $img->writeImage($outputPath);
    $img->destroy();

    $response['success'] = true;
    $response['output']  = str_replace($baseDir . '/', '', $outputPath);

} catch (Throwable $e) {
    $response['error'] = $e->getMessage();
}

echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
