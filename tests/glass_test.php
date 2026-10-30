<?php
// The glass plugin (mygo's plugins/glass tests): the quads Liquid Glass,
// the blur's levels along their mask and the scroll edges leave in the
// display list, what the shader's weight of a level makes of them, and an
// interactive glass growing while pressed. The pixels are the shader's,
// which runs on the Mac only.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Plugins\Glass\{Blur, Glass, ScrollEdge, Style};
use MyPHP\UI\{Context, DisplayList, LinearGradient, Node, Painter, TextPainter, Theme};

/** The quads of a list: [x, y, w, h, r, g, b, a, radius, kind, u, v] each. @return list<list<float>> */
function glassQuads(DisplayList $list): array
{
    $out = [];
    for ($i = 0; $i < $list->n; $i++) {
        $q = [];
        for ($j = 0; $j < 12; $j++) { $q[] = $list->buf[$i * 16 + $j]; }
        $q[9] = fmod($q[9], DisplayList::KIND_STRIDE);
        $out[] = $q;
    }
    return $out;
}

/** The line of the first box's gradient (its slot after's last four floats), from its top left. @return list<float> */
function boxLine(Headless $h): array
{
    $list = $h->paint();
    for ($i = 0; $i < $list->n; $i++) {
        if (fmod($list->buf[$i * 16 + 9], DisplayList::KIND_STRIDE) == 14.0) {
            $k = ($i + 1) * 16;
            return array_map(fn(float $v): float => round($v, 6) + 0.0, [$list->buf[$k + 12], $list->buf[$k + 13], $list->buf[$k + 14], $list->buf[$k + 15]]);
        }
    }
    return [];
}

/** The quads of kind $kind among a frame's. @return list<list<float>> */
function glassKind(Headless $h, int $kind): array
{
    return array_values(array_filter(glassQuads($h->paint()), fn(array $q): bool => (int)$q[9] === $kind));
}

/** How much the level of quad $q shows at its point (x, y), as the shader works it out. */
function glassWeight(array $q, float $x, float $y): float
{
    $t = max(0.0, min(1.0, $q[6] + $q[4] * $x + $q[5] * $y));
    return max(0.0, min(1.0, $q[10] + $q[11] * $t)) * $q[7];
}

// The levels of a blur: halving from the most down to 2 pixels, adding up as variances do.
same([], Blur::levels(0, 0), 'no blur, no level');
same([[8.0, -1.0, 0.0]], Blur::levels(8, 8), 'a blur that does not vary is one level that shows everywhere');
$levels = Blur::levels(0, 16);
same([[2.0, 0.0, 2.0], [sqrt(12), 2.0, 4.0], [sqrt(48), 4.0, 8.0], [sqrt(192), 8.0, 16.0]], $levels, 'levels from nothing to 16 pixels');
$sum = 0.0;
foreach ($levels as [$b]) { $sum += $b * $b; }
check(abs(sqrt($sum) - 16) < 1e-9, 'all the levels add up to the most');
same([[4.0, -1.0, 0.0], [sqrt(48), 4.0, 8.0], [sqrt(192), 8.0, 16.0]], Blur::levels(4, 16), 'from 4 pixels: a level everywhere, then those above');
// The mask's line, across the rectangle at its angle.
same([50.0, 0.0, 50.0, 50.0], array_map(fn($v) => round($v, 9), Blur::maskLine([0, 0, 100, 50], new LinearGradient(angle: 180))), 'downward: from the top to the bottom');
same([0.0, 25.0, 100.0, 25.0], array_map(fn($v) => round($v, 9), Blur::maskLine([0, 0, 100, 50], new LinearGradient(angle: 90))), 'rightward');
same([50.0, 10.0, 50.0, 40.0], array_map(fn($v) => round($v, 9), Blur::maskLine([0, 0, 100, 50], new LinearGradient(angle: 180, start: 0.2, end: 0.8))), 'start and end along it');
// Where a level is wanted: the part of the box past its lo.
same([0.0, 0.0, 100.0, 25.0], Blur::wanted([0, 0, 100, 50], [50, 0, 50, 50], 16, 0, 8), 'a blur fading downward is more than half its most in the top half');
same(null, Blur::wanted([0, 0, 100, 50], [50, 0, 50, 50], 4, 4, 8), 'nowhere more than it is');

