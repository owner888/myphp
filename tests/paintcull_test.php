<?php
// Painting skips the nodes whose ink shows nowhere (mygo's paintcull_test.go):
// a long column scrolled by a translation in a clip paints only the rows that
// show, and every frame of trees changing and moving, styled to paint past
// their boxes, paints with the skipping what it paints without.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Context, DisplayList, Node, TextPainter, Theme};

// The rows of a long column scrolled by a translation in a clip are painted only where they show.
$dy = 0.0;
$h = new Headless(function (Context $c) use (&$dy): void {
    $c->column()->fill()->clip();
      $c->column()->translate(0, $dy);
        for ($i = 0; $i < 200; $i++) {
            $c->row(0, 8)->height(28);
              $c->text("Row $i");
              $c->spacer();
              $c->text((string)($i * 7 % 100));
            $c->end();
        }
      $c->end();
    $c->end();
}, 400, 300);
foreach ([0.0, -1000.0, -4000.0] as $d) {
    $dy = $d;
    $h->frame();
    $was = $h->rt->culled;
    $h->paint();
    $n = $h->rt->culled - $was;
    check($n >= 188 && $n <= 190, "at $d, $n rows skipped whole, 11 at most showing");
}

// Trees styled to paint past their boxes, moved out of their clips and back: the same quads with the skipping and without.
$extras = [
    null,
    fn(Node $n) => $n->translate(0, -260),
    fn(Node $n) => $n->translate(0, 340),
    fn(Node $n) => $n->translate(-420, 15),
    fn(Node $n) => $n->rotate(30),
    fn(Node $n) => $n->scale(0.5, 3),
    fn(Node $n) => $n->blur(2),
    fn(Node $n) => $n->shadow(0, 60, 30, 10, 0x000000, 0.4),
    fn(Node $n) => $n->draw(fn(DisplayList $l, Node $b) => $l->rect($b->x - 30, $b->y - 30, $b->w + 60, 10, 0x007800)),
    fn(Node $n) => $n->drawOver(fn(DisplayList $l, Node $b) => $l->rect($b->x, $b->y + $b->h, 20, 40, 0x0000a0, 1.0, 2)),
    fn(Node $n) => $n->dividers(1, 0x5a5a5a),
    fn(Node $n) => $n->debug(),
    fn(Node $n) => $n->background(0xfafac8),
    fn(Node $n) => $n->disabled()->background(0x1e1e1e),
];
/** The quads of $l that show: some of each within its clip, through its transform (painting everything paints a container's background out of view too), each with its transform. @return list<list<float>> */
$shown = function (DisplayList $l): array {
    $out = [];
    for ($i = 0; $i < $l->n; $i++) {
        $q = []; for ($j = 0; $j < 16; $j++) { $q[] = $l->buf[$i * 16 + $j]; }
        $e = (int)floor($q[9] / DisplayList::KIND_STRIDE) * DisplayList::FLOATS_PER_TRANSFORM;
        if ($q[9] < 0) { continue; } // hidden
        $xs = $ys = [];
        foreach ([[$q[0], $q[1]], [$q[0] + $q[2], $q[1]], [$q[0], $q[1] + $q[3]], [$q[0] + $q[2], $q[1] + $q[3]]] as [$x, $y]) {
            $t = $l->xforms; // a, b, c, d, x, y, g, h
            $w = $t[$e + 6] * $x + $t[$e + 7] * $y + 1;
            $xs[] = ($t[$e] * $x + $t[$e + 2] * $y + $t[$e + 4]) / $w; $ys[] = ($t[$e + 1] * $x + $t[$e + 3] * $y + $t[$e + 5]) / $w;
        }
        if (min($xs) < $q[12] + $q[14] && max($xs) > $q[12] && min($ys) < $q[13] + $q[15] && max($ys) > $q[13]) {
            $q[9] = fmod($q[9], DisplayList::KIND_STRIDE); // its transform by what it is, not where it is in the table, which nodes skipped leave shorter
            if ($q[9] == 0.0) { $q[10] = $q[11] = 0.0; } // a rectangle's, which it leaves as they were
            for ($j = 0; $j < 8; $j++) { $q[] = $l->xforms[$e + $j]; }
            $out[] = $q;
        }
    }
    return $out;
};
$culled = 0;
for ($seed = 1; $seed <= 6; $seed++) {
    mt_srand($seed);
    $style = [];
    $shift = 0.0;
    $build = function (Context $c, int $depth, string $path) use (&$build, &$style, $extras): void {
        $kids = $depth >= 3 ? 0 : mt_rand(1, 4);
        for ($i = 0; $i < $kids; $i++) {
            $p = "$path.$i";
            $n = $i % 2 === 0 ? $c->column(4, 4) : $c->row(4, 4);
            if (isset($style[$p]) && ($f = $extras[$style[$p]]) !== null) { $f($n); }
            if ($depth === 2) { $c->text("t$p"); $c->box(30, 12, 0x3366cc)->shadow(0, 2, 4, 0, 0x000000, 0.3); }
            $build($c, $depth + 1, $p);
            $c->end();
        }
    };
    $h = new Headless(function (Context $c) use ($build, &$shift): void {
        $c->column()->fill()->clip();
          $c->column(0, 6)->translate(0, $shift);
            for ($s = 0; $s < 6; $s++) { $c->column(8, 8); $build($c, 0, (string)$s); $c->end(); }
          $c->end();
        $c->end();
    }, 400, 300);
    for ($f = 0; $f < 12; $f++) {
        foreach (range(0, 24) as $_) { $style[mt_rand(0, 5) . '.' . mt_rand(0, 3) . (mt_rand(0, 1) ? '.' . mt_rand(0, 3) : '')] = mt_rand(0, count($extras) - 1); }
        $shift = -mt_rand(0, 1200);
        $h->frame();
        $was = $h->rt->culled;
        $a = $shown($h->paint());
        $culled += $h->rt->culled - $was;
        $h->noCull = true;
        $b = $shown($h->paint());
        $h->noCull = false;
        if ($a !== $b) {
            check(false, sprintf('seed %d, frame %d: %d quads show with the skipping, %d without', $seed, $f, count($a), count($b)));
            break 2;
        }
    }
}
check($culled > 0, "the skipping skipped nodes: $culled");
