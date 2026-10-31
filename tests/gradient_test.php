<?php
// Gradient stops, conic gradients, border gradients and element blur
// (mygo's gradient_test.go), as far as the display lists tell: the color a
// box's paint has at a point is worked out here as the shader works it out,
// from the box's slots and the stops after them; a blur, from the canvas it
// draws into.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{ConicGradient, Context, DisplayList, GradientStop, LinearGradient, Node, Painter, Path, RadialGradient, Theme};

/** The first box quad of $l from $from on (kind 14), or -1. */
function gradBox(DisplayList $l, int $from = 0): int
{
    for ($i = $from; $i < $l->n; $i++) {
        if (fmod($l->buf[$i * 16 + 9], DisplayList::KIND_STRIDE) == 14) { return $i; }
    }
    return -1;
}

/** What the paint of box $i of $l shows at the window's point ($px, $py), straight RGBA from 0 to 255, as Metal's paintAt and stopsAt (sRGB mixes); with $border, what paints its border. @return array{0: int, 1: int, 2: int, 3: int} */
function gradAt(DisplayList $l, int $i, float $px, float $py, bool $border = false): array
{
    $b = $l->buf; $k = $i * 16; $s = $k + 16;
    [$x, $y] = [$px - $b[$k], $py - $b[$k + 1]];
    $flags = $b[$s + 11];
    $bp = $flags > 63.5; if ($bp) { $flags -= 64; }
    if ($flags > 31.5) { $flags -= 32; }
    if ($flags > 15.5) { $flags -= 16; }
    $g = [$b[$s + 12], $b[$s + 13], $b[$s + 14], $b[$s + 15]];
    if ($bp && !$border || $flags < 0.5) {
        $c = [$b[$k + 4], $b[$k + 5], $b[$k + 6], $b[$k + 7]];
    } else {
        if ($flags < 5.5) {
            [$dx, $dy] = [$g[2] - $g[0], $g[3] - $g[1]];
            $t = (($x - $g[0]) * $dx + ($y - $g[1]) * $dy) / max($dx * $dx + $dy * $dy, 0.0001);
        } elseif ($flags < 7.5) {
            $t = (atan2($x - $g[0], -($y - $g[1])) - $g[2]) / (2 * M_PI);
            $t -= floor($t);
        } else {
            $t = hypot($x - $g[0], $y - $g[1]) / max($g[2], 0.0001);
        }
        $n = (int)round($b[$k + 10]);
        $stop = static fn(int $j): array => [[$b[($i + 2 + $j) * 16], $b[($i + 2 + $j) * 16 + 1], $b[($i + 2 + $j) * 16 + 2], $b[($i + 2 + $j) * 16 + 3]], $b[($i + 2 + $j) * 16 + 4]];
        [$ca, $aa] = $stop(0); [$cl, $al] = $stop($n - 1);
        if ($t <= $aa) { $c = $ca; } elseif ($t >= $al) { $c = $cl; } else {
            [$cb, $ab] = [$cl, $al];
            for ($j = 1; $j < $n - 1; $j++) {
                [$cc, $ac] = $stop($j);
                if ($t < $ac) { [$cb, $ab] = [$cc, $ac]; break; }
                [$ca, $aa] = [$cc, $ac];
            }
            $f = max(0.0, min(1.0, ($t - $aa) / max($ab - $aa, 1e-6)));
            $a = $ca[3] + ($cb[3] - $ca[3]) * $f;
            $c = [0.0, 0.0, 0.0, $a];
            for ($q = 0; $q < 3; $q++) { $c[$q] = $a > 0 ? ($ca[$q] * $ca[3] + ($cb[$q] * $cb[3] - $ca[$q] * $ca[3]) * $f) / $a : 0.0; }
        }
    }
    return array_map(static fn(float $v): int => (int)round($v * 255), $c);
}

/** Whether $a and $b differ by $tol at most in each component. */
function gradNear(array $a, array $b, int $tol = 8): bool
{
    foreach ($a as $q => $v) { if (abs($v - $b[$q]) > $tol) { return false; } }
    return true;
}