// A blur fading downward over 100 × 80 points at 2×: a quad a level, each where it is wanted, weighted along the mask.
$h = new Headless(function (Context $c): void {
    $c->column(0, 0, 1, 0x3366cc);
      $c->column()->at(0, 0)->size(100, 80)->material(new Blur(8, new LinearGradient(0x000000, 1, 0x000000, 0, 180)));
      $c->end();
    $c->end();
}, 200, 200);
$h->metrics->scale = 2;
$h->frame();
$q = glassKind($h, 7);
same(count(Blur::levels(0, 16)), count($q), 'a quad a level');
$list = $h->paint();
same(count($q), count($list->glass), 'each level splits the draw');
check(abs($list->glass[3][1] - sqrt(192)) < 1e-9, 'the last level blurs by √(16² − 8²) pixels');
$last = $q[3]; // blur wanted from 8 to 16 pixels: the top half of the box
same([0.0, 0.0, 100.0, 40.0], array_slice($last, 0, 4), 'the last level covers where it is wanted, the top half');
check(abs(glassWeight($last, 50, 0) - 1) < 1e-5 && abs(glassWeight($last, 50, 40)) < 1e-5 && abs(glassWeight($last, 50, 20) - 0.5) < 1e-5, 'it shows wholly at the top, not at the middle, half between');
$first = $q[0]; // from nothing to 2 pixels: the bottom eighth fades it in
check(abs(glassWeight($first, 50, 80)) < 1e-5 && abs(glassWeight($first, 50, 70) - 1) < 1e-5, 'the first level fades in over the bottom eighth');
// An even blur: one level, everywhere, at the box's radius.
$h = new Headless(function (Context $c): void {
    $c->column()->at(10, 10)->size(60, 30)->round(8)->material(new Blur(6));
    $c->end();
}, 100, 100);
$h->frame();
$q = glassKind($h, 7);
check(count($q) === 1 && $q[0][8] == 8 && abs(glassWeight($q[0], 0, 0) - 1) < 1e-9 && abs(glassWeight($q[0], 60, 30) - 1) < 1e-9, 'an even blur is one level, shown everywhere: ' . json_encode($q));
same(6.0, $h->paint()->glass[0][1], 'blurred by its radius in pixels');

// Glass: one quad of the glass's kind, blurred by its size, regular or clear, tinted.
$glass = new Glass;
$h = new Headless(function (Context $c) use (&$glass): void {
    $c->row(8, 16)->at(20, 20)->size(200, 44)->round(22)->material($glass);
      $c->text('On glass');
    $c->end();
}, 300, 100);
$h->frame();
$g = glassKind($h, 5);
check(count($g) === 1 && array_slice($g[0], 0, 4) === [20.0, 20.0, 200.0, 44.0] && $g[0][8] == 22 && $g[0][10] == 0, 'a pane of regular glass, round: ' . json_encode($g));
same(44 * 0.035, $h->paint()->glass[0][1], 'larger panes blur more');
check(in_array('On glass', $h->texts(), true), 'its children draw on it');
$glass = new Glass(Style::Clear, 0x007aff, 0.5);
$h->frame();
$g = glassKind($h, 5)[0];
check($g[10] == 1 && abs($g[7] - 0.44) < 1e-6 && abs($g[6] - 1.0) < 1e-6, 'clear, tinted by the tint\'s alpha × 0.88: ' . json_encode($g));
same(1.0, $h->paint()->glass[0][1], 'clear glass blurs by a pixel');

// Interactive glass grows while pressed, and back once let go.
$h = new Headless(function (Context $c): void {
    $c->column()->at(50, 50)->size(200, 100)->round(20)->material(new Glass(interactive: true));
    $c->end();
}, 300, 200);
$h->rt->reduceMotion = true; // at once
$h->frame();
same([50.0, 50.0, 200.0, 100.0, 20.0], [...array_slice(glassKind($h, 5)[0], 0, 4), glassKind($h, 5)[0][8]], 'at rest');
$h->press(150, 100);
$h->frame();
$g = glassKind($h, 5)[0];
check(abs($g[0] - 48.9) < 1e-4 && abs($g[2] - 202.2) < 1e-4 && abs($g[1] - 49.55) < 1e-4 && abs($g[3] - 100.9) < 1e-4 && abs($g[8] - 20.45) < 1e-4, 'pressed, grown 1.1 × 0.45 points: ' . json_encode(array_slice($g, 0, 9)));
$h->release(150, 100);
$h->frame();
same(50.0, glassKind($h, 5)[0][0], 'let go, it shrinks back');

