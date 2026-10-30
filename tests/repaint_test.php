<?php
// What of mygo's repaint_test.go myphp has (TestRepaintFrames,
// TestRepaintOnlyInView, TestPainterAfter, TestIndicatorsRepaint's asks):
// what painting asks for, nothing for a drawing out of view, a frame when
// after() is due by the frame's clock, the spinner's next spoke and a
// moving progress bar's next frame; animationFrame() asked as the frame is
// built, kept past the frame's end; frames that only paint again while
// only drawings move, and those built anew after anything else.
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
same([false, 1, true], [$h->rt->needsFrame, $h->rt->pace(60.0), $h->rt->repaints()], 'a moving progress bar asks for the next frame, one that only paints again (mygo\'s TestIndicatorsRepaint)');

// animationFrame() as the frame is built (what moves with now() asks for every frame): the next frame comes, as one asked while painting.
$moving = true;
$h = new Headless(function (Context $c) use (&$moving): void {
    $c->column()->size(10, 10); $c->end();
    if ($moving) { $c->animationFrame(); }
}, 100, 100);
$h->frame();
check($h->rt->needsFrame, 'asked as it is built: a frame to come');
$moving = false; $h->frame();
check(!$h->rt->needsFrame, 'asked for no more: none');

// animationFrameRate() (mygo's TestAnimationFrameRate): the frames that only a drawing at a lower rate asks for come every
// so many of the display's refreshes, the most that still give it as many a second; at each while anything asks for more.
$fps = 0.0; $full = false; $draws = 0;
$h = new Headless(function (Context $c) use (&$fps, &$full, &$draws): void {
    $c->column()->size(100, 20)->draw(function (DisplayList $l, Node $n) use (&$fps, &$draws, $c): void { $draws++; if ($fps > 0) { $c->runtime->animationFrameRate($fps); } }); $c->end();
    $c->column()->size(10, 10)->draw(function (DisplayList $l, Node $n) use (&$full, $c): void { if ($full) { $c->runtime->animationFrame(); } }); $c->end();
}, 200, 100);
$h->paints = true;
// How many of $n refreshes of a display of $hz paint the drawing again, each asking for the next, and the last pace.
$paints = function (float $hz, int $n) use ($h, &$draws): array {
    $h->frame(1);
    $start = $draws; $every = $h->rt->pace($hz); $since = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $since += 1 / $hz;
        check($every !== null, "at $hz Hz, refresh $i asked for another");
        if (\MyPHP\UI\Runtime::due($every ?? 1, $since, $hz)) { $h->frame(1); $every = $h->rt->pace($hz); $since = 0.0; }
    }
    return [$draws - $start, $every];
};
foreach ([[120, 60, 2], [120, 50, 2], [120, 30, 4], [120, 100, 1], [120, 120, 1], [60, 60, 1], [60, 30, 2], [59.94, 30, 2]] as [$hz, $f, $every]) {
    $fps = (float)$f;
    same([intdiv(24, $every), $every], $paints((float)$hz, 24), "at $hz Hz asking for $f: painted of 24 refreshes, every");
}
$fps = 30.0; $full = true;
same([24, 1], $paints(120.0, 24), 'with animationFrame() too: each refresh paints');
$full = false; $fps = 0.0; $h->frame(1);
same(null, $h->rt->pace(120.0), 'nothing moving: no frame asked for');
same(1, (function () use ($h) { $h->rt->animationFrameRate(30); $h->rt->needsFrame = true; return $h->rt->pace(120.0); })(), 'an event between the frames due: the next refresh');

