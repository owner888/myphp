<?php
// Tooltips (mygo's tooltip_test.go TestTooltipBase, TestTooltipFocus,
// TestTooltipInnermost and widgets_more_test.go TestTooltipHidesOnPress):
// a tip shows once the pointer rests on its node, at once along a toolbar,
// at once for the keyboard focus; a press, a click by the keys or Escape
// hides it until the pointer or the focus comes back; the innermost
// node's shows; a disabled node shows none.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Context, Node};

/** The text of the tip that shows, '' for one of its own, or null. */
function tipText(Headless $h): ?string
{
    return $h->rt->tip[0] ?? null;
}

// TooltipBase and Tooltip: the delay, along a toolbar at once, not long after, Escape until the pointer leaves.
$now = 0; $tip = null; $save = null; $open = null;
$h = poseTester(function (Context $c) use (&$tip, &$save, &$open): void {
    $c->column(40, 20);
      $save = $c->button('Save')->label('save');
      $tip = $c->tooltipBase($save, function (Node $t) use ($c): void { $c->text('Saves the file'); });
      $open = $c->button('Open')->label('open')->tooltip('Opens a file');
    $c->end();
}, $now, 400, 300);
$tick = function (float $s) use ($h, &$now): void { $now += (int)($s * 1e9); $h->frame(); };
[$sx, $sy] = $h->middle($save);
$h->hover($sx, $sy);
$tick(0.5);
check($tip === null, 'no tip before the pointer rested 0.6 seconds');
$tick(0.15); $h->frame();
check($tip !== null && $h->byText('Saves the file') !== null, 'the tip once the pointer rested');
$r = $h->rect($tip); $a = $h->rect($save);
check($r[1] + $r[3] <= $a[1] + 0.5 && abs($r[0] + $r[2] / 2 - ($a[0] + $a[2] / 2)) <= 0.5, 'above the anchor, centered');
$h->hover(...$h->middle($open));
$tick(0.016);
same('Opens a file', tipText($h), 'moving along, the next tip at once');
$h->hover(390, 290);
$tick(1);
$h->hover($sx, $sy);
$tick(0.016); $h->frame();
check($tip === null, 'not at once long after the last tip went');
$tick(1); $h->frame();
check($tip !== null, 'the tip after a rest');
$h->escape();
$tick(1); $h->frame();
check($tip === null, 'Escape hides it');
$h->hover(390, 290);
$h->hover($sx, $sy);
$tick(1); $h->frame();
check($tip !== null, 'the tip as the pointer comes back after Escape');

// The keyboard focus shows a tip at once; the pointer coming later takes it; Escape until the focus leaves; none for a disabled node.
$now = 0; $disabled = false; $b = null;
$h = poseTester(function (Context $c) use (&$disabled, &$b): void {
    $c->column(40, 20);
      $b = $c->button('Save')->tooltip('Saves the file');
      $b->disabled = $disabled;
      $c->button('Open')->tooltip('Opens a file');
    $c->end();
}, $now, 400, 300);
$tick = function (float $s) use ($h, &$now): void { $now += (int)($s * 1e9); $h->frame(); };
$h->hover(390, 290);
$h->tab();
check(tipText($h) === 'Saves the file' && $h->rt->tipByFocus, 'the tip of the focus at once');
$h->tab();
same('Opens a file', tipText($h), 'after Tab, the next');
$tick(1);
$h->hover(...$h->middle($b));
$tick(1);
same('Saves the file', tipText($h), 'the pointer after the focus takes the tip');
$h->hover(390, 290);
$tick(1);
same('Opens a file', tipText($h), 'the tip of the focus comes back');
$h->escape();
same(null, tipText($h), 'Escape hides the tip of the focus');
$h->tab(true); $h->tab();
same('Opens a file', tipText($h), 'the tip as the focus comes back');
$disabled = true; $h->frame();
$h->hover(...$h->middle($b));
$tick(1);
check(tipText($h) !== 'Saves the file', 'a disabled node shows none');

// The innermost node's tip.
$now = 0; $cut = null;
$h = poseTester(function (Context $c) use (&$cut): void {
    $c->row(20)->tooltip('The toolbar');
      $cut = $c->button('Cut')->tooltip('Cut the selection');
    $c->end();
}, $now, 300, 200);
$tick = function (float $s) use ($h, &$now): void { $now += (int)($s * 1e9); $h->frame(); };
[$x, $y] = $h->middle($cut);
$h->hover($x, $y);
$tick(1);
same('Cut the selection', tipText($h), 'over the button');
$r = $h->rect($cut);
$h->hover($r[0] + $r[2] + 10, $y);
$tick(1);
same('The toolbar', tipText($h), 'over the toolbar');

// A press hides the tip, as a click by the keys does, until the pointer comes back.
$now = 0; $save = null;
$h = poseTester(function (Context $c) use (&$save): void {
    $c->row(40, 40);
      $save = $c->button('Save')->tooltip('Save the file');
    $c->end();
}, $now, 400, 200);
$rest = function (float $x, float $y) use ($h, &$now): void { $h->hover($x, $y); $now += 1_000_000_000; $h->frame(); };
[$x, $y] = $h->middle($save);
$rest($x, $y);
same('Save the file', tipText($h), 'the tip over the button');
$h->click($x, $y);
$rest($x + 2, $y);
same(null, tipText($h), 'a click hides it while the pointer stays');
$rest(5, 5);
$rest($x, $y);
same('Save the file', tipText($h), 'the tip as the pointer comes back');
$h->enter();
same(null, tipText($h), 'Enter clicking the button hides it');