// Glass in a drawing.
$h = new Headless(function (Context $c): void {
    $c->column(0, 0, 1)->draw(function (DisplayList $list, Node $n, Theme $t, TextPainter $text): void {
        Glass::paint(new Painter($list, $text, $t), [20.0, 20.0, 100.0, 60.0], 20, new Glass);
    });
    $c->end();
}, 200, 120);
$h->frame();
same([20.0, 20.0, 100.0, 60.0, 20.0], [...array_slice(glassKind($h, 5)[0], 0, 4), glassKind($h, 5)[0][8]], 'a pane painted in a drawing');

// Scroll edges: soft, the background's gradient from 85% to nothing; hard, the toned blur and a hairline.
$edge = new ScrollEdge;
$h = new Headless(function (Context $c) use (&$edge): void {
    $c->column()->at(0, $edge->bottom ? 60 : 0)->size(200, 40)->material($edge);
    $c->end();
}, 200, 100);
$h->frame();
$soft = glassKind($h, 14);
check(count($soft) === 1 && abs($soft[0][7] - 0.85) < 1e-6 && $soft[0][11] == 0.0, 'soft: from 85% to nothing: ' . json_encode($soft));
same([100.0, 0.0, 100.0, 40.0], boxLine($h), 'down');
$edge = new ScrollEdge(bottom: true);
$h->frame();
$soft = glassKind($h, 14)[0];
check(abs($soft[7] - 0.85) < 1e-6 && $soft[11] == 0.0, 'soft at the bottom: from 85% to nothing');
same([100.0, 40.0, 100.0, 0.0], boxLine($h), 'up');
$edge = new ScrollEdge(hard: true);
$h->frame();
$hard = glassKind($h, 8);
check(count($hard) === 1 && abs($hard[0][7] - 0.8245) < 1e-6, 'hard: the blur under 82.45% of the background');
same(6.4, $h->paint()->glass[0][1], 'blurred by 6.4 points');
$lines = array_values(array_filter(glassQuads($h->paint()), fn(array $q): bool => (int)$q[9] === 0 && $q[1] == 40.0));
check(count($lines) === 1 && $lines[0][3] == 1.0 && abs($lines[0][7] - 0.035) < 1e-6, 'the hairline along its other edge, a pixel at half its alpha (dark: white at 7%): ' . json_encode($lines));
same(ScrollEdge::toned(Theme::dark()->background, 0.947, 0.03), (int)round($hard[0][4] * 255) << 16 | (int)round($hard[0][5] * 255) << 8 | (int)round($hard[0][6] * 255), 'the background toned');
same(0xffffff, ScrollEdge::toned(0xffffff, 0.947, 0.03), 'toned clamps');

// fillGradient draws at any angle: at 45°, from the bottom left corner to the top right, as CSS's.
$list = $h->paint(); $list->reset();
(new Painter($list, $h->metrics, Theme::light()))->fillGradient([0, 0, 10, 10], new LinearGradient(angle: 45));
$g = [$list->buf[28], $list->buf[29], $list->buf[30], $list->buf[31]];
check(abs($g[0]) < 1e-5 && abs($g[1] - 10) < 1e-5 && abs($g[2] - 10) < 1e-5 && abs($g[3]) < 1e-5, 'a gradient at 45°: ' . json_encode($g));

