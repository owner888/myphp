<?php
// Rings, border-only boxes and box shadows with round corners drawn in
// bands (mygo's TestBandsCoverWhatDraws and the split cases of
// TestRingSplit): the pieces of a shape never overlap, hold every point
// where its shader may draw anything, computed a point nearer to drawing,
// and shade less than the shape whole; other fills are drawn whole.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Bands, DisplayList};

$bandList = function (bool $bands): DisplayList {
    $ffi = \FFI::cdef();
    $l = new DisplayList($ffi->new('float[' . (4096 * 16) . ']'), 4096, $ffi->new('float[' . (16 * 16) . ']'), 16);
    $l->noBands = !$bands;
    $l->pushClip(0, 0, 2000, 2000);
    return $l;
};
/** The quads of a list: [rect, clip, the floats]. */
$quadsOf = function (DisplayList $l): array {
    $out = [];
    for ($i = 0; $i < $l->n; $i++) {
        $k = $i * 16;
        if ($l->buf[$k + 9] < 0) { continue; } // a box's second slot
        $out[] = [[$l->buf[$k], $l->buf[$k + 1], $l->buf[$k + 2], $l->buf[$k + 3]], [$l->buf[$k + 12], $l->buf[$k + 13], $l->buf[$k + 14], $l->buf[$k + 15]]];
    }
    return $out;
};
/** The shaders' sdRoundRect: the distance from (x, y) to the edge of rect r with circular corners of radii, negative inside. */
$sd = function (float $x, float $y, array $r, array $radii): float {
    [$hx, $hy] = [$r[2] / 2, $r[3] / 2];
    [$qx, $qy] = [$x - $r[0] - $hx, $y - $r[1] - $hy];
    $rad = $qx < 0 && $qy < 0 ? $radii[0] : ($qy < 0 ? $radii[1] : ($qx >= 0 ? $radii[2] : $radii[3]));
    [$ax, $ay] = [abs($qx) - $hx + $rad, abs($qy) - $hy + $rad];
    return hypot(max($ax, 0), max($ay, 0)) + min(max($ax, $ay), 0) - $rad;
};
$cover = fn(float $d): float => min(max(0.5 - $d, 0.0), 1.0);

