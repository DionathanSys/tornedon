<?php

// Generate raster equivalents of the PWA mark with PHP GD (supersampled).
foreach ([180, 192, 512] as $size) {
    $scale = 3;
    $canvas = imagecreatetruecolor($size * $scale, $size * $scale);
    $background = imagecolorallocate($canvas, 24, 24, 27);
    $surface = imagecolorallocate($canvas, 39, 39, 42);
    $white = imagecolorallocate($canvas, 255, 255, 255);
    imagefill($canvas, 0, 0, $background);
    $factor = $size * $scale / 192;
    $points = static fn (array $coordinates): array => array_map(static fn ($value): int => (int) round($value * $factor), $coordinates);
    imagefilledrectangle($canvas, (int) (24 * $factor), (int) (24 * $factor), (int) (168 * $factor), (int) (168 * $factor), $surface);
    imagefilledpolygon($canvas, $points([56, 136, 84, 56, 108, 56, 136, 136, 116, 136, 110, 118, 82, 118, 76, 136]), $white);
    imagefilledpolygon($canvas, $points([88, 100, 104, 100, 96, 76]), $surface);
    $icon = imagecreatetruecolor($size, $size);
    imagecopyresampled($icon, $canvas, 0, 0, 0, 0, $size, $size, $size * $scale, $size * $scale);
    imagepng($icon, __DIR__."/../public/pwa-{$size}.png");
    if ($size === 512) {
        imagepng($icon, __DIR__.'/../public/pwa-maskable.png');
    }
    imagedestroy($icon);
    imagedestroy($canvas);
}