// Clarity (mygo's TestPaneMaterial, TestPaneClarity, TestClarityZero, TestClarityClear): panes up to 100 points keep the measured material whatever the clarity; from 200 theirs is the clarity's, eased between.
(function () {
    $mat = fn(float $m, float $s, float $k, bool $clear, bool $dark): array => Glass::paneMaterial($m, $s, $k, $clear, $dark);
    $near = fn(float $a, float $b): bool => abs($a - $b) < 1e-9;
    $bad = [];
    foreach ([0, 0.25, 0.5, 1] as $k) {
        foreach ([20, 44, 72, 100] as $m) {
            foreach ([1, 2] as $s) {
                $want = ['blur' => min(max($m * 0.035, 1), 10) * $s, 'bezel' => min(36, $m / 2) * $s, 'rim' => 0.35];
                $l = $mat($m, $s, $k, false, false); $d = $mat($m, $s, $k, false, true);
                if ($l != $want + ['low' => 0.541, 'high' => 1.0] || $d != $want + ['low' => 0.15, 'high' => 0.51]) { $bad[] = "$m at {$s}x, clarity $k"; }
            }
        }
    }
    same([], $bad, 'clarity: controls keep the measured material');
    foreach ([200, 300, 900] as $m) {
        $l = $mat($m, 2, 0.5, false, false); $d = $mat($m, 2, 0.5, false, true);
        check($near($l['blur'], 10) && $near($l['bezel'], 96) && $near($l['rim'], 0.5) && $near($l['low'], 0.35) && $l['high'] == 1 && $near($d['high'], 0.6) && $d['low'] == 0.15, "clarity 0.5 at $m points: " . json_encode([$l, $d]));
    }
    $mid = $mat(150, 1, 0.5, false, false);
    check($near($mid['low'], (0.541 + 0.35) / 2) && $near($mid['rim'], 0.425), 'clarity: half way at 150 points');
    // Along the clarity, at 400 points: blur, light low, dark low, dark high, rim, bezel.
    $tone = function (float $k) use ($mat): array {
        $l = $mat(400, 1, $k, false, false); $d = $mat(400, 1, $k, false, true);
        return [round($l['blur'], 9), round($l['low'], 9), round($d['low'], 9), round($d['high'], 9), round($l['rim'], 9), round($l['bezel'], 9)];
    };
    foreach ([[0, [10, 0.541, 0.15, 0.51, 0.35, 36]], [0.25, [7.5, 0.4455, 0.15, 0.555, 0.425, 42]], [0.5, [5, 0.35, 0.15, 0.6, 0.5, 48]],
        [0.75, [3.5, 0.275, 0.125, 0.725, 0.5, 48]], [1, [2, 0.2, 0.1, 0.85, 0.5, 48]], [-1, [10, 0.541, 0.15, 0.51, 0.35, 36]], [2, [2, 0.2, 0.1, 0.85, 0.5, 48]], [NAN, [10, 0.541, 0.15, 0.51, 0.35, 36]]] as [$k, $want]) {
        same(array_map('floatval', $want), $tone($k), "clarity $k at 400 points");
    }
    // At clarity 0 every pane, of any size, scale and style, is exactly the measured material.
    $bad = [];
    foreach ([1, 20, 72, 100, 150, 199, 200, 260, 900, 4000] as $m) {
        foreach ([1, 1.5, 2] as $s) {
            $base = ['bezel' => min(36, $m / 2) * $s, 'rim' => 0.35];
            $blur = min(max($m * 0.035, 1), 10) * $s;
            foreach ([[true, false, $s, 0.125, 1.082], [true, true, $s, 0.125, 1.082], [false, true, $blur, 0.15, 0.51], [false, false, $blur, 0.541, 1.0]] as [$clear, $dark, $b, $low, $high]) {
                if ($mat($m, $s, 0, $clear, $dark) != ['blur' => $b] + $base + ['low' => $low, 'high' => $high]) { $bad[] = "$m at {$s}x"; }
            }
        }
    }
    same([], $bad, 'clarity 0: the measured material, exactly');
    $f = $mat(400, 1, 0, true, false); $c = $mat(400, 1, 1, true, false);
    check([$f['blur'], $f['low'], $f['high']] === [$c['blur'], $c['low'], $c['high']] && $c['rim'] == 0.5 && $c['bezel'] == 48, 'clarity on clear glass: the rim and the bezel alone');

    // The pane's material goes to the shader in the slot after it.
    $h = new Headless(function (Context $c): void {
        $c->column()->at(0, 0)->size(400, 300)->round(20)->material(new Glass(clarity: 0.5));
        $c->end();
        $c->column()->at(0, 310)->size(100, 40)->round(20)->material(new Glass);
        $c->end();
    }, 420, 360);
    $h->frame();
    $list = $h->paint();
    $slots = [];
    foreach ($list->glass as [$at]) { $k = ($at + 1) * 16; $slots[] = [round($list->buf[$k], 6), round($list->buf[$k + 1], 6), round($list->buf[$k + 2], 6), round($list->buf[$k + 4], 6), round($list->buf[$k + 5], 6), $list->buf[$k + 9]]; }
    same([[48.0, 0.5, 0.35, 0.15, 0.6, -1.0], [20.0, 0.35, 0.541, 0.15, 0.51, -1.0]], $slots, 'clarity: the bezel, rim and tones in the slot after each pane');
})();