$cases = [
    // [what, draw(list), the region it may draw in, draws(x, y): whether it may draw there, a point nearer]
    ['a circle a point wide', fn(DisplayList $l) => $l->ring(100, 100, 300, 300, 0x000000, 1.0, 150, 1),
        fn(float $x, float $y) => $cover($sd($x, $y, [100, 100, 300, 300], [150, 150, 150, 150]) - 1) - $cover($sd($x, $y, [101, 101, 298, 298], [149, 149, 149, 149]) + 1) > 0],
    ['a pill\'s ring', fn(DisplayList $l) => $l->ring(50, 300, 600, 80, 0x000000, 1.0, 40, 2),
        fn(float $x, float $y) => $cover($sd($x, $y, [50, 300, 600, 80], [40, 40, 40, 40]) - 1) - $cover($sd($x, $y, [52, 302, 596, 76], [38, 38, 38, 38]) + 1) > 0],
    ['a card\'s border alone', fn(DisplayList $l) => $l->box(20, 20, 500, 300, [24, 24, 24, 24], 0xffffff, 0.0, [1, 1, 1, 1], 0x333333, 1.0),
        fn(float $x, float $y) => $cover($sd($x, $y, [20, 20, 500, 300], [24, 24, 24, 24]) - 1) - $cover($sd($x, $y, [21, 21, 498, 298], [23, 23, 23, 23]) + 1) > 0],
    ['borders of their own widths', fn(DisplayList $l) => $l->box(20, 400, 400, 200, [40, 10, 0, 60], 0xffffff, 0.0, [4, 1, 8, 2], 0x333333, 1.0),
        fn(float $x, float $y) => $cover($sd($x, $y, [20, 400, 400, 200], [40, 10, 0, 60]) - 1) - $cover($sd($x, $y, [22, 404, 397, 188], DisplayList::fitRadii(397, 188, [36, 6, 0, 52])) + 1) > 0],
    ['a circle\'s shadow', fn(DisplayList $l) => $l->boxShadow(600, 100, 200, 200, [100, 100, 100, 100], 0x000000, 0.4, 8, false, [600, 100, 200, 200], [100, 100, 100, 100]),
        fn(float $x, float $y) => $cover($sd($x, $y, [600, 100, 200, 200], [100, 100, 100, 100]) + 1) < 1],
    ['a pill\'s shadow', fn(DisplayList $l) => $l->boxShadow(100, 700, 600, 60, [30, 30, 30, 30], 0x000000, 0.3, 12, false, [100, 690, 600, 60], [30, 30, 30, 30]),
        fn(float $x, float $y) => $cover($sd($x, $y, [100, 690, 600, 60], [30, 30, 30, 30]) + 1) < 1],
];
foreach ($cases as [$what, $draw, $draws]) {
    $split = $bandList(true); $whole = $bandList(false);
    $draw($split); $draw($whole);
    $pieces = $quadsOf($split); $one = $quadsOf($whole);
    $wq = $one[0][0]; $wc = $one[0][1];
    $region = [max($wq[0], $wc[0]), max($wq[1], $wc[1]), min($wq[0] + $wq[2], $wc[0] + $wc[2]), min($wq[1] + $wq[3], $wc[1] + $wc[3])];
    $clips = array_map(fn($q) => [max($q[0][0], $q[1][0]), max($q[0][1], $q[1][1]), min($q[0][0] + $q[0][2], $q[1][0] + $q[1][2]), min($q[0][1] + $q[0][3], $q[1][1] + $q[1][3])], $pieces);
    check(count($pieces) > 1 && count(array_unique(array_map(fn($q) => json_encode($q[0]), $pieces))) === 1, "$what: drawn in pieces of the same quad (" . count($pieces) . ')');
    $overlap = false;
    foreach ($clips as $i => $a) {
        foreach (array_slice($clips, $i + 1) as $b) {
            if (min($a[2], $b[2]) > max($a[0], $b[0]) && min($a[3], $b[3]) > max($a[1], $b[1])) { $overlap = true; }
        }
    }
    check(!$overlap, "$what: no pieces overlap");
    $missed = 0;
    for ($y = floor($region[1]); $y < $region[3]; $y += 0.5) {
        for ($x = floor($region[0]); $x < $region[2]; $x += 0.5) {
            [$px, $py] = [$x + 0.25, $y + 0.25]; // the pixels of a scale of 2
            if ($px < $region[0] || $py < $region[1] || $px >= $region[2] || $py >= $region[3] || !$draws($px, $py)) { continue; }
            $held = false;
            foreach ($clips as $c) { if ($px >= $c[0] && $px < $c[2] && $py >= $c[1] && $py < $c[3]) { $held = true; break; } }
            if (!$held) { $missed++; }
        }
    }
    same(0, $missed, "$what: every point it may draw at is in a piece");
    $area = array_sum(array_map(fn($c) => max(0, $c[2] - $c[0]) * max(0, $c[3] - $c[1]), $clips));
    $wholeArea = ($region[2] - $region[0]) * ($region[3] - $region[1]);
    check($area < $wholeArea, sprintf('%s: shades %d square points in %d pieces, whole %d', $what, $area, count($clips), $wholeArea));
}

// Other fills are drawn whole: a filled box, a ring too small to pay for its pieces, a turned ring, an inset shadow.
$l = $bandList(true);
$l->box(0, 0, 400, 400, [20, 20, 20, 20], 0xffffff, 1.0, [1, 1, 1, 1], 0x333333, 1.0);
$l->ring(0, 0, 20, 20, 0, 1.0, 10, 1);
$l->setWorld(\MyPHP\UI\Affine::rotation(30));
$l->ring(0, 0, 300, 300, 0, 1.0, 150, 1);
$l->setWorld(\MyPHP\UI\Affine::identity());
$l->boxShadow(0, 0, 300, 300, [150, 150, 150, 150], 0, 1.0, 8, true, [20, 20, 260, 260], [130, 130, 130, 130]);
same(4, count($quadsOf($l)), 'a filled box, a small ring, a turned ring and an inset shadow: one quad each');

// A ring clipped out of sight draws nothing; one clipped draws its pieces within the clip.
$l = $bandList(true);
$l->pushClip(0, 0, 200, 150);
$l->ring(100, 100, 300, 300, 0, 1.0, 150, 1);
$cs = array_map(fn($q) => $q[1], $quadsOf($l));
check($cs !== [] && max(array_map(fn($c) => $c[1] + $c[3], $cs)) <= 150 && max(array_map(fn($c) => $c[0] + $c[2], $cs)) <= 200, 'pieces within the clip');