// Frames that only paint again (mygo's TestRepaintFrames): a drawing that moves is painted again where the time puts it, without
// building the view; anything else that may change what the view builds builds it anew, and the frame after paints again.
$builds = 0; $draws = 0; $drawnAt = 0; $now = 0; $size = 100.0;
$h = poseTester(function (Context $c) use (&$builds, &$draws, &$drawnAt, &$size): void {
    $builds++;
    $c->column()->size($size, 20)->draw(function (DisplayList $l, Node $n) use (&$draws, &$drawnAt, $c): void { $draws++; $drawnAt = $c->runtime->now; $c->runtime->animationFrame(); }); $c->end();
}, $now, 200, 100);
$h->paint(); $h->rt->pace(60.0);
same([true, 1], [$h->rt->repaints(), $h->rt->pace(60.0)], 'a drawing that moves asks for frames that only paint again');
foreach ([1, 2] as $i) {
    [$b, $d] = [$builds, $draws];
    $now += 8_000_000;
    $repainted = $h->windowFrame();
    same([true, $b, $d + 1, $now, 1], [$repainted, $builds, $draws, $drawnAt, $h->rt->pace(60.0)], "frame $i: painted again at its time, not built, asking for the next");
}
foreach ([
    'an event' => fn() => $h->input->push(['move', 150, 50]),
    'a frame built anew asked for (an event\'s, the app\'s)' => null,
    'the runtime asking for one' => function () use ($h) { $h->rt->needsFrame = true; },
    'another size' => function () use ($h) { $h->w = 210; },
] as $what => $change) {
    if ($change !== null) { $change(); }
    $b = $builds;
    $h->windowFrame($change !== null);
    check($builds === $b + 1, "after $what, the frame built the view");
    $b = $builds;
    check($h->windowFrame() && $builds === $b, "after $what, the next frame only painted again");
}

// A drawing's after() (mygo's TestPainterAfter): a frame that only paints again when due; the view's own after() builds.
$draws = 0; $builds = 0; $now = 0; $viewAfter = false;
$h = poseTester(function (Context $c) use (&$draws, &$builds, &$viewAfter): void {
    $builds++;
    if ($viewAfter) { $c->after(0.01); }
    $c->column()->size(100, 20)->draw(function (DisplayList $l, Node $n) use (&$draws, $c): void { $draws++; $c->runtime->after(0.03); }); $c->end();
}, $now, 200, 100);
$h->paint(); $h->rt->pace(60.0);
same([null, true, true], [$h->rt->pace(60.0), abs($h->rt->nextWake() - 0.03) < 1e-9, $h->rt->wakeRepaints()], 'due in 30 ms, a frame that only paints again');
$now += 30_000_000;
[$b, $d] = [$builds, $draws];
check($h->windowFrame() && $builds === $b && $draws === $d + 1 && abs($h->rt->nextWake() - 0.03) < 1e-9, 'the frame painted it again, not built, the next due in 30 ms');
$viewAfter = true; $h->rt->needsFrame = true; $h->windowFrame(false);
same([true, false], [abs($h->rt->nextWake() - 0.01) < 1e-9, $h->rt->wakeRepaints()], 'the view\'s after() first: its frame builds');

// A drawing out of view asks for nothing (mygo's TestRepaintOnlyInView).
$h = new Headless(function (Context $c): void {
    $c->scroll()->height(100);
      $c->column()->size(100, 300)->shrink(0); $c->end();
      $c->column()->size(100, 20)->shrink(0)->draw(function (DisplayList $l, Node $n) use ($c): void { $c->runtime->animationFrame(); }); $c->end();
    $c->end();
}, 200, 100);
$h->frame(1); $h->paint();
same([null, false], [$h->rt->pace(60.0), $h->rt->repaints()], 'out of view: no frame, none to paint again');

// A drawing in a memo, painted from the memo's quads, still asks for its frames.
$now = 0; $draws = 0;
$h = poseTester(function (Context $c) use (&$draws): void {
    $c->memo('m', [1], function () use ($c, &$draws): void {
        $c->column()->size(100, 20)->draw(function (DisplayList $l, Node $n) use ($c, &$draws): void { $draws++; $c->runtime->after(0.05); }); $c->end();
    });
}, $now, 200, 100);
$h->paint(); $h->rt->pace(60.0);
$now += 50_000_000;
$h->windowFrame();
same([true, true], [$h->rt->wakeRepaints(), $h->rt->nextWake() !== null], 'a memo\'s drawing asks for its next frame again');

