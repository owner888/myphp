<?php
// Canvases (mygo's canvas_test.go), as far as the display lists tell: a
// canvas drawn once, into its own list, rendered once and shown every
// frame from its place among the canvases' pages, pixelated; canvases
// drawn inside others rendered first; places taken again once the pages
// fill. The pixels are the GPU's.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Canvas, CanvasAtlas, Context, DisplayList, ImageAtlas, Node, Painter, TextPainter, Theme};

CanvasAtlas::reset();
/** The image quads of a list: [x, y, w, h, slot w, slot h, page, kind, u, v]. @return list<list<float>> */
function canvasImages(DisplayList $list): array
{
    $out = [];
    for ($i = 0; $i < $list->n; $i++) {
        $k = $i * 16;
        $kind = fmod($list->buf[$k + 9], DisplayList::KIND_STRIDE);
        if ($kind == 2 || $kind == 12 || $kind == 13) {
            $out[] = [$list->buf[$k], $list->buf[$k + 1], $list->buf[$k + 2], $list->buf[$k + 3], $list->buf[$k + 4], $list->buf[$k + 5], $list->buf[$k + 6], $kind, $list->buf[$k + 10], $list->buf[$k + 11]];
        }
    }
    return $out;
}

// An 8×4 canvas, drawn once, shown scaled up 4 times, pixelated.
$cv = new Canvas(8, 4);
$cv->bitmap()->pixelated();
$draws = 0;
$h = new Headless(function (Context $c) use ($cv, &$draws): void {
    $c->column()->at(0, 0)->size(32, 16)->draw(function (DisplayList $list, Node $n, Theme $t, TextPainter $text) use ($cv, &$draws): void {
        $p = new Painter($list, $text, $t);
        if ($draws === 0) {
            $draws++;
            $cv->draw($p, function (Painter $cp): void {
                $cp->fill([0, 0, 4, 4], 0xff0000);
                $cp->fill([4, 0, 4, 4], 0x0000ff);
                $cp->fill([1, 1, 1, 1], 0x00ff00); // a pixel of green
            });
        }
        $p->image($cv->bitmap(), [0.0, 0.0, 32.0, 16.0], Node::FILL_BOX);
    });
    $c->end();
}, 64, 32);
$h->frame();
$win = $h->paint();
same(3, $cv->list->n, 'what it was drawn with, in its own list');
same([[0.0, 0.0, 4.0, 4.0], [1.0, 1.0, 1.0, 1.0]], [[$cv->list->buf[0], $cv->list->buf[1], $cv->list->buf[2], $cv->list->buf[3]], [$cv->list->buf[32], $cv->list->buf[33], $cv->list->buf[34], $cv->list->buf[35]]], 'in its pixels');
same([0.0, 0.0, 8.0, 4.0], [$cv->list->buf[12], $cv->list->buf[13], $cv->list->buf[14], $cv->list->buf[15]], 'clipped to itself');
$slot = $cv->slot();
check($slot['page'] === ImageAtlas::MAX && $slot['w'] === 8 && $slot['h'] === 4, 'placed on the first of the canvases\' pages: ' . json_encode($slot));
same([$cv], CanvasAtlas::take(), 'rendered this frame');
$img = canvasImages($win);
check(count($img) === 1 && $img[0][2] == 32 && $img[0][4] == 8 && $img[0][5] == 4 && $img[0][6] == ImageAtlas::MAX && $img[0][7] == 13 && $img[0][8] == $slot['u'], 'shown from its place, scaled, pixelated: ' . json_encode($img));
$h->frame();
check($draws === 1 && CanvasAtlas::take() === [] && count(canvasImages($h->paint())) === 1, 'frames after show it without drawing or rendering it again');

// A canvas drawn in another's drawing renders first; text and images draw in canvases too.
$inner = new Canvas(4, 4);
$outer = new Canvas(8, 8);
$h = new Headless(function (Context $c) use ($inner, $outer): void {
    $c->column()->at(0, 0)->size(80, 40)->draw(function (DisplayList $list, Node $n, Theme $t, TextPainter $text) use ($inner, $outer): void {
        $p = new Painter($list, $text, $t);
        $outer->draw($p, function (Painter $op) use ($p, $inner): void {
            $inner->draw($p, fn(Painter $ip) => $ip->fill([0, 0, 4, 4], 0x00c800));
            $op->image($inner->bitmap(), [2.0, 2.0, 4.0, 4.0], Node::FILL_BOX);
            $op->text(1, 1, 'Hi', 14, 0xffffff);
        });
        $p->image($outer->bitmap(), [0.0, 0.0, 8.0, 8.0], Node::FILL_BOX);
    });
    $c->end();
}, 80, 40);
$h->frame();
$h->paint();
same([$inner, $outer], CanvasAtlas::take(), 'the inner first');
$shown = canvasImages($outer->list);
check(count($shown) === 1 && $shown[0][6] == ImageAtlas::MAX && $shown[0][8] == $inner->slot()['u'] && $shown[0][9] == $inner->slot()['v'], 'the outer shows the inner from its place');
check($outer->list->n >= 2, 'and its text');

// The pages fill: the canvases start over, those drawn render again where they go.
$big = [new Canvas(2048, 1500), new Canvas(2048, 1500)];
foreach ($big as $b) { $b->slot(); }
$before = CanvasAtlas::$resets;
$cvSlot = $cv->slot;
$third = new Canvas(2048, 1500);
$third->slot();
check(CanvasAtlas::$resets === $before + 1 && $cv->slot === null && $big[0]->slot === null, 'full: started over');
$cv->slot();
same([$cv], CanvasAtlas::take(), 'one drawn before renders again in its new place');

// Limits.
$threw = 0;
try { new Canvas(4096, 10); } catch (\InvalidArgumentException) { $threw++; }
try { $cv->bitmap()->pixels(); } catch (\RuntimeException) { $threw++; }
same(2, $threw, 'larger than a page, and its pixels, which the GPU has');
same($cv->bitmap(), $cv->bitmap()->smaller(8), 'no smaller levels of a canvas');
same([1, 1], (new Canvas(0, -3))->size(), 'at least a pixel');
CanvasAtlas::reset();
