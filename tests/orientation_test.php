<?php
// Photos come upright (mygo's bitmap_test.go TestDecodeBitmapTurnsPhotosUpright):
// the EXIF orientation of a JPEG, in either byte order, and the transform
// that draws each orientation upright, pixel for pixel as mygo's orient.
// ImageIO draws with it on macOS.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Native\ImageIO;

/** A JPEG's start with an EXIF segment giving orientation $o, in byte order $order ('II' or 'MM'), as mygo's withOrientation. */
function withOrientation(int $o, string $order): string
{
    [$u16, $u32] = $order === 'II' ? ['v', 'V'] : ['n', 'N'];
    $tiff = $order . pack($u16, 42) . pack($u32, 8) . pack($u16, 1) . pack($u16, 0x0112) . pack($u16, 3) . pack($u32, 1) . pack($u16, $o) . "\0\0" . pack($u32, 0);
    $app1 = "Exif\0\0" . $tiff;
    return "\xff\xd8" . "\xff\xe1" . pack('n', 2 + strlen($app1)) . $app1 . "\xff\xdb\x00\x04\x00\x00" . "\xff\xda\x00\x02";
}

foreach ([[1, 'MM'], [3, 'II'], [6, 'MM'], [8, 'II'], [5, 'II'], [2, 'MM']] as [$o, $order]) {
    same($o, ImageIO::exifOrientation(withOrientation($o, $order)), "orientation $o, $order");
}
same(0, ImageIO::exifOrientation('not a jpeg'), 'no orientation in what is no JPEG');
same(0, ImageIO::exifOrientation("\xff\xd8\xff\xdb\x00\x04\x00\x00\xff\xda\x00\x02"), 'none in a JPEG without EXIF');

// Each pixel of a 4 by 3 image lands where mygo's orient puts it: out(x, y) = src(sx, sy), y down.
$w = 4; $h = 3;
foreach (range(1, 8) as $o) {
    [$ow, $oh] = $o >= 5 ? [$h, $w] : [$w, $h];
    [$a, $b, $c, $d, $tx, $ty] = ImageIO::orientTransform($o, $w, $h);
    $ok = true;
    for ($sy = 0; $sy < $h; $sy++) {
        for ($sx = 0; $sx < $w; $sx++) {
            [$u, $v] = [$sx + 0.5, $h - ($sy + 0.5)]; // the pixel's center where CoreGraphics draws the image, y up
            $x = (int)floor($a * $u + $c * $v + $tx); $y = (int)floor($oh - ($b * $u + $d * $v + $ty)); // in the bitmap, y down
            $want = match ($o) { 2 => [$w - 1 - $x, $y], 3 => [$w - 1 - $x, $h - 1 - $y], 4 => [$x, $h - 1 - $y], 5 => [$y, $x], 6 => [$y, $h - 1 - $x], 7 => [$w - 1 - $y, $h - 1 - $x], 8 => [$w - 1 - $y, $x], default => [$x, $y] };
            if ($x < 0 || $x >= $ow || $y < 0 || $y >= $oh || $want !== [$sx, $sy]) { $ok = false; }
        }
    }
    check($ok, "orientation $o draws each pixel where mygo's orient puts it");
}