// Frames painting again only the drawings that asked (mygo's TestRepaintPartsMatchWhole): what they paint is what painting the window
// whole does, without calling the callbacks of what does not move.
$partsView = function (int &$statics) {
    $static = function (DisplayList $l, Node $n) use (&$statics): void { $statics++; $l->rect($n->x + 2, $n->y + 2, $n->w - 4, $n->h - 4, 0x14a03c, 1.0, 4); };
    return function (Context $c) use ($static): void {
        $moving = function (DisplayList $l, Node $n, $t, $text) use ($c): void {
            $ms = (float)(intdiv($c->runtime->now, 1_000_000) % 40);
            $c->runtime->animationFrame();
            $l->rect($n->x + $ms, $n->y, 6, $n->h, 0x2563eb, 1.0, 3);
            $text->paint($l, 'ab', 10, $n->x + $ms / 2, $n->y + $n->h - 4, 0xdc2828);
        };
        $c->column(4)->background(0xfafafa)->padding(4);
          $c->column()->size(120, 20)->draw($static); $c->end();
          $c->scroll()->height(40)->radius(10);
            $c->column()->size(120, 24)->shrink(0)->draw($moving); $c->end();
            $c->column()->size(120, 24)->shrink(0)->draw($static); $c->end();
          $c->end();
          $c->row();
            $c->column()->size(60, 20)->opacity(0.5)->background(0xdc2828)->draw($moving)->drawOver(fn(DisplayList $l, Node $n) => $static($l, $n)); $c->end();
            $c->column()->size(60, 20)->scale(0.8, 1.2)->draw($static)->drawOver(function (DisplayList $l, Node $n) use ($c): void {
                $c->runtime->after(0.016);
                $l->rect($n->x + (float)(intdiv($c->runtime->now, 1_000_000) % 30), $n->y, 4, 4, 0xdc2828, 1.0, 0);
            }); $c->end();
          $c->end();
          $c->column()->size(120, 20)->draw($static); $c->end();
        $c->end();
    };
};
$statics = 0; $wholeStatics = 0; $nowP = 0; $nowW = 0;
$parts = poseTester($partsView($statics), $nowP, 200, 140);
$whole = poseTester($partsView($wholeStatics), $nowW, 200, 140);
$whole->noParts = true;
$parts->paint(); $parts->rt->pace(60.0); $whole->paint(); $whole->rt->pace(60.0);
$n = $statics; $same = true;
for ($i = 0; $i < 12; $i++) {
    $d = (5 + $i * 3) * 1_000_000; $nowP += $d; $nowW += $d;
    $pr = $parts->windowFrame(); $wr = $whole->windowFrame();
    if (!$pr || !$wr) { check(false, "frame $i built the view"); break; }
    [$a, $b] = [$parts->painted(), $whole->painted()];
    $same = $same && $a->copyOut(0, $a->n) === $b->copyOut(0, $b->n) && $a->transformBytes() === $b->transformBytes();
}
check($same, 'painted in parts, the same quads and maps as painted whole');
same([0, 12, 0], [$statics - $n, $parts->rt->partPaints, $whole->rt->partPaints], 'the drawings that do not move not painted again; 12 frames in parts');

// The window painted whole when something else than a draw callback asks (mygo's TestRepaintPartsWhole): a progress bar, a drawing in a blur's layer.
$statics = 0; $now = 0;
$h = poseTester(function (Context $c) use (&$statics): void {
    $c->column()->size(100, 20)->draw(function () use (&$statics): void { $statics++; }); $c->end();
    $c->column()->size(100, 20)->blur(2);
      $c->column()->size(100, 20)->draw(function () use ($c): void { $c->runtime->animationFrame(); }); $c->end();
    $c->end();
}, $now, 200, 100);
$h->paint(); $h->rt->pace(60.0);
$n = $statics; $now += 16_000_000;
check($h->windowFrame() && $statics === $n + 1 && $h->rt->partPaints === 0, 'a drawing in a layer asking: the window painted whole');
$now = 0;
$h = poseTester(function (Context $c): void { $c->progress(-1.0)->width(100); }, $now, 200, 100);
$h->paint(); $h->rt->pace(60.0); $now += 16_000_000;
check($h->windowFrame() && $h->rt->partPaints === 0, 'an indeterminate progress bar: painted whole');
