<?php
// Flyout placement and Escapes (mygo's platform flyout_test.go, the
// placement checks of flyout_test.go and ui's TestUnhandledEscape): where a
// flyout goes from its anchor, flipping, sliding and shrinking into the work
// area; the display it goes to; the Escapes nothing took reaching the window.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\{Flyout, FlyoutOptions, Rectangle};
use MyPHP\UI\{Context, Key};

$R = fn(int $x, int $y, int $w, int $h) => new Rectangle($x, $y, $w, $h);
$work = $R(0, 25, 1000, 700); // under a menu bar
$button = $R(100, 100, 80, 20);
foreach ([
    ['below, start', Flyout::BOTTOM, Flyout::START, 4, $button, [200, 150], $R(100, 124, 200, 150), false, false],
    ['below, centered', Flyout::BOTTOM, Flyout::CENTER, 0, $button, [200, 150], $R(40, 120, 200, 150), false, false],
    ['below, end', Flyout::BOTTOM, Flyout::END, 0, $R(400, 100, 80, 20), [200, 150], $R(280, 120, 200, 150), false, false],
    ['flips to the start', Flyout::BOTTOM, Flyout::END, 0, $button, [200, 150], $R(100, 120, 200, 150), true, false],
    ['above', Flyout::TOP, Flyout::START, 4, $R(100, 400, 80, 20), [200, 150], $R(100, 246, 200, 150), false, false],
    ['right', Flyout::RIGHT, Flyout::START, 2, $button, [200, 150], $R(182, 100, 200, 150), false, false],
    ['left, end', Flyout::LEFT, Flyout::END, 0, $R(500, 300, 80, 20), [200, 150], $R(300, 170, 200, 150), false, false],
    // Not enough room below: above instead, the gap on that side.
    ['flips up', Flyout::BOTTOM, Flyout::START, 4, $R(100, 600, 80, 20), [200, 150], $R(100, 446, 200, 150), false, true],
    // Not enough room right of the start: lines up with the end.
    ['flips to the end', Flyout::BOTTOM, Flyout::START, 0, $R(900, 100, 80, 20), [200, 150], $R(780, 120, 200, 150), true, false],
    // Not enough room on either side: stays, slides back in.
    ['slides', Flyout::BOTTOM, Flyout::START, 0, $R(100, 300, 80, 20), [200, 600], $R(100, 125, 200, 600), false, false],
    ['slides from the left', Flyout::LEFT, Flyout::START, 0, $R(50, 300, 80, 20), [200, 100], $R(130, 300, 200, 100), true, false],
    // Larger than the work area: its start shows, the rest is cut.
    ['shrinks', Flyout::BOTTOM, Flyout::START, 0, $button, [1200, 900], $R(0, 25, 1000, 700), false, false],
] as [$name, $side, $align, $gap, $anchor, [$w, $h], $want, $fx, $fy]) {
    [$got, $gx, $gy] = Flyout::place($side, $align, $gap, $anchor, $w, $h, $work);
    check($got == $want && $gx === $fx && $gy === $fy, "$name: at {$got->x}, {$got->y}, {$got->width}×{$got->height}, flipped " . var_export($gx, true) . ' ' . var_export($gy, true));
}

// The display a flyout goes to: the one with most of its anchor, else the nearest.
$displays = [
    ['bounds' => $R(0, 0, 1920, 1080), 'workArea' => $R(0, 25, 1920, 1055), 'primary' => true],
    ['bounds' => $R(1920, -77, 1470, 956), 'workArea' => $R(1920, -77, 1470, 900), 'primary' => false],
];
foreach ([[$R(100, 100, 50, 20), 0], [$R(1900, 100, 50, 20), 1], [$R(1880, 100, 50, 20), 0], [$R(2000, 100, 0, 0), 1], [$R(5000, 100, 10, 10), 1], [$R(-300, 5, 10, 10), 0]] as [$r, $i]) {
    check(Flyout::workAreaFor($r, $displays) === $displays[$i]['workArea'], "the anchor at {$r->x}, {$r->y} goes to display $i");
}
same($displays[0]['workArea'], Flyout::primaryWorkArea($displays), 'the primary work area');
check(Flyout::centered(300, 2000, $work) == $R(350, 25, 300, 700), 'without an anchor, in the middle, as large as it fits');

// mygo's flyout_test placements: a flyout of a window at 100, 100 whose content starts 28 lower.
$content = fn(Rectangle $a, int $px, int $py) => $R($a->x + $px, $a->y + $py, $a->width, $a->height);
$fake = [['bounds' => $R(0, 0, 1440, 900), 'workArea' => $R(0, 25, 1440, 875), 'primary' => true]];
[$side, $align] = Flyout::PLACEMENTS[FlyoutOptions::RIGHT_END];
[$r] = Flyout::place($side, $align, 4, $content($R(10, 20, 80, 24), 100, 100), 150, 200, $fake[0]['workArea']);
check($r == $R(194, 120, 150, 200), "right of the anchor, their tops lined up where their bottoms would cross the work area: {$r->x}, {$r->y}");
[$side, $align] = Flyout::PLACEMENTS[''];
[$r] = Flyout::place($side, $align, 4, $content($R(10, 20, 80, 24), 300, 800), 200, 100, $fake[0]['workArea']);
check($r == $R(310, 716, 200, 100), "above its anchor where the work area ends below it: {$r->x}, {$r->y}");
check(!isset(Flyout::PLACEMENTS['below']), 'no placement "below"');

// An Escape nothing took reaches the window; one a modal took, or with Shift, does not.
$escapes = 0; $open = false;
$t = new Headless(function (Context $c) use (&$open) {
    if ($c->clicked($c->button('Open'))) { $open = true; }
    $c->modal($open, function () use ($c) { $c->text("Dialog"); });
});
$t->rt->escape = function () use (&$escapes) { $escapes++; };
$t->frame();
$t->escape();
same(1, $escapes, 'an Escape nothing handled reached the window');
$t->clickText('Open'); $t->frame();
check($open, 'the dialog opened');
$t->escape();
check(!$open, 'Escape closed the dialog');
$t->key(Key::ESCAPE, 'cancelOperation:', Key::SHIFT, "\e");
same(1, $escapes, 'Escapes for the window after the dialog took one, and Shift+Escape');
