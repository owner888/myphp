<?php
// What of mygo's repaint_test.go myphp has (TestRepaintOnlyInView,
// TestPainterAfter's times, TestIndicatorsRepaint's asks): every frame of
// myphp builds the view (docs/mygo-deviations.md, paint-only frames), so
// what is checked is what painting asks for: nothing for a drawing out of
// view, a frame when after() is due by the frame's clock, the spinner's
// next spoke and a moving progress bar's next frame.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Context, DisplayList, Node};

// A drawing out of view is not painted, and asks for no frame.
$draws = 0;
$h = new Headless(function (Context $c) use (&$draws): void {
    $c->scroll()->height(100);
      $c->column()->size(100, 300)->shrink(0); $c->end();
      $c->column()->size(100, 20)->shrink(0)->draw(function (DisplayList $l, Node $n) use (&$draws, $c): void { $draws++; $c->runtime->animationFrame(); }); $c->end();
    $c->end();
}, 200, 100);
$h->paints = true; $h->frame();
check($draws === 0 && !$h->rt->needsFrame && $h->rt->nextWake() === null, "out of view: painted $draws times, a frame asked for: " . json_encode($h->rt->needsFrame));

// after() asks for a frame when it is due, by the frame's clock; the frame painted asks again.
$draws = 0; $now = 0;
$h = poseTester(function (Context $c) use (&$draws): void {
    $c->column()->size(100, 20)->draw(function (DisplayList $l, Node $n) use (&$draws, $c): void { $draws++; $c->runtime->after(0.03); }); $c->end();
}, $now, 200, 100);
$h->paint();
check($draws === 1 && !$h->rt->needsFrame && abs($h->rt->nextWake() - 0.03) < 1e-9, 'due in 30 ms: ' . json_encode($h->rt->nextWake()));
$now += 20_000_000;
check(abs($h->rt->nextWake() - 0.01) < 1e-9, '20 ms later, due in 10 ms');
$now += 10_000_000;
same(0.0, $h->rt->nextWake(), 'due now');
$h->frame(1); $h->paint();
check($draws === 2 && abs($h->rt->nextWake() - 0.03) < 1e-9, 'the frame painted it, the next due in 30 ms');

// A spinner asks for its next spoke without frames meanwhile; a progress bar of unknown length for the next frame.
$value = 0.0; $now = 0;
$h = poseTester(function (Context $c) use (&$value): void {
    $c->column(10, 10);
      $c->spinner();
      $c->progress($value)->width(100);
    $c->end();
}, $now, 200, 100);
$h->paint();
$wake = $h->rt->nextWake();
check(!$h->rt->needsFrame && $wake !== null && $wake <= 0.075, 'the spinner alone: its next spoke in ' . json_encode($wake));
$value = -1.0; $h->frame(1); $h->paint();
check($h->rt->needsFrame, 'a moving progress bar asks for the next frame');
