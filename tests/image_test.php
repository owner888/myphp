<?php
// Decoding images, on macOS (mygo's bitmap_test.go
// TestDecodeBitmapTurnsPhotosUpright): a photo comes upright, as its EXIF
// orientation says, scaled to fit the size asked; in a fiber the decoding
// waits while the rest runs. upright.jpg is 16 by 8 pixels stored, red on
// the left and blue on the right, with orientation 6 (turned a quarter
// clockwise to show): upright, 8 by 16, red on top.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\App;
use MyPHP\Native\Fibers;
use MyPHP\UI\Image;
use RuntimeException;

if (PHP_OS_FAMILY === 'Darwin') {
    $jpg = (string)file_get_contents(__DIR__ . '/testdata/upright.jpg');
    /** The RGBA of the pixel at ($x, $y) of $img, y down. */
    $pixel = fn(Image $img, int $x, int $y): array => array_values(unpack('C4', $img->pixels(), 4 * ($y * $img->w + $x)));

    $img = Image::fromBytes($jpg);
    same([8, 16], $img->size(), 'a photo turned a quarter comes upright');
    [$r, $g, $b] = $pixel($img, 4, 2);
    check($r > 200 && $b < 60, "red on top: $r, $g, $b");
    [$r, $g, $b] = $pixel($img, 4, 13);
    check($b > 200 && $r < 60, "blue below: $r, $g, $b");
    same([2, 4], Image::fromBytes($jpg, 4)->size(), 'scaled to fit 4 pixels on a side');

    $got = null;
    App::go(function () use ($jpg, &$got) { $got = Image::fromBytes($jpg); });
    check($got === null, 'in a fiber, the decoding waits and the caller goes on');
    Fibers::drain();
    same([8, 16], $got?->size(), 'the fiber has the image once it is decoded');

    $err = null;
    try { Image::fromBytes('not an image'); } catch (RuntimeException $e) { $err = $e->getMessage(); }
    same('not an image ImageIO can read', $err, 'what is no image throws');

    // SF Symbols: the system's icon, at its size, in the color asked, its pixels twice its points.
    $g = Image::symbol('gearshape', 16, 0xff0000);
    check($g->w >= 28 && $g->w <= 40 && $g->h >= 28 && $g->h <= 40, "a 16-point gear at 2x: {$g->w}x{$g->h}");
    $px = $g->pixels(); $opaque = 0; $red = true;
    for ($i = 0; $i < strlen($px); $i += 4) { if (ord($px[$i + 3]) === 255) { $opaque++; if (ord($px[$i]) !== 255 || ord($px[$i + 1]) !== 0) { $red = false; } } }
    check($opaque > 50 && $red, "drawn in the color asked: $opaque opaque pixels");
    check(Image::symbol('gearshape', 32)->w > $g->w, 'larger at a larger size');
    $err = null;
    try { Image::symbol('no.such.symbol', 16); } catch (RuntimeException $e) { $err = $e->getMessage(); }
    check(str_contains((string)$err, 'no SF Symbol'), 'a name of no symbol throws');
}