$stops = [new GradientStop(0xff0000, 0), new GradientStop(0x00ff00, 0.25), new GradientStop(0x0000ff, 0.5), new GradientStop(0xff0000, 1)];

// A conic gradient fills the node around its middle, from its angle clockwise, as CSS's conic-gradient().
foreach ([
    [new ConicGradient($stops), 90, 50, [0, 255, 0, 255], 'right: a quarter'],
    [new ConicGradient($stops), 50, 90, [0, 0, 255, 255], 'down: half'],
    [new ConicGradient($stops, angle: 90), 50, 90, [0, 255, 0, 255], 'from the right'],
    [new ConicGradient($stops, 0.1, 0.5), 90, 52, [0, 255, 0, 255], 'around a point off the middle'],
] as [$g, $x, $y, $want, $what]) {
    $h = new Headless(fn(Context $c) => $c->box(100, 100, 0)->at(0, 0)->conicGradient($g), 100, 100);
    $h->frame(); $l = $h->paint(); $i = gradBox($l);
    check(gradNear(gradAt($l, $i, $x + 0.5, $y + 0.5), $want), "conic, $what: " . json_encode(gradAt($l, $i, $x + 0.5, $y + 0.5)));
}
same([6.0, 4.0, 6], [$l->buf[($i + 1) * 16 + 11], $l->buf[$i * 16 + 10], $l->n - $i], 'conic (6), its four stops each a slot after the box');
$h = new Headless(fn(Context $c) => $c->box(100, 100, 0)->conicGradient(new ConicGradient($stops, oklab: true)), 100, 100);
$h->frame(); $l = $h->paint();
same(7.0, $l->buf[(gradBox($l) + 1) * 16 + 11], 'in Oklab (7)');

// A linear gradient with stops shows each where it lies along the line.
$h = new Headless(fn(Context $c) => $c->box(100, 20, 0)->at(0, 0)->linearGradient(new LinearGradient(angle: 90, stops: [new GradientStop(0xff0000, 0.25), new GradientStop(0x00ff00, 0.5), new GradientStop(0x0000ff, 0.75)])), 100, 20);
$h->frame(); $l = $h->paint(); $i = gradBox($l);
same(4.0, $l->buf[($i + 1) * 16 + 11], 'stops along a line (4)');
foreach ([[5, [255, 0, 0, 255]], [50, [0, 255, 0, 255]], [95, [0, 0, 255, 255]]] as [$x, $want]) {
    check(gradNear(gradAt($l, $i, $x + 0.5, 10.5), $want), "linear stops at $x: " . json_encode(gradAt($l, $i, $x + 0.5, 10.5)));
}
check(gradNear(gradAt($l, $i, 37.5, 10.5), [128, 128, 0, 255], 4), 'between two, both mixed: ' . json_encode(gradAt($l, $i, 37.5, 10.5)));
// Out of order, a stop goes where the one before it is; past 32 they are left out; the node's opacity fades them.
$many = array_map(fn(int $k) => new GradientStop($k % 2 ? 0xffffff : 0, $k / 40), range(0, 39));
$list = $h->paint(); $list->reset();
$list->setOpacity(0.5);
(new Painter($list, $h->metrics, Theme::light()))->fillGradient([0, 0, 10, 10], new LinearGradient(stops: [new GradientStop(0xff0000, 0.6), new GradientStop(0x0000ff, 0.2)]));
same([0.6, 0.6, 0.5], [round($list->buf[2 * 16 + 4], 3), round($list->buf[3 * 16 + 4], 3), $list->buf[3 * 16 + 3]], 'a stop out of order at the one before; its alpha at the opacity');
$list->reset();
(new Painter($list, $h->metrics, Theme::light()))->fillGradient([0, 0, 10, 10], new LinearGradient(stops: $many));
same([32.0, 2 + 32], [$list->buf[10], $list->n], 'at most 32 stops');
same([0.628, 0.225, 0.126], array_map(fn(float $v) => round($v, 3), DisplayList::oklab(0xff0000)), 'red in Oklab');

