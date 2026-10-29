<?php
// What changed from one frame to the next (Native\FrameDamage, mygo's
// scene.Differ and gpu.Ages): the boxes of the quads that differ, where
// they drew and draw now, in pixels; nothing for a frame the same as the
// last; everything when it cannot tell; and for each drawable of the swap
// chain what changed since it was drawn.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Native\FrameDamage;
use MyPHP\UI\{Affine, DisplayList};

$ffi = \FFI::cdef();
$list = new DisplayList($ffi->new('float[' . (256 * DisplayList::FLOATS_PER_RECT) . ']'), 256, $ffi->new('float[' . (16 * DisplayList::FLOATS_PER_TRANSFORM) . ']'), 16);
$frame = function (int $color, float $x = 100, bool $more = false, bool $turned = false, int $border = 0) use ($list): array {
    $list->reset();
    $list->pushClip(0, 0, 400, 300);
    $list->rect(0, 0, 400, 300, 0xffffff);
    $list->rect(10, 10, 50, 20, 0x336699, 1.0, 4);
    if ($turned) { $list->setWorld(Affine::rotation(10)); }
    $list->rect($x, 50, 30, 30, $color);
    $list->setWorld(Affine::identity());
    $list->box(200, 200, 80, 40, [6, 6, 6, 6], 0x222222, 1.0, [1.0, 1.0, 1.0, 1.0], $border, 1.0);
    if ($more) { $list->rect(300, 10, 20, 20, 0xff0000); }
    $list->rect(380, 280, 10, 10, 0x00ff00);
    return [$list->copyOut(0, $list->n), $list->transformBytes()];
};
$d = new FrameDamage();
same(null, $d->diff(...[...$frame(0x000000), 'w', 2.0, 800, 600]), 'the first frame: drawn whole');
same([], $d->diff(...[...$frame(0x000000), 'w', 2.0, 800, 600]), 'the same frame: nothing to draw');
same([[198, 98, 262, 162]], $d->diff(...[...$frame(0xaaaaaa), 'w', 2.0, 800, 600]), 'one quad of another color: its box, in pixels, two around');
same([[198, 98, 302, 162]], $d->diff(...[...$frame(0xaaaaaa, 120), 'w', 2.0, 800, 600]), 'moved: where it was and where it is, one rectangle');
same([[598, 18, 642, 62]], $d->diff(...[...$frame(0xaaaaaa, 120, true), 'w', 2.0, 800, 600]), 'a quad more in the middle: its box, those after it the same');
same(null, $d->diff(...[...$frame(0xaaaaaa, 120, true), 'w', 2.0, 900, 600]), 'another size: whole');
$d->diff(...[...$frame(0xaaaaaa, 120, true), 'w', 2.0, 800, 600]);
same(null, $d->diff(...[...$frame(0xaaaaaa, 120, true, true), 'w', 2.0, 800, 600]), 'turned: drawn through a transform, whole');
$d->diff(...[...$frame(0xaaaaaa), 'w', 2.0, 800, 600]);
same([[398, 398, 562, 482]], $d->diff(...[...$frame(0xaaaaaa, 100, false, false, 0xff0000), 'w', 2.0, 800, 600]), 'a box\'s border, which its second slot holds: the box');

// The parts apart from each other: those that meet join, past eight the two whose union grows least.
$r = [];
foreach (range(0, 9) as $i) { $r = FrameDamage::add($r, [$i * 100, 0, $i * 100 + 10, 10]); }
same(FrameDamage::MAX_RECTS, count($r), 'at most eight rectangles');
same([[0, 0, 30, 10]], FrameDamage::add([[0, 0, 10, 10]], [15, 0, 30, 10]), 'nearly meeting: one');
check(FrameDamage::worth([[0, 0, 100, 100]], 800, 600) && !FrameDamage::worth([[0, 0, 800, 400]], 800, 600), 'a part worth drawing: under half the frame');

// Drawables in turn: each gets what changed since it was drawn.
$a = new FrameDamage(); $gone = [];
$forget = function (int $id) use (&$gone): void { $gone[] = $id; };
same(null, $a->take(1, null, $forget), 'a drawable new to the window: whole');
same(null, $a->take(2, [[0, 0, 10, 10]], $forget), 'another new: whole');
same([[0, 0, 10, 10], [50, 50, 60, 60]], $a->take(1, [[50, 50, 60, 60]], $forget), 'the first again: what changed in both frames since');
same([[50, 50, 60, 60], [80, 0, 90, 10]], $a->take(2, [[80, 0, 90, 10]], $forget), 'the second: since its own frame');
same(null, $a->take(1, null, $forget), 'everything changed: whole');
foreach ([3, 4, 5] as $id) { $a->take($id, [], $forget); }
same([2], $gone, 'past four drawables, the one drawn least recently let go');
same(false, $a->has(2), 'no longer tracked');

