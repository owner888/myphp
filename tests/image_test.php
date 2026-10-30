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


// releasePixels (mygo's TestReleasePixels…): an image decoded lets its pixels go and decodes them again when something needs them, as the atlas or a level, which take them and let them go again; one of pixels, or updated, keeps them.
(function () {
    $w = 6; $h = 4;
    $rgba = '';
    for ($i = 0; $i < $w * $h; $i++) { $rgba .= chr(($i * 37) % 256) . chr(($i * 11) % 256) . chr(200) . chr(255); }
    $decodes = 0;
    $decode = function () use (&$decodes, $w, $h, $rgba): array {
        $decodes++;
        $pix = \MyPHP\Native\Mem::alloc($w * $h * 4);
        \FFI::memcpy($pix, $rgba, $w * $h * 4);
        return ['w' => $w, 'h' => $h, 'pix' => $pix, 'addr' => \MyPHP\Native\Mem::addr($pix)];
    };
    $img = Image::decoded($decode);
    $kept = Image::fromPixels($w, $h, $rgba);
    same(1, $decodes, 'released: decoded once, as made');
    check($img->address() !== 0, 'released: its pixels, before');
    $img->uploaded();
    same([1, $rgba], [$decodes, $img->pixels()], 'released: kept while not released, uploaded or not');
    $img->releasePixels();
    same([$rgba, 2], [$img->pixels(), $decodes], 'released: pixels() decodes them again, as they were');
    $n = $decodes;
    $img->address(); $img->address();
    same($n + 1, $decodes, 'released: decoded once for an upload');
    $img->uploaded();
    $img->address();
    same($n + 2, $decodes, 'released: gone again once uploaded, decoded again for the next');
    $img->uploaded();
    // A level made after the release, the same as one of an image that kept its pixels; it goes once uploaded, and comes back the same.
    $want = $kept->smaller(2)->pixels();
    $lvl = $img->smaller(2);
    same([3, 2, $want], [$lvl->w, $lvl->h, $lvl->pixels()], 'released: a level made from the pixels decoded again');
    $lvl->address(); $lvl->uploaded();
    same($want, $lvl->pixels(), 'released: the level decoded and halved again');
    // A level made before the release goes with it.
    $img2 = Image::decoded($decode);
    $lvl2 = $img2->smaller(4);
    $img2->releasePixels();
    same($kept->smaller(4)->pixels(), $lvl2->pixels(), "released: a level made before, decoded again");
    // An image of pixels has nothing to decode: it keeps them.
    $addr = $kept->address();
    $kept->releasePixels(); $kept->uploaded();
    same([$addr, $rgba], [$kept->address(), $kept->pixels()], 'released: an image of pixels keeps them');
    // update() on a released image: the new pixels kept, released no more.
    $img->releasePixels();
    $new = str_repeat("\x10\x20\x30\xff", $w * $h);
    $img->update($w, $h, $new);
    $img->releasePixels(); $img->uploaded();
    $n = $decodes;
    same([$new, $n], [$img->pixels(), $decodes], 'released: updated, it keeps its new pixels');
    same([3, 2], [$img->smaller(2)->w, $img->smaller(2)->h], 'released and updated: a level of the new pixels');
    // On macOS, the image atlas: it takes the pixels decoded again, and once more after it started over.
    if (PHP_OS_FAMILY === 'Darwin') {
        \MyPHP\Native\ObjC::boot(['Foundation', 'AppKit']);
        $text = new \MyPHP\UI\Text(new \MyPHP\Native\CoreText(2.0), new \MyPHP\UI\Atlas(256, 256), new \MyPHP\UI\ImageAtlas(256, 256));
        $a = Image::decoded($decode);
        $a->releasePixels();
        $n = $decodes;
        $text->beginFrame();
        $slot = $text->imageSlot($a);
        $text->imageSlot($a);
        $page = $text->images->pages[$slot['page']];
        $row = \FFI::string(\FFI::addr($page->pix[($slot['v'] * $page->w + $slot['u']) * 4]), $w * 4);
        same([$n + 1, substr($rgba, 0, $w * 4)], [$decodes, $row], 'released: the atlas has its pixels, decoded once');
        $page->reset();
        $text->imageSlot($a);
        same($n + 2, $decodes, 'released: decoded again for the atlas started over');
    }
})();