// A radial gradient with stops, from its center out to its radius.
$h = new Headless(fn(Context $c) => $c->box(100, 100, 0)->at(0, 0)->radialGradient(new RadialGradient(radius: 40, stops: [new GradientStop(0xffffff, 0), new GradientStop(0xff0000, 0.5), new GradientStop(0x0000ff, 1)])), 100, 100);
$h->frame(); $l = $h->paint(); $i = gradBox($l);
check(gradNear(gradAt($l, $i, 70, 50), [255, 0, 0, 255]) && gradNear(gradAt($l, $i, 95, 50), [0, 0, 255, 255]), 'radial stops: half way out red, past the radius blue');

// A border painted with a conic gradient takes its colors around the node, over the background, which keeps its own.
$h = new Headless(fn(Context $c) => $c->box(100, 100, 0)->at(0, 0)->background(0xffffff)->border(8, 0x000000)
    ->borderConicGradient(new ConicGradient([new GradientStop(0xff0000, 0), new GradientStop(0x0000ff, 0.5), new GradientStop(0xff0000, 1)])), 100, 100);
$h->frame(); $l = $h->paint();
$i = gradBox($l);
$s = ($i + 1) * 16;
same([6.0 + 64, [8.0, 8.0, 8.0, 8.0], 0xffffff, 1.0, 0.0], [$l->buf[$s + 11], [$l->buf[$s + 4], $l->buf[$s + 5], $l->buf[$s + 6], $l->buf[$s + 7]], (int)$l->buf[$s + 8], $l->buf[$s + 10], $l->buf[$i * 16 + 7]],
    'the border painted with the gradient (64), times opaque white; the inside, none');
check(gradNear(gradAt($l, $i, 50, 96, true), [0, 0, 255, 255], 10), 'the border below blue');
check(gradAt($l, $i, 50, 3, true)[0] > 200 && gradAt($l, $i, 50, 3, true)[2] < 60, 'the border above red');
$fills = 0;
for ($q = 0; $q < $i; $q++) { if ($l->buf[$q * 16 + 4] == 1.0 && $l->buf[$q * 16 + 5] == 1.0 && $l->buf[$q * 16 + 6] == 1.0 && $l->buf[$q * 16 + 2] == 100.0) { $fills++; } }
same(1, $fills, 'the white background under it, of its own');
// A linear gradient of two colors too, as two stops, left to right.
$h = new Headless(fn(Context $c) => $c->box(100, 100, 0)->at(0, 0)->borderWidth(8)->borderLinearGradient(new LinearGradient(0xff0000, 1.0, 0x0000ff, 1.0, 90)), 120, 120);
$h->frame(); $l = $h->paint(); $i = gradBox($l);
same([4.0 + 64, 2.0], [$l->buf[($i + 1) * 16 + 11], $l->buf[$i * 16 + 10]], 'a border of a linear gradient: its two colors as stops');
check(gradAt($l, $i, 3, 50, true)[0] > 200 && gradAt($l, $i, 96, 50, true)[2] > 200, 'its sides red then blue');
same(0.0, $l->buf[$i * 16 + 7], 'the inside showing what is under it');
$h->frame();
$own = json_encode((fn() => self::own(new \MyPHP\UI\Inspector(), $h->rt, $h->root->kids[0]))->bindTo(null, \MyPHP\UI\InspectorDetails::class)(), JSON_UNESCAPED_SLASHES);
check(str_contains($own, '"border-image","gradient"'), 'the inspector shows it as a border image: ' . $own);

// Paths fill with a conic gradient around their bounds.
$h = new Headless(fn(Context $c) => $c->box(10, 10, 0), 100, 100);
$h->frame();
$list = $h->paint(); $list->reset();
$disc = (new Path())->circle(50, 50, 40);
(new Painter($list, $h->metrics, Theme::light()))->fillPathConicGradient($disc, new ConicGradient([new GradientStop(0xff0000, 0), new GradientStop(0x0000ff, 0.5), new GradientStop(0xff0000, 1)]));
$i = gradBox($list);
same(6.0 + 32, $list->buf[($i + 1) * 16 + 11], 'a path\'s mask (32) painted conic (6)');
check(gradNear(gradAt($list, $i, 50, 85), [0, 0, 255, 255], 12) && gradAt($list, $i, 50, 15)[0] > 200, 'below the middle blue, above red');

