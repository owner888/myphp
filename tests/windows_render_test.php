<?php
// The Direct3D 11 renderer on Windows (Native\D3d, D3dShader's HLSL): the shaders compiled,
// a list of rects drawn off screen and read back (CI's runners draw with WARP) at one and at two
// pixels a point: fills, alpha, a rounded corner's coverage, a clip, the clear color.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Native\{D3d, D3dShader};
use MyPHP\UI\{Atlas, ImageAtlas};

(function (): void {
    if (PHP_OS_FAMILY !== 'Windows') {
        echo "  skipped: Windows's\n";
        return;
    }
    foreach ([['QUAD', 'vs', 'vs_5_0'], ['QUAD', 'ps', 'ps_5_0'], ['BLUR', 'vs', 'vs_5_0'], ['BLUR', 'ps', 'ps_5_0']] as [$which, $entry, $target]) {
        try {
            $code = D3d::compile(constant(D3dShader::class . "::$which"), strtolower($which), $entry, $target);
            check(strlen($code) > 100, "$which $entry compiled: " . strlen($code) . ' bytes');
        } catch (\RuntimeException $e) {
            check(false, $e->getMessage());
            return;
        }
    }

    $r = new D3d(1024);
    check(true, 'a device: ' . (D3d::$warp ? 'WARP' : 'the GPU'));
    $r->attachAtlas(new Atlas(256, 256), 1.0);
    $r->attachImages(new ImageAtlas(256, 256));
    $px = function (string $pix, int $w, int $x, int $y): array {
        return array_values(unpack('C4', substr($pix, ($y * $w + $x) * 4, 4)));
    };
    $near = fn(array $a, array $b, int $tol = 2): bool => max(array_map(fn($p, $q) => abs($p - $q), $a, $b)) <= $tol;

    $list = $r->nextList();
    $list->rect(10, 10, 20, 20, 0xff0000);
    $list->rect(40, 10, 20, 20, 0x000000, 0.5);
    $list->rect(10, 40, 20, 20, 0x0000ff, 1.0, 10.0);
    $list->pushClip(40, 40, 10, 10);
    $list->rect(40, 40, 20, 20, 0x00ff00);
    $pix = $r->readBack($list, 64, 64, 1.0, [1.0, 1.0, 1.0, 1.0]);
    same(64 * 64 * 4, strlen($pix), 'the pixels read back: BGRA, 64 × 64');
    check($near($px($pix, 64, 2, 2), [255, 255, 255, 255]), 'the clear color: ' . json_encode($px($pix, 64, 2, 2)));
    check($near($px($pix, 64, 20, 20), [0, 0, 255, 255]), 'an opaque red fill: ' . json_encode($px($pix, 64, 20, 20)));
    check($near($px($pix, 64, 9, 20), [255, 255, 255, 255]) && $near($px($pix, 64, 30, 20), [255, 255, 255, 255]), 'its edges where the rect ends');
    // its alpha blended as its color is, as Gl's: 0.5 × 0.5 + 1 × 0.5
    check($near($px($pix, 64, 50, 20), [128, 128, 128, 191], 3), 'black at half alpha over white: ' . json_encode($px($pix, 64, 50, 20)));
    check($near($px($pix, 64, 20, 50), [255, 0, 0, 255]), 'a circle\'s middle: ' . json_encode($px($pix, 64, 20, 50)));
    check($near($px($pix, 64, 11, 41), [255, 255, 255, 255], 8), 'its corner outside the curve: ' . json_encode($px($pix, 64, 11, 41)));
    $edge = $px($pix, 64, 12, 43);
    check($edge[0] === 255 && $edge[1] > 20 && $edge[1] < 235, 'a pixel the curve crosses, partly covered: ' . json_encode($edge));
    check($near($px($pix, 64, 45, 45), [0, 255, 0, 255]) && $near($px($pix, 64, 55, 55), [255, 255, 255, 255]), 'a clip: ' . json_encode([$px($pix, 64, 45, 45), $px($pix, 64, 55, 55)]));

    $list = $r->nextList();
    $list->rect(10, 10, 20, 20, 0xff0000);
    $pix = $r->readBack($list, 128, 128, 2.0, [0.0, 0.0, 0.0, 0.0]);
    check($near($px($pix, 128, 21, 21), [0, 0, 255, 255]) && $near($px($pix, 128, 58, 58), [0, 0, 255, 255]) && $near($px($pix, 128, 61, 61), [0, 0, 0, 0]),
        'two pixels a point, on a transparent clear: ' . json_encode([$px($pix, 128, 21, 21), $px($pix, 128, 61, 61)]));

    $other = new D3d(256);
    $list = $other->nextList();
    $list->rect(0, 0, 8, 8, 0x00ff00);
    $pix = $other->readBack($list, 8, 8, 1.0, [0.0, 0.0, 0.0, 1.0]);
    check($near($px($pix, 8, 4, 4), [0, 255, 0, 255]), 'a second renderer on the shared device');
    $other->release();
    $r->release();
})();
