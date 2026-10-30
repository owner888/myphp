<?php
// Node::cache (mygo's cache_test.go), as far as the display lists tell: a
// kept subtree is drawn into a canvas of its layer's area, rendered again
// only when what it paints differs, and shown as one image; the window's
// frame finds that image the same. The pixels are the GPU's.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{CanvasAtlas, Context, Image, Node};

CanvasAtlas::reset();
$color = 0x336699; $kidX = 0.0; $clip = false; $img = Image::fromPixels(1, 1, "\xff\x00\x00\xff"); $blur = 0.0; $moving = false;
$h = new Headless(function (Context $c) use (&$color, &$kidX, &$clip, $img, &$blur, &$moving): void {
    $card = $c->column()->at(20, 20)->size(100, 50)->background($color)->shadow(0, 4, 8, 0, 0x000000, 0.5)->cache();
    if ($clip) { $card->clip(); }
      $c->column()->size(30, 10)->translate($kidX, 0)->background(0xffffff); $c->end();
      $c->image($img, 4, 4);
      if ($blur > 0) { $c->column()->size(10, 10)->background(0xff0000)->blur($blur); $c->end(); }
      if ($moving) { $c->animationFrame(); }
    $c->end();
}, 300, 200);
$images = fn(): array => canvasImagesOf($h->paint());
/** The image quads of a list: [x, y, w, h]. @return list<list<float>> */
function canvasImagesOf(\MyPHP\UI\DisplayList $list): array
{
    $out = [];
    for ($i = 0; $i < $list->n; $i++) {
        $k = $i * 16;
        $kind = fmod($list->buf[$k + 9], \MyPHP\UI\DisplayList::KIND_STRIDE);
        if ($kind == 2 || $kind == 12 || $kind == 13) { $out[] = [$list->buf[$k], $list->buf[$k + 1], $list->buf[$k + 2], $list->buf[$k + 3]]; }
    }
    return $out;
}

$h->frame();
// The box grown by its shadow's reach and 4 points: 0 + 4 + 8 + 0 + 4 = 16 each way.
same([[4.0, 4.0, 132.0, 82.0]], $images(), 'shown as one image of its layer: the box and what it paints around it');
same(1, count(CanvasAtlas::take()), 'rendered the first time');
$h->frame();
check($images() !== [] && CanvasAtlas::take() === [], 'painted the same: not rendered again');
$color = 0x663399; $h->frame();
$images();
same(1, count(CanvasAtlas::take()), 'another color: rendered again');
$h->frame(); $images();
same([], CanvasAtlas::take(), 'then kept');

$kidX = 200.0; $h->frame();
same([[4.0, 4.0, 250.0, 82.0]], $images(), 'a child beyond the box: the layer reaches it, and 4 points more');
CanvasAtlas::take();
$clip = true; $h->frame();
same([[4.0, 4.0, 132.0, 82.0]], $images(), 'clipped: the box and its shadow');
CanvasAtlas::take();

$img->update(1, 1, "\x00\xff\x00\xff"); $h->frame(); $images();
same(1, count(CanvasAtlas::take()), 'an image in it updated: rendered again');
$h->frame(); $images(); CanvasAtlas::take();

$blur = 2.0; $h->frame(); $images();
same(2, count(CanvasAtlas::take()), 'a blur inside: its canvas rendered, and the kept one with it');
$h->frame(); $images();
same(2, count(CanvasAtlas::take()), 'each frame, as the blur\'s is');
$blur = 0.0; $h->frame(); $images(); CanvasAtlas::take();

$moving = true; $h->frame(); $images(); CanvasAtlas::take();
$h->frame(); $images();
same([], CanvasAtlas::take(), 'asking for frames but painting the same: kept');
$moving = false;

// Under a transform the bitmap moves, not what it holds.
$at = 0.0;
$h2 = new Headless(function (Context $c) use (&$at): void {
    $c->column()->at(10, 10)->size(40, 40)->background(0x00aa00)->translate($at, 0)->cache(); $c->end();
}, 200, 100);
$h2->frame(); canvasImagesOf($h2->paint()); CanvasAtlas::take();
$at = 50.0; $h2->frame(); canvasImagesOf($h2->paint()); CanvasAtlas::take(); // drawn through its map from now on
$at = 70.0; $h2->frame();
$l = $h2->paint();
same([[6.0, 6.0, 48.0, 48.0]], canvasImagesOf($l), 'moved: the image of its layout, through its map');
same([], CanvasAtlas::take(), 'not rendered again');

// With blur(), its bitmap is the one kept.
$h3 = new Headless(function (Context $c): void {
    $c->column()->at(10, 10)->size(40, 40)->background(0x0000aa)->blur(3)->cache(); $c->end();
}, 200, 100);
$h3->frame(); canvasImagesOf($h3->paint());
same(1, count(CanvasAtlas::take()), 'blurred and kept: one canvas, rendered');
$h3->frame();
same(1, count(canvasImagesOf($h3->paint())), 'shown as before');
same([], CanvasAtlas::take(), 'kept');