// A blurred node draws into a canvas of its box and 3 sigma more, at half the pixels for a blur this wide, blurred by
// what is left of it; the canvas shows where it was drawn from, and the node still takes clicks.
$clicked = false;
$h = new Headless(function (Context $c) use (&$clicked): void {
    $c->column()->at(0, 0)->size(120, 120)->justify(Node::CENTER)->alignItems(Node::CENTER);
    $b = $c->box(40, 40, 0x000000)->blur(6)->label('Blurred');
    if ($c->clicked($b)) { $clicked = true; }
    $c->end();
}, 120, 120);
$h->frame(); $l = $h->paint();
$cv = $h->rt->blurLayers[array_key_first($h->rt->blurLayers)] ?? null;
check($cv !== null && [$cv->w, $cv->h] === [38, 38], 'a canvas of (40 + 36) / 2 pixels a side: ' . json_encode($cv === null ? null : [$cv->w, $cv->h]));
same(round(sqrt(36 - 4 / 12) / 2, 4), round($cv->blur, 4), 'blurred by what the halving leaves of 6 pixels, in its pixels');
$img = null;
for ($q = 0; $q < $l->n; $q++) { if (fmod($l->buf[$q * 16 + 9], DisplayList::KIND_STRIDE) == 2) { $img = [$l->buf[$q * 16], $l->buf[$q * 16 + 1], $l->buf[$q * 16 + 2], $l->buf[$q * 16 + 3]]; } }
same([22.0, 22.0, 76.0, 76.0], $img, 'shown over its box and 18 points around');
check(gradBox($l) === -1, 'the node itself is not painted in the window');
$cb = $cv->list->buf;
check($cv->list->n === 1 && [$cb[0], $cb[2]] == [40.0, 40.0] && fmod($cb[9], DisplayList::KIND_STRIDE) == 0, 'in the canvas: the node as laid out');
$xk = intdiv((int)$cb[9], (int)DisplayList::KIND_STRIDE);
$x = $cv->list->xforms; $e = $xk * DisplayList::FLOATS_PER_TRANSFORM;
same([0.5, 0.5, 9.0, 9.0], [$x[$e], $x[$e + 3], $x[$e] * 40 + $x[$e + 4], $x[$e + 3] * 40 + $x[$e + 5]], 'drawn through the map to the canvas\'s pixels');
$h->click(60, 60);
check($clicked, 'the blurred node takes clicks');
$own = json_encode((fn() => self::own(new \MyPHP\UI\Inspector(), $h->rt, $h->root->kids[0]->kids[0]))->bindTo(null, \MyPHP\UI\InspectorDetails::class)(), JSON_UNESCAPED_SLASHES);
check(str_contains($own, 'blur(6px)'), 'the inspector shows the filter');
// Out of the clip, nothing; too large for a canvas, painted as it is.
$h = new Headless(fn(Context $c) => $c->box(40, 40, 0x000000)->at(500, 500)->blur(2), 100, 100);
$h->frame(); $l = $h->paint();
same([[], -1], [$h->rt->blurLayers, gradBox($l)], 'out of the clip, no canvas');
$h = new Headless(fn(Context $c) => $c->box(9000, 40, 0x000000)->at(0, 0)->blur(1), 100, 100);
$h->frame(); $l = $h->paint();
check($h->rt->blurLayers === [] && $l->n > 0, 'too wide a canvas: as it is');
// Wider than a page of the canvases, its own texture, drawn by a shader.
$h = new Headless(fn(Context $c) => $c->box(3000, 40, 0x000000)->at(0, 0)->blur(1), 100, 100);
$h->frame(); $l = $h->paint();
$layer = array_values($h->rt->blurLayers)[0] ?? null;
check($layer?->loose() && count($l->shaders) === 1 && $l->shaders[0][3][0] === $layer->bitmap(), 'wider than a page: its own texture, a shader drawing it');
