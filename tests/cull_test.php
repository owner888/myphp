<?php
// Occlusion culling (mygo's gpu.planCull of 9e5bdc3), as the display list
// does it: the quads a later opaque fill covers whole get the kind -1,
// which draws nothing, and nothing else changes.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Affine, DisplayList};

/** A list of its own, clipped to a window of 800 × 600. */
function cullList(): DisplayList
{
    $ffi = \FFI::cdef();
    $l = new DisplayList($ffi->new('float[' . (256 * 16) . ']'), 256, $ffi->new('float[' . (16 * 16) . ']'), 16);
    $l->pushClip(0, 0, 800, 600);
    return $l;
}

/** The quads of $l left out. @return list<int> */
function culledOf(DisplayList $l): array
{
    $was = [];
    for ($i = 0; $i < $l->n; $i++) { $was[$i] = $l->buf[$i * 16 + 9]; }
    $l->cull();
    $out = [];
    for ($i = 0; $i < $l->n; $i++) { if ($l->buf[$i * 16 + 9] == -1.0 && $was[$i] != -1.0) { $out[] = $i; } }
    return $out;
}

// Under an opaque panel: what lies inside it goes, what reaches out of it, or past its smoothed edge, stays; what comes after it stays.
$l = cullList();
$l->rect(110, 110, 50, 20, 0xff0000);          // 0: inside
$l->rect(90, 110, 50, 20, 0x00ff00);           // 1: across its left edge
$l->glyph(300, 200, 8, 16, 0x000000, 1.0, 0, 0); // 2: a glyph inside
$l->rect(100.2, 150, 40, 20, 0x0000ff);        // 3: within a point of its edge
$l->rect(100, 100, 400, 300, 0xffffff);        // 4: the panel
$l->rect(120, 120, 20, 20, 0x00ffff);          // 5: after it
same([0, 2], culledOf($l), 'inside the panel, left out');
same(2, $l->culled, 'counted');
same([0.0, 0.0], [$l->buf[4 * 16 + 9], $l->buf[5 * 16 + 9]], 'the panel and what follows drawn');

// A fill that is not opaque, too small, or drawn through a map hides nothing; nor does it hide what is drawn through one.
$l = cullList();
$l->rect(110, 110, 50, 20, 0xff0000);
$l->rect(100, 100, 400, 300, 0xffffff, 0.99);
same([], culledOf($l), 'a translucent fill');
$l = cullList();
$l->rect(110, 110, 50, 20, 0xff0000);
$l->setOpacity(0.5); $l->rect(100, 100, 400, 300, 0xffffff); $l->setOpacity(1.0);
same([], culledOf($l), 'an opaque fill drawn at an opacity');
$l = cullList();
$l->rect(110, 110, 20, 20, 0xff0000);
$l->rect(100, 100, 150, 150, 0xffffff);
same([], culledOf($l), 'a fill smaller than a panel');
$l = cullList();
$l->rect(110, 110, 20, 20, 0xff0000);
$l->setWorld(Affine::translation(1, 1)); $l->rect(100, 100, 400, 300, 0xffffff); $l->setWorld(Affine::identity());
same([], culledOf($l), 'a fill drawn through a map');
$l = cullList();
$l->setWorld(Affine::translation(1, 1)); $l->rect(110, 110, 20, 20, 0xff0000); $l->setWorld(Affine::identity());
$l->rect(100, 100, 400, 300, 0xffffff);
same([], culledOf($l), 'what is drawn through a map');

// Rounded, its corners' curves hide nothing; bordered, its border neither; clipped, only what its clip keeps.
$l = cullList();
$l->rect(101, 101, 6, 6, 0xff0000);            // 0: in its corner
$l->rect(200, 200, 20, 20, 0xff0000);          // 1: in its middle
$l->rect(100, 100, 400, 300, 0xffffff, 1.0, 20);
same([1], culledOf($l), 'a rounded panel: not in its corner');
$l = cullList();
$l->rect(102, 150, 3, 20, 0xff0000);           // 0: under its border
$l->rect(200, 200, 20, 20, 0xff0000);          // 1
$l->box(100, 100, 400, 300, [0.0, 0.0, 0.0, 0.0], 0xffffff, 1.0, [4.0, 4.0, 4.0, 4.0], 0x000000, 1.0);
same([1], culledOf($l), 'a bordered box: not under its border');
$l = cullList();
$l->rect(110, 110, 20, 20, 0xff0000);          // 0: inside its clip
$l->rect(400, 300, 20, 20, 0xff0000);          // 1: outside it
$l->pushClip(100, 100, 250, 250); $l->rect(100, 100, 400, 300, 0xffffff); $l->popClip();
same([0], culledOf($l), 'a clipped panel: what its clip keeps');
$l = cullList();
$l->pushClip(100, 100, 50, 50); $l->rect(140, 140, 30, 30, 0xff0000); $l->popClip(); // 0: reaching out of the panel, but clipped inside it
$l->rect(100, 100, 400, 300, 0xffffff);
same([0], culledOf($l), 'a quad clipped within the panel');

// A box goes with its slots; what reads what was drawn before stops the look.
$l = cullList();
$l->box(150, 150, 60, 60, [8.0, 8.0, 8.0, 8.0], 0xff0000, 1.0, paint: DisplayList::PAINT_CONIC, stops: [[0xff0000, 1.0, 0.0], [0x0000ff, 1.0, 1.0]]);
$l->rect(100, 100, 400, 300, 0xffffff);
$l->cull();
same([-1.0, -1.0, -1.0, -1.0, 0.0], array_map(fn(int $i) => $l->buf[$i * 16 + 9], range(0, 4)), 'a box and its slots and stops: none drawn');
$l = cullList();
$l->rect(110, 110, 50, 20, 0xff0000);
$l->glass(100, 100, 300, 200, 12, 0, 0xffffff, 0.2, 10);
$l->rect(100, 100, 400, 300, 0xffffff);
same([], culledOf($l), 'glass between: it reads what is under it');

