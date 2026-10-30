<?php
// The size of a window that takes its page's content's (mygo's
// TestFitContentDIPs, TestFitContentScrollbars): the content's CSS pixels
// at the page's zoom, a fraction of a pixel taking one, within the limits,
// the scrollbars' room where the content needs them. Windows resizing are
// AppKit's; the bridge's side is src/Native/fit-content.test.ts.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Native\FitContent;

$c = fn(float $w, float $h, float $sw = 0, float $sh = 0): array => ['w' => $w, 'h' => $h, 'sw' => $sw, 'sh' => $sh];
$f = new FitContent(100, 40, 300, 200);
foreach ([
    [120, 80, 1, [120, 80], 'the content\'s size'],
    [120.5, 80.25, 1, [121, 81], 'fractions take a pixel'],
    [120.004, 80, 1, [120, 80], 'but not rounding errors'],
    [60.25, 45, 2, [121, 90], 'CSS pixels of a zoomed page'],
    [150, 100, 0, [150, 100], 'no zoom is 1'],
    [10, 10, 1, [100, 40], 'the minimum'],
    [500, 400, 1, [300, 200], 'the maximum'],
    [0, 0, 1, [100, 40], 'nothing'],
] as [$w, $h, $zoom, $want, $what]) {
    same($want, $f->fit($c($w, $h), $zoom), $what);
}
same([1, FitContent::MAX_POINTS], (new FitContent())->fit($c(0, 1e300), 1), 'an unbounded fit of an empty, huge page');
same([null, null], [$f->fit($c(-1, 10), 1), $f->fit($c(10, -0.5), 1)], 'no size for what no layout gives');

$f = new FitContent(0, 0, 300, 200);
foreach ([
    ['a vertical scrollbar for a moment', $c(120, 150, 17), 1, 0, [120, 150]],
    ['taller than the maximum', $c(120, 500, 17), 1, 0, [137, 200]],
    ['taller than the work area', $c(120, 150, 17), 1, 100, [137, 100]],
    ['overlay scrollbars', $c(120, 500), 1, 0, [120, 200]],
    ['wider than the maximum', $c(300, 60, 0, 17), 1, 0, [300, 77]],
    ['zoomed', $c(150, 60, 0, 8.5), 2, 0, [300, 137]],
    ['no room', $c(120, 500, -1, NAN), 1, 0, [120, 200]],
] as [$what, $content, $zoom, $maxHeight, $want]) {
    same($want, $f->fit($content, $zoom, $maxHeight), $what);
}

same(['minWidth' => 50.0, 'minHeight' => 20.0, 'maxWidth' => 150.0, 'maxHeight' => 100.0], (new FitContent(100, 40, 300, 200))->limits(2.0), 'the limits in the CSS pixels of a zoomed page');
same(['w' => 120.5, 'h' => 80.0, 'sw' => 15.0, 'sh' => 0.0], FitContent::told(['t' => 'content-size', 'w' => 120.5, 'h' => 80, 'sw' => 15]), 'a size the bridge told');
same([null, null], [FitContent::told(['t' => 'content-size', 'w' => 'x', 'h' => 1]), FitContent::told(['t' => 'content-size'])], 'a size that is none');
