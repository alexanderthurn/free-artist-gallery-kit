<?php
if (!extension_loaded('imagick')) {
  http_response_code(500);
  die(json_encode(['error' => 'Imagick not available']));
}

$imgPath = $_FILES['image']['tmp_name'] ?? null;
$ptsJson = $_POST['points'] ?? null;
if (!$imgPath || !$ptsJson) {
  http_response_code(400);
  die(json_encode(['error' => 'image + points required']));
}
$pts = json_decode($ptsJson, true);
foreach (['tl','tr','br','bl'] as $k) {
  if (!isset($pts[$k][0], $pts[$k][1])) {
    http_response_code(400);
    die(json_encode(['error' => "missing corner $k"]));
  }
}

function warp(string $srcPath, array $p): string {
  $im = new Imagick($srcPath);

  $w = (int)round(max(
    hypot($p['tr'][0]-$p['tl'][0], $p['tr'][1]-$p['tl'][1]),
    hypot($p['br'][0]-$p['bl'][0], $p['br'][1]-$p['bl'][1])
  ));
  $h = (int)round(max(
    hypot($p['bl'][0]-$p['tl'][0], $p['bl'][1]-$p['tl'][1]),
    hypot($p['br'][0]-$p['tr'][0], $p['br'][1]-$p['tr'][1])
  ));
  $w = max($w, 64); 
  $h = max($h, 64);

  $args = [
    $p['tl'][0], $p['tl'][1],   0,     0,
    $p['tr'][0], $p['tr'][1],   $w-1,  0,
    $p['br'][0], $p['br'][1],   $w-1,  $h-1,
    $p['bl'][0], $p['bl'][1],   0,     $h-1
  ];

  $im->setImageColorspace(Imagick::COLORSPACE_SRGB);
  $im->setImageAlphaChannel(Imagick::ALPHACHANNEL_SET);
  $im->distortImage(Imagick::DISTORTION_PERSPECTIVE, $args, true);
  $im->extentImage($w, $h, 0, 0);

  // JPEG-Ausgabe
  $im->setImageCompression(Imagick::COMPRESSION_JPEG);
  $im->setImageCompressionQuality(90);
  $im->setImageFormat('jpeg');

  $out = $im->getImageBlob();
  $im->clear(); 
  $im->destroy();
  return $out;
}

$outJpg = warp($imgPath, $pts);
header('Content-Type: image/jpeg');
echo $outJpg;