if (PHP_OS_FAMILY === 'Darwin') { // on the GPU: a frame drawn in part over the last is the frame drawn whole (mygo's TestPartialFramesMatchWhole)
    \MyPHP\Native\ObjC::boot(['Foundation', 'AppKit']);
    $metal = new \MyPHP\Native\Metal(1024);
    $get = fn(string $p) => (new \ReflectionProperty($metal, $p))->getValue($metal);
    $drawList = new \ReflectionMethod($metal, 'drawList');
    [$W, $H] = [400, 300]; // points, at 2 pixels a point
    $u = $get('uniforms'); $u[0] = $W; $u[1] = $H; $u[4] = 2.0; $u[5] = 2.0;
    $ObjC = \MyPHP\Native\ObjC::class;
    $target = function () use ($ObjC, $metal, $W, $H) { // a shared BGRA8 texture of the frame's pixels
        $desc = $ObjC::msg('p:LLLb', $ObjC::cls('MTLTextureDescriptor'), 'texture2DDescriptorWithPixelFormat:width:height:mipmapped:', 80, 2 * $W, 2 * $H, false);
        $ObjC::msg('v:L', $desc, 'setUsage:', 5); // shader read, render target
        $ObjC::msg('v:L', $desc, 'setStorageMode:', 0);
        return $ObjC::msg('p:p', $metal->device, 'newTextureWithDescriptor:', $desc);
    };
    $clear = $ObjC::$c->new('MTLClearColor'); [$clear->r, $clear->g, $clear->b, $clear->a] = [0.9, 0.9, 0.92, 1.0];
    $draw = function ($texture, int $ring, ?array $rects) use ($ObjC, $metal, $get, $drawList, $clear): void {
        $pass = $ObjC::msg('p:', $ObjC::cls('MTLRenderPassDescriptor'), 'renderPassDescriptor');
        $ca = $ObjC::msg('p:L', $ObjC::msg('p:', $pass, 'colorAttachments'), 'objectAtIndexedSubscript:', 0);
        $ObjC::msg('v:p', $ca, 'setTexture:', $texture);
        $ObjC::msg('v:L', $ca, 'setStoreAction:', 1);
        $ObjC::msg('v:k', $ca, 'setClearColor:', $clear);
        $cb = $ObjC::msg('p:', $get('queue'), 'commandBuffer');
        $drawList->invoke($metal, $cb, $pass, $texture, $get('lists')[$ring], [$get('buffers')[$ring], $get('xformBuffers')[$ring]], $rects, $clear);
        $ObjC::msg('v:', $cb, 'commit');
        $ObjC::msg('v:', $cb, 'waitUntilCompleted');
    };
    $pixels = function ($texture) use ($ObjC, $W, $H): string {
        $bytes = $ObjC::$c->new('char[' . (4 * $W * $H * 4) . ']');
        $region = $ObjC::$c->new('MTLRegion'); $region->w = 2 * $W; $region->h = 2 * $H; $region->d = 1;
        $ObjC::msg('v:pLRL', $texture, 'getBytes:bytesPerRow:fromRegion:mipmapLevel:', $ObjC::$c->cast('void*', \FFI::addr($bytes)), 8 * $W, $region, 0);
        return \FFI::string($bytes, 16 * $W * $H);
    };
    $paint = function (DisplayList $l, int $color, float $x, bool $more) {
        $l->reset(); $l->pushClip(0, 0, 400, 300);
        $l->rect(10, 10, 50, 20, 0x336699, 1.0, 4);
        $l->rect($x, 50, 30, 30, $color, 0.8, 6);
        $l->box(200, 200, 80, 40, [6, 6, 6, 6], 0x222222, 1.0, [2.0, 2.0, 2.0, 2.0], $more ? 0xff0000 : 0x00aa00, 1.0);
        if ($more) { $l->shadow(300, 20, 40, 20, 0x000000, 0.4, 4, 6); }
        $l->rect(380, 280, 10, 10, 0x00ff00);
        return $l->copyOut(0, $l->n);
    };
    $lists = $get('lists'); $fd = new FrameDamage();
    $a = $paint($lists[0], 0xaa3300, 100, false);
    $fd->diff($a, $lists[0]->transformBytes(), 'w', 2.0, 2 * $W, 2 * $H);
    $b = $paint($lists[1], 0x0033aa, 140, true);
    $rects = $fd->diff($b, $lists[1]->transformBytes(), 'w', 2.0, 2 * $W, 2 * $H);
    check($rects !== null && $rects !== [] && FrameDamage::worth($rects, 2 * $W, 2 * $H), 'what changed: ' . json_encode($rects));
    $part = $target(); $whole = $target();
    $draw($part, 0, null); $draw($part, 1, $rects); // the last frame, then this one over it in part
    $draw($whole, 1, null);
    $p = $pixels($part); $w = $pixels($whole);
    $off = 0; for ($i = 0, $n = strlen($p); $i < $n; $i++) { if (abs(ord($p[$i]) - ord($w[$i])) > 1) { $off++; } }
    same(0, $off, 'drawn in part over the last frame: the frame drawn whole, pixel for pixel');
    $old = $pixels($part); $draw($part, 0, null);
    check($pixels($part) !== $old, 'and the test tells frames apart');
}
