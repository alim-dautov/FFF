<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$srcPath = $root . '/public/brand/logo.png';
$src = imagecreatefrompng($srcPath);
if (!$src) {
    fwrite(STDERR, "Нет логотипа\n");
    exit(1);
}
$w = imagesx($src);
$h = imagesy($src);
foreach ([192 => 'icon-192.png', 512 => 'icon-512.png', 180 => 'apple-touch-icon.png'] as $size => $name) {
    $im = imagecreatetruecolor($size, $size);
    imagealphablending($im, true);
    $bg = imagecolorallocate($im, 20, 23, 20);
    imagefilledrectangle($im, 0, 0, $size, $size, $bg);
    $targetW = (int) ($size * 0.88);
    $targetH = (int) round($h * ($targetW / $w));
    if ($targetH > $size * 0.7) {
        $targetH = (int) ($size * 0.7);
        $targetW = (int) round($w * ($targetH / $h));
    }
    $x = (int) (($size - $targetW) / 2);
    $y = (int) (($size - $targetH) / 2);
    imagecopyresampled($im, $src, $x, $y, 0, 0, $targetW, $targetH, $w, $h);
    imagepng($im, $root . '/public/icons/' . $name);
    imagedestroy($im);
}
imagedestroy($src);
fwrite(STDOUT, "icons ok\n");