// On whole pixels (a window's list) a square fill covers each of its pixels whole: the same rect filled again over it hides it, as a page's background over the window's.
$l = cullList(); $l->pixels = 2.0;
$l->rect(0, 0, 800, 600, 0x000000);            // 0: the window's background
$l->rect(0, 0, 800, 600, 0x111111);            // 1: a page's, over all of it
$l->rect(0, 50, 800, 550, 0x222222);           // 2: a list's, under the bar
same([0], culledOf($l), 'a fill under another of the same rect, on whole pixels');
$l = cullList();
$l->rect(0, 0, 800, 600, 0x000000);
$l->rect(0, 0, 800, 600, 0x111111);
same([], culledOf($l), 'not on whole pixels: its edge may be smoothed');

// A large rounded fill on whole pixels is drawn in pieces (DisplayList::betweenCorners): three square fills between its corners, and its corners, copies clipped to their squares; together they tile it.
$piecesOf = function (DisplayList $l): array {
    $out = [];
    for ($i = 0; $i < $l->n; $i++) {
        $k = $i * 16;
        $out[] = ['rect' => [$l->buf[$k], $l->buf[$k + 1], $l->buf[$k + 2], $l->buf[$k + 3]], 'radius' => $l->buf[$k + 8], 'clip' => [$l->buf[$k + 12], $l->buf[$k + 13], $l->buf[$k + 14], $l->buf[$k + 15]]];
    }
    return $out;
};
$l = cullList(); $l->pixels = 2.0;
$l->rect(100, 100, 400, 300, 0xffffff, 1.0, 20); // a corner's curve reaches 1.528665 × 20 = 30.57 points: 31 on whole pixels
$p = $piecesOf($l);
same(7, $l->n, 'a rounded panel: seven pieces');
same([[100.0, 131.0, 400.0, 238.0], [131.0, 100.0, 338.0, 31.0], [131.0, 369.0, 338.0, 31.0]], array_map(fn(array $q) => $q['rect'], array_slice($p, 0, 3)), 'the bands between its corners');
same([0.0, 0.0, 0.0], array_map(fn(array $q) => $q['radius'], array_slice($p, 0, 3)), 'square');
same([[100.0, 100.0, 31.0, 31.0], [469.0, 100.0, 31.0, 31.0], [100.0, 369.0, 31.0, 31.0], [469.0, 369.0, 31.0, 31.0]], array_map(fn(array $q) => $q['clip'], array_slice($p, 3)), 'its corners, each clipped to its square');
same([[[100.0, 100.0, 400.0, 300.0], 20.0]], array_values(array_unique(array_map(fn(array $q) => [$q['rect'], $q['radius']], array_slice($p, 3)), SORT_REGULAR)), 'each the rounded fill itself');
same(400.0 * 300.0, array_sum(array_map(fn(array $q) => $q['rect'][2] * $q['rect'][3], array_slice($p, 0, 3))) + array_sum(array_map(fn(array $q) => $q['clip'][2] * $q['clip'][3], array_slice($p, 3))), 'together they tile it');
$l->rect(200, 200, 20, 20, 0xff0000);
same(0.0, $l->buf[7 * 16 + 9], 'what follows it is drawn');
$l = cullList(); $l->pixels = 2.0;
$l->rect(200, 200, 20, 20, 0xff0000);           // 0: under its middle
$l->rect(100, 100, 400, 300, 0xffffff, 1.0, 20);
same([0], culledOf($l), 'in pieces, it still hides what is under its middle');
$l = cullList(); $l->pixels = 2.0;
$l->pushClip(100, 100, 120, 120); $l->rect(100, 100, 400, 300, 0xffffff, 1.0, 20); $l->popClip();
$p = $piecesOf($l);
same([[100.0, 100.0, 31.0, 31.0]], array_map(fn(array $q) => $q['clip'], array_slice($p, 3)), 'clipped: the corner its clip keeps');
same([[100.0, 100.0, 120.0, 120.0]], array_values(array_unique(array_map(fn(array $q) => $q['clip'], array_slice($p, 0, 3)), SORT_REGULAR)), 'the bands keep the clip');
foreach ([
    'a capsule, all corners' => fn(DisplayList $l) => $l->rect(100, 100, 400, 60, 0xffffff, 1.0, 30),
    'a small rounded fill' => fn(DisplayList $l) => $l->rect(100, 100, 80, 70, 0xffffff, 1.0, 8),
    'a rounded fill through a map' => function (DisplayList $l) { $l->setWorld(Affine::translation(1, 1)); $l->rect(100, 100, 400, 300, 0xffffff, 1.0, 20); $l->setWorld(Affine::identity()); },
    'a rounded fill drawn whole (noBands)' => function (DisplayList $l) { $l->noBands = true; $l->rect(100, 100, 400, 300, 0xffffff, 1.0, 20); },
] as $what => $draw) {
    $l = cullList(); $l->pixels = 2.0;
    $draw($l);
    same(1, $l->n, "$what: one quad");
}
$l = cullList();
$l->rect(100, 100, 400, 300, 0xffffff, 1.0, 20);
same(1, $l->n, 'not on whole pixels: one quad');
