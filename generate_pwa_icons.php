<?php
// Generate PWA icons from logo
// Run this once: php generate_pwa_icons.php
$logoPath = __DIR__ . '/assets/images/logo.png';
$outputDir = __DIR__ . '/uploads/branding/pwa/';

if (!file_exists($logoPath)) {
    die("Logo not found at $logoPath\n");
}

if (!is_dir($outputDir)) mkdir($outputDir, 0777, true);

$sizes = [72, 96, 128, 144, 152, 192, 384, 512];

// Detect actual image type (may differ from extension)
$info = getimagesize($logoPath);
if (!$info) die("Cannot read image info\n");

switch ($info[2]) {
    case IMAGETYPE_PNG:  $source = imagecreatefrompng($logoPath); break;
    case IMAGETYPE_JPEG: $source = imagecreatefromjpeg($logoPath); break;
    case IMAGETYPE_GIF:  $source = imagecreatefromgif($logoPath); break;
    case IMAGETYPE_WEBP: $source = imagecreatefromwebp($logoPath); break;
    default: die("Unsupported image type: " . $info['mime'] . "\n");
}

if (!$source) die("Failed to load logo\n");

$srcW = imagesx($source);
$srcH = imagesy($source);

foreach ($sizes as $size) {
    $dest = imagecreatetruecolor($size, $size);
    // Preserve transparency
    imagealphablending($dest, false);
    imagesavealpha($dest, true);
    $transparent = imagecolorallocatealpha($dest, 0, 0, 0, 127);
    imagefilledrectangle($dest, 0, 0, $size, $size, $transparent);
    imagealphablending($dest, true);

    imagecopyresampled($dest, $source, 0, 0, 0, 0, $size, $size, $srcW, $srcH);
    $outFile = $outputDir . 'icon-' . $size . '.png';
    imagepng($dest, $outFile);
    imagedestroy($dest);
    echo "Created: $outFile\n";
}

imagedestroy($source);
echo "\nAll PWA icons generated!\n";
