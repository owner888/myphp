<?php
// The atlases' room (UI\Atlas, ImageAtlas): bitmaps packed shelf by shelf,
// each going again on its own, the least recently drawn first and none of
// the frame drawing; the gaps they leave taken again, empty shelves joined;
// and on macOS the text painter's glyph atlas evicting glyph by glyph, not
// starting over.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Native\Mem;
use MyPHP\UI\{Atlas, ImageAtlas};

$bitmap = function (int $w, int $h, int $v, int $bpp = 1): array { // a w*h bitmap of byte $v, and its address (freed with the first)
    $p = Mem::alloc($w * $h * $bpp);
    Mem::write(Mem::addr($p), str_repeat(chr($v), $w * $h * $bpp));
    return [$p, Mem::addr($p)];
};
$pixel = fn(Atlas $a, int $u, int $v): int => ord(Mem::read(Mem::addr($a->pix) + ($v * $a->w + $u) * $a->bpp, 1));

// 64 by 64: shelves of 8 by 8 bitmaps (9 by 9 with their pixel apart, a shelf 12 high, in steps of four): seven to a shelf, five shelves.
$a = new Atlas(64, 64);
[$p, $src] = $bitmap(8, 8, 200);
$a->frame = 1;
$n = 0;
while ($a->add("g$n", 8, 8, $src) !== null) { $n++; }
same(35, $n, 'full: five shelves of seven');
same(null, $a->add('more', 8, 8, $src), 'no room: null, not an exception');
// Frame 2 draws g0 to g9 (get, mark); a bitmap more evicts the oldest of the others, one, not those.
$a->frame = 2;
for ($i = 0; $i < 9; $i++) { $a->get("g$i"); }
$a->mark('g9');
$gone = $a->evict(8, 8);
check(count($gone) === 1 && !in_array($gone[0], ['g0', 'g1', 'g2', 'g3', 'g4', 'g5', 'g6', 'g7', 'g8', 'g9'], true), 'evicted: one of those not drawn this frame: ' . json_encode($gone));
check($a->get($gone[0]) === null, 'gone from the atlas');
[$q, $src2] = $bitmap(8, 8, 77);
$slot = $a->add('new', 8, 8, $src2);
check($slot !== null && $pixel($a, $slot['u'], $slot['v']) === 77, 'the new one where it was: ' . json_encode($slot));
// Evicting what this frame draws: never. All drawn: nothing to free, and no room.
$a->frame = 3;
foreach (range(0, 34) as $i) { $a->get("g$i"); }
$a->get('new');
same([], $a->evict(8, 8), 'every bitmap drawn this frame: none evicted');
// Shelves emptied take a taller bitmap: the top shelf (g0 to g6) removed, then the next, joined, 24 high: one 16 high fits, split to it.
foreach (range(0, 13) as $i) { $a->remove("g$i"); }
$a->remove('new'); // in the second shelf, where the bitmap evicted was
same(0, $pixel($a, 0, 0), 'a bitmap removed: its pixels cleared (a neighbor\'s edge samples them)');
[$r, $src3] = $bitmap(20, 16, 9);
$tall = $a->add('tall', 20, 16, $src3);
same([0, 0], [$tall['u'] ?? null, $tall['v'] ?? null], 'two empty shelves joined: a bitmap twice their height at the top');
same(9, $pixel($a, 19, 15), 'its pixels there');
// The gaps a shelf's bitmaps leave join: two side by side removed take one twice as wide.
$b = new Atlas(64, 64);
$b->frame = 1;
foreach (['a', 'b', 'c', 'd'] as $k) { $b->add($k, 8, 8, $src); }
$b->remove('b'); $b->remove('c');
[$s, $src4] = $bitmap(17, 8, 5);
$wide = $b->add('wide', 17, 8, $src4);
same([9, 0], [$wide['u'], $wide['v']], 'two gaps side by side: one, where the first was');
// The image atlas: past its pages, the images drawn least recently make room, not a page emptied.
$im = new ImageAtlas(64, 64);
[$big, $bsrc] = $bitmap(40, 40, 1, 4);
for ($i = 0; $i < ImageAtlas::MAX; $i++) { $im->beginFrame(); $im->add("page$i", 40, 40, $bsrc); }
$im->beginFrame();
$im->get('page1'); $im->get('page2'); $im->get('page3');
[$slot, $emptied] = $im->add('fifth', 40, 40, $bsrc);
same([0, false, null], [$slot['page'], $emptied, $im->get('page0')], 'the image drawn least recently evicted for it, its page not emptied whole');
same(true, $im->get('page1') !== null, 'the others kept');
foreach ([$p, $q, $r, $s, $big] as $m) { Mem::free($m); }

if (PHP_OS_FAMILY === 'Darwin') { // the text painter: a glyph atlas far smaller than what it draws over the frames
    \MyPHP\Native\ObjC::boot(['Foundation', 'AppKit']); // the text system's (font smoothing's defaults), run alone
    $text = new \MyPHP\UI\Text(new \MyPHP\Native\CoreText(2.0), new Atlas(256, 256), new ImageAtlas());
    $ffi = \FFI::cdef(); // the list's buffers, no renderer reading them
    $list = new \MyPHP\UI\DisplayList($ffi->new('float[' . (8192 * \MyPHP\UI\DisplayList::FLOATS_PER_RECT) . ']'), 8192,
        $ffi->new('float[' . (16 * \MyPHP\UI\DisplayList::FLOATS_PER_TRANSFORM) . ']'), 16);
    for ($f = 0; $f < 40; $f++) { // a frame of a new line of glyphs each time
        $text->beginFrame(); $list->reset();
        $line = '';
        for ($k = 0; $k < 20; $k++) { $line .= mb_chr(0x4E00 + $f * 20 + $k); }
        $text->paint($list, $line, 16.0, 0, 0, 0xffffff);
    }
    check($text->resets === 0 && $text->atlas->evicted > 0, 'glyphs evicted one by one, the atlas never started over: ' . $text->atlas->evicted . ' evicted, ' . $text->resets . ' resets');
    $text->beginFrame(); $list->reset();
    $text->paint($list, $line, 16.0, 0, 0, 0xffffff);
    $again = $list->n;
    check($again === 20 && $text->resets === 0, 'the last line again, from its glyphs kept: ' . $again . ' quads');
}
