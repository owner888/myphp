<?php
// Timelines (mygo's timeline_test.go): tweens along one playhead, placed
// after, with and at one another, paused, sought, reversed, repeated, and
// at rest at once with less motion, on a clock the test sets.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Context, Easing, KeyframeOptions, Placement, TimelineOptions, Tween};

/** A harness whose frames happen at $now, nanoseconds, that the test moves on; settled, as mygo's timelineTester. */
function timelineTester(\Closure $view, ?int &$now): Headless
{
    $now = 2000 * 1_000_000_000;
    $t = new Headless($view, 400, 300);
    $t->rt->clock = function () use (&$now): int { return $now; };
    for ($i = 0; $i < 10; $i++) {
        $t->frame(1);
        if (!$t->rt->needsFrame) { break; }
    }
    return $t;
}

/** Moves the clock on by $ms and draws the frame that follows (mygo's step). */
function step(Headless $t, int &$now, float $ms): void
{
    $now += (int)round($ms * 1_000_000);
    $t->frame(1);
}

/** That nothing asks for another frame (mygo's resting). */
function resting(Headless $t, string $what): void
{
    check(!$t->rt->needsFrame, "$what: kept asking for frames");
}

$lin = Easing::linear(...);

// Tweens play one after the other.
$x = $y = 0.0; $dur = 0.0;
$t = timelineTester(function (Context $c) use (&$x, &$y, &$dur, $lin) {
    $tl = $c->timeline($c->box(10, 10, 0), 'intro');
    $x = $tl->tween(new Tween(0, 100, 0.4, $lin));
    $y = $tl->tween(new Tween(-50, 50, 0.3, $lin));
    $dur = $tl->duration();
}, $now);
closeFloat(0.7, $dur, 'one after the other, the duration');
closeFloat(0, $x, 'at first, x'); closeFloat(-50, $y, 'at first, y');
step($t, $now, 200); closeFloat(50, $x, '200 ms, x'); closeFloat(-50, $y, '200 ms, y');
step($t, $now, 200); closeFloat(100, $x, '400 ms, x'); closeFloat(-50, $y, 'the second starts where the first ends');
step($t, $now, 150); closeFloat(100, $x, '550 ms, x'); closeFloat(0, $y, '550 ms, y');

// Placed after, overlapping and with the one before.
$after = $overlap = $with = $abs = 0.0;
$t = timelineTester(function (Context $c) use (&$after, &$overlap, &$with, &$abs, $lin) {
    $tl = $c->timeline($c->box(10, 10, 0), 'places');
    $after = $tl->tween(new Tween(0, 100, 0.4, $lin));
    $with = $tl->tween(new Tween(0, 100, 0.2, $lin), Placement::afterPrevious(-0.15)); // 250 ms to 450 ms
    $overlap = $tl->tween(new Tween(0, 100, 0.2, $lin), Placement::afterPrevious(0.1)); // 550 ms to 750 ms
    $abs = $tl->tween(new Tween(0, 100, 0.4, $lin), Placement::withPrevious(0)); // 550 ms to 950 ms
}, $now);
foreach ([[$after, 0], [$overlap, 0], [$with, 0], [$abs, 0]] as $i => [$v, $w]) { closeFloat($w, $v, "placed, at first $i"); }
step($t, $now, 250); closeFloat(62.5, $after, '250 ms, after'); closeFloat(0, $overlap, '250 ms, overlap'); closeFloat(0, $with, '250 ms, with'); closeFloat(0, $abs, '250 ms, abs');
step($t, $now, 100); closeFloat(0, $overlap, '350 ms, overlap'); closeFloat(50, $with, '350 ms, with'); closeFloat(0, $abs, '350 ms, abs');
step($t, $now, 100); closeFloat(0, $overlap, '450 ms, overlap'); closeFloat(100, $with, '450 ms, with'); closeFloat(0, $abs, '450 ms, abs');
step($t, $now, 100); closeFloat(0, $overlap, '550 ms, overlap'); closeFloat(0, $abs, '550 ms, abs');
step($t, $now, 100); closeFloat(50, $overlap, '650 ms, overlap'); closeFloat(25, $abs, '650 ms, abs');
step($t, $now, 100); closeFloat(100, $overlap, '750 ms, overlap'); closeFloat(50, $abs, '750 ms, abs');

// Placed at a time.
$delayed = 0.0;
$t = timelineTester(function (Context $c) use (&$abs, &$delayed, &$dur, $lin) {
    $tl = $c->timeline($c->box(10, 10, 0), 'abs');
    $abs = $tl->tween(new Tween(0, 100, 0.2, $lin), Placement::atTime(0.2));
    $delayed = $tl->tween(new Tween(10, 20, 0.2, $lin), Placement::atTime(0.5));
    $dur = $tl->duration();
}, $now);
closeFloat(0.7, $dur, 'placed at times, the duration');
closeFloat(0, $abs, 'at first, abs'); closeFloat(10, $delayed, 'at first, delayed');
step($t, $now, 100); closeFloat(0, $abs, 'it has not started yet');
step($t, $now, 200); closeFloat(50, $abs, '300 ms, abs'); closeFloat(10, $delayed, '300 ms, delayed');
step($t, $now, 200); closeFloat(100, $abs, '500 ms, abs'); closeFloat(10, $delayed, 'the delay is over at 500 ms');
step($t, $now, 100); closeFloat(15, $delayed, '600 ms, delayed');

// Labels.
$a = $b = $cv = 0.0;
$t = timelineTester(function (Context $c) use (&$a, &$b, &$cv, &$dur, $lin) {
    $tl = $c->timeline($c->box(10, 10, 0), 'labels');
    $a = $tl->tween(new Tween(0, 100, 0.4, $lin));
    $tl->label('intro'); // where a ends, at 400 ms
    $b = $tl->tween(new Tween(0, 100, 0.2, $lin), Placement::atLabel('intro', 0));
    $tl->label('far', Placement::atTime(0.8));
    $cv = $tl->tween(new Tween(0, 100, 0.2, $lin), Placement::atLabel('far', 0.1));
    $dur = $tl->duration();
}, $now);
closeFloat(1.1, $dur, 'with labels, the duration');
closeFloat(0, $b, 'at first, b'); closeFloat(0, $cv, 'at first, c');
step($t, $now, 400); closeFloat(100, $a, '400 ms, a'); closeFloat(0, $b, '400 ms, b'); closeFloat(0, $cv, '400 ms, c');
step($t, $now, 200); closeFloat(100, $b, '600 ms, b'); closeFloat(0, $cv, '600 ms, c');
step($t, $now, 400); closeFloat(50, $cv, '1 s, c');

// Eases.
$linear = $squared = $plain = 0.0;
$t = timelineTester(function (Context $c) use (&$linear, &$squared, &$plain, $lin) {
    $tl = $c->timeline($c->box(10, 10, 0), 'ease');
    $linear = $tl->tween(new Tween(0, 100, 0.4, $lin));
    $squared = $tl->tween(new Tween(0, 100, 0.4, fn(float $t): float => $t * $t), Placement::atTime(0));
    $plain = $tl->tween(new Tween(0, 100, 0.4), Placement::atTime(0));
}, $now);
step($t, $now, 200);
closeFloat(50, $linear, 'linear'); closeFloat(25, $squared, 'the ease, not the time, shapes it'); closeFloat(100 * Easing::easeOut(0.5), $plain, 'no ease eases out');

// Placed relative to the one before.
$first = 0.0;
$t = timelineTester(function (Context $c) use (&$first, &$overlap, &$with, &$dur, $lin) {
    $tl = $c->timeline($c->box(10, 10, 0), 'relative');
    $first = $tl->tween(new Tween(0, 100, 0.4, $lin), Placement::atTime(0.1));
    $overlap = $tl->tween(new Tween(0, 100, 0.2, $lin), Placement::afterPrevious(-0.2)); // 300 ms to 500 ms
    $with = $tl->tween(new Tween(0, 100, 0.2, $lin), Placement::withPrevious(0.1)); // 400 ms to 600 ms
    $dur = $tl->duration();
}, $now);
closeFloat(0.6, $dur, 'relative, the duration');
step($t, $now, 400); closeFloat(75, $first, '400 ms, first'); closeFloat(50, $overlap, '400 ms, overlap'); closeFloat(0, $with, '400 ms, with');
step($t, $now, 100); closeFloat(100, $overlap, '500 ms, overlap'); closeFloat(50, $with, '500 ms, with');

// Time and progress.
$tm = $p = 0.0; $done = false;
$t = timelineTester(function (Context $c) use (&$tm, &$p, &$done, $lin) {
    $tl = $c->timeline($c->box(10, 10, 0), 'progress');
    $tl->tween(new Tween(0, 100, 0.4, $lin));
    $tm = $tl->time(); $p = $tl->progress(); $done = $tl->settled();
}, $now);
check($tm == 0 && $p == 0 && !$done, "a new timeline is at $tm, $p");
step($t, $now, 100); closeFloat(0.1, $tm, '100 ms, the time'); closeFloat(0.25, $p, '100 ms, the progress');
step($t, $now, 500); closeFloat(0.4, $tm, 'past the end, the time'); closeFloat(1, $p, 'past the end, the progress');
check($done, 'a timeline past its end is done');
resting($t, 'past its end');

// Paused and resumed.
$paused = false;
$t = timelineTester(function (Context $c) use (&$x, &$paused, $lin) {
    $x = $c->timeline($c->box(10, 10, 0), 'pause', new TimelineOptions(paused: $paused))->tween(new Tween(0, 100, 0.4, $lin));
}, $now);
step($t, $now, 100); closeFloat(25, $x, 'before the pause');
$paused = true; $t->frame(1); closeFloat(25, $x, 'paused');
resting($t, 'paused');
step($t, $now, 100); closeFloat(25, $x, 'paused, time passes and nothing moves');
$paused = false; $t->frame(1); closeFloat(25, $x, 'the frame that lets the pause go plays no time');
step($t, $now, 100); closeFloat(50, $x, 'it goes on where it was held');

// Seek and progress hold the playhead.
$seek = null; $progress = null;
$t = timelineTester(function (Context $c) use (&$x, &$seek, &$progress, $lin) {
    $x = $c->timeline($c->box(10, 10, 0), 'seek', new TimelineOptions(seek: $seek, progress: $progress))->tween(new Tween(0, 100, 0.4, $lin));
}, $now);
step($t, $now, 100); closeFloat(25, $x, 'before seeking');
$seek = 0.3; $t->frame(1); closeFloat(75, $x, 'sought');
resting($t, 'sought');
step($t, $now, 200); closeFloat(75, $x, 'sought, time passes and it stays');
$seek = null;
step($t, $now, 50); closeFloat(75, $x, 'the frame that lets a seek go plays no time');
step($t, $now, 50); closeFloat(87.5, $x, 'it goes on from where it was sought');
$progress = 0.25; $t->frame(1); closeFloat(25, $x, 'held at a part of the duration');
resting($t, 'held at a part');
$progress = null; $t->frame(1);
step($t, $now, 100); closeFloat(50, $x, 'it goes on from that part');

// Reversed.
$reverse = false;
$t = timelineTester(function (Context $c) use (&$x, &$done, &$reverse, $lin) {
    $tl = $c->timeline($c->box(10, 10, 0), 'reverse', new TimelineOptions(reverse: $reverse));
    $x = $tl->tween(new Tween(0, 100, 0.4, $lin)); $done = $tl->settled();
}, $now);
step($t, $now, 100); closeFloat(25, $x, 'before reversing');
$reverse = true; $t->frame(1); closeFloat(25, $x, 'it turns where the playhead is');
step($t, $now, 50); closeFloat(12.5, $x, 'playing back');
step($t, $now, 100); closeFloat(0, $x, 'it comes to rest at its start');
check($done, 'a timeline played back to its start is done');
resting($t, 'played back');
$reverse = false; $t->frame(1);
step($t, $now, 100); closeFloat(25, $x, 'and plays forward again');

// Reversed from its end, after frames something else asked for.
$t = timelineTester(function (Context $c) use (&$x, &$reverse, $lin) {
    $x = $c->timeline($c->box(10, 10, 0), 'close', new TimelineOptions(reverse: $reverse))->tween(new Tween(0, 100, 0.4, $lin));
}, $now);
step($t, $now, 400); closeFloat(100, $x, 'at its end');
step($t, $now, 1000);
$reverse = true; $t->frame(1); closeFloat(100, $x, 'turned at its end');
check($t->rt->needsFrame, 'a timeline playing back asks for frames');
step($t, $now, 100); closeFloat(75, $x, 'played back from its end');

// Reversed from the first frame, it rests at its start; a run while reversed plays from the end.
$reverse = true; $run = 0;
$t = timelineTester(function (Context $c) use (&$x, &$reverse, &$run, $lin) {
    $x = $c->timeline($c->box(10, 10, 0), 'open', new TimelineOptions(run: $run, reverse: $reverse))->tween(new Tween(0, 100, 0.4, $lin));
}, $now);
closeFloat(0, $x, 'reversed at first'); resting($t, 'reversed at first');
$reverse = false; $t->frame(1);
step($t, $now, 200); closeFloat(50, $x, 'played forward');
$reverse = true; $run = 1; $t->frame(1); closeFloat(100, $x, 'run reversed, from its end');
step($t, $now, 100); closeFloat(75, $x, 'run reversed, played back');

// Repeated and alternated.
$t = timelineTester(function (Context $c) use (&$x, $lin) {
    $x = $c->timeline($c->box(10, 10, 0), 'repeat', new TimelineOptions(repeat: 1))->tween(new Tween(0, 100, 0.4, $lin));
}, $now);
step($t, $now, 200); closeFloat(50, $x, 'repeated, halfway');
step($t, $now, 200); closeFloat(0, $x, 'it plays again from the start');
step($t, $now, 200); closeFloat(50, $x, 'halfway again');
step($t, $now, 200); closeFloat(100, $x, 'two plays, at the end'); resting($t, 'repeated');
$t = timelineTester(function (Context $c) use (&$y, &$tm, $lin) {
    $tl = $c->timeline($c->box(10, 10, 0), 'alternate', new TimelineOptions(repeat: 1, alternate: true));
    $y = $tl->tween(new Tween(0, 100, 0.4, $lin)); $tm = $tl->time();
}, $now);
step($t, $now, 200); closeFloat(50, $y, 'alternated, halfway');
step($t, $now, 200); closeFloat(100, $y, 'the second play goes backward');
step($t, $now, 200); closeFloat(50, $y, 'halfway back');
step($t, $now, 200); closeFloat(0, $y, 'two plays, where it began');
check($tm == 0, "the time at the end of an alternated timeline: $tm"); resting($t, 'alternated');

// Repeated for ever.
$t = timelineTester(function (Context $c) use (&$x, &$done, $lin) {
    $tl = $c->timeline($c->box(10, 10, 0), 'forever', new TimelineOptions(repeat: KeyframeOptions::REPEAT_FOREVER));
    $x = $tl->tween(new Tween(0, 100, 0.4, $lin)); $done = $tl->settled();
}, $now);
step($t, $now, 100); closeFloat(25, $x, 'for ever, at first');
for ($i = 0; $i < 20; $i++) { step($t, $now, 200); }
check(!$done && $t->rt->needsFrame, 'a timeline that repeats for ever goes on');

// Staggered from a label.
$v = [0.0, 0.0, 0.0];
$t = timelineTester(function (Context $c) use (&$v, &$dur, $lin) {
    $tl = $c->timeline($c->box(10, 10, 0), 'stagger');
    $tl->label('rows', Placement::atTime(0));
    foreach ($v as $i => $_) { $v[$i] = $tl->tween(new Tween(0, 1, 0.1, $lin), Placement::atLabel('rows', $i * 0.05)); }
    $dur = $tl->duration();
}, $now);
closeFloat(0.2, $dur, 'staggered, the duration');
step($t, $now, 75); closeFloat(0.75, $v[0], '75 ms, 0'); closeFloat(0.25, $v[1], '75 ms, 1'); closeFloat(0, $v[2], '75 ms, 2');
step($t, $now, 50); closeFloat(1, $v[0], '125 ms, 0'); closeFloat(0.75, $v[1], '125 ms, 1'); closeFloat(0.25, $v[2], '125 ms, 2');
step($t, $now, 100); closeFloat(1, $v[2], 'past the end, 2'); resting($t, 'staggered');

// Settled and arrived.
$arrived = false;
$t = timelineTester(function (Context $c) use (&$x, &$done, &$arrived, $lin) {
    $tl = $c->timeline($c->box(10, 10, 0), 'done');
    $x = $tl->tween(new Tween(0, 1, 0.1, $lin)); $done = $tl->settled(); $arrived = $tl->arrived();
}, $now);
check(!$done && !$arrived, 'a new timeline is not done');
step($t, $now, 50); check(!$done && !$arrived, 'halfway, not done');
step($t, $now, 100); closeFloat(1, $x, 'at rest'); check($done && $arrived, 'the frame it came to rest in');
resting($t, 'at rest');
step($t, $now, 100); check($done && !$arrived, 'arrived in that frame only');

// Run plays it over.
$run = 0;
$t = timelineTester(function (Context $c) use (&$x, &$run, $lin) {
    $x = $c->timeline($c->box(10, 10, 0), 'run', new TimelineOptions(run: $run))->tween(new Tween(0, 100, 0.4, $lin));
}, $now);
step($t, $now, 300); closeFloat(75, $x, 'before the run');
$run = 1; $t->frame(1); closeFloat(0, $x, 'it plays again from the start');
step($t, $now, 200); closeFloat(50, $x, 'run, halfway');
step($t, $now, 100); closeFloat(75, $x, 'the same run does not restart it');

// A changed definition.
$two = false;
$t = timelineTester(function (Context $c) use (&$x, &$y, &$two, $lin) {
    $tl = $c->timeline($c->box(10, 10, 0), 'definition');
    $x = $tl->tween(new Tween(0, 100, 0.4, $lin));
    $y = $two ? $tl->tween(new Tween(0, 10, 0.2, $lin)) : 0.0;
}, $now);
step($t, $now, 300); closeFloat(75, $x, 'one tween');
$two = true; $t->frame(1); closeFloat(75, $x, 'a tween added, x'); closeFloat(0, $y, 'a tween added, y');
step($t, $now, 150); closeFloat(100, $x, 'then x'); closeFloat(2.5, $y, 'then y');

// Less motion.
$t = timelineTester(function (Context $c) use (&$x, &$done, $lin) {
    $tl = $c->timeline($c->box(10, 10, 0), 'less');
    $x = $tl->tween(new Tween(0, 100, 0.4, $lin)); $done = $tl->settled();
}, $now);
closeFloat(0, $x, 'before less motion');
$t->rt->reduceMotion = true; $t->frame(1);
closeFloat(100, $x, 'with less motion, where it would end, at once');
check($done, 'with less motion, done'); resting($t, 'less motion');
step($t, $now, 1000); closeFloat(100, $x, 'with less motion, it stays at its end'); resting($t, 'less motion, later');

// Less motion keeps direct manipulation.
$o = new TimelineOptions();
$t = timelineTester(function (Context $c) use (&$x, &$o, $lin) {
    $x = $c->timeline($c->box(10, 10, 0), 'less-held', $o)->tween(new Tween(0, 100, 0.4, $lin));
}, $now);
$t->rt->reduceMotion = true; $t->frame(1); closeFloat(100, $x, 'less motion, at rest');
$o = new TimelineOptions(seek: 0.1); step($t, $now, 16); closeFloat(25, $x, 'the scrubber moves it, even from where it rested');
$o = new TimelineOptions(progress: 0.5); step($t, $now, 16); closeFloat(50, $x, 'by part');
$o = new TimelineOptions(paused: true); step($t, $now, 1000); closeFloat(50, $x, 'paused, it holds where the scrubber left it'); resting($t, 'less motion, paused');
$o = new TimelineOptions(); step($t, $now, 16); closeFloat(100, $x, 'let go, at once where it comes to rest'); resting($t, 'less motion, let go');

// Less motion restarts at rest.
$run = 0;
$t = timelineTester(function (Context $c) use (&$x, &$run, $lin) {
    $x = $c->timeline($c->box(10, 10, 0), 'less-run', new TimelineOptions(run: $run))->tween(new Tween(0, 100, 0.4, $lin));
}, $now);
$t->rt->reduceMotion = true; $t->frame(1); closeFloat(100, $x, 'less motion, run 0');
$run = 1; $t->frame(1); closeFloat(100, $x, 'less motion, run 1'); resting($t, 'less motion, run');

// Without tweens it stops.
$t = timelineTester(function (Context $c) use (&$done) { $done = $c->timeline($c->box(10, 10, 0), 'empty')->settled(); }, $now);
step($t, $now, 1); check($done, 'a timeline with no tweens comes to rest'); resting($t, 'no tweens');

// It goes with the view.
$build = true; $id = 0;
$t = timelineTester(function (Context $c) use (&$x, &$build, &$id, $lin) {
    if (!$build) { return; }
    $e = $c->box(10, 10, 0); $id = $e->id;
    $x = $c->timeline($e, 'gone')->tween(new Tween(0, 100, 0.4, $lin));
}, $now);
step($t, $now, 200); closeFloat(50, $x, 'built');
$build = false; $t->frame(1);
check(!isset($t->rt->timelines[$id]), 'a timeline the view stopped building stayed'); resting($t, 'gone');

// Two nodes keep timelines of their own under one key.
$v = [0.0, 0.0]; $runs = [0, 0];
$t = timelineTester(function (Context $c) use (&$v, &$runs, $lin) {
    foreach ($v as $i => $_) { $v[$i] = $c->timeline($c->box(10, 10, 0), 'card', new TimelineOptions(run: $runs[$i]))->tween(new Tween(0, 100, 0.4, $lin)); }
}, $now);
step($t, $now, 200); $runs[0] = 1; $t->frame(1);
step($t, $now, 100); closeFloat(25, $v[0], 'the card played over'); closeFloat(75, $v[1], 'the other card');

// A seek let go after a long while goes on from where it held.
$seek = null;
$t = timelineTester(function (Context $c) use (&$x, &$seek, $lin) {
    $x = $c->timeline($c->box(10, 10, 0), 'held', new TimelineOptions(seek: $seek))->tween(new Tween(0, 100, 0.4, $lin));
}, $now);
$seek = 0.1; $t->frame(1); closeFloat(25, $x, 'held'); resting($t, 'held');
$now += 5_000_000_000; $seek = null; $t->frame(1); closeFloat(25, $x, 'let go after a while');
step($t, $now, 100); closeFloat(50, $x, 'goes on from where it held');

// With less motion it rests where it would.
foreach ([['forward', new TimelineOptions(), 100], ['reversed', new TimelineOptions(reverse: true), 0], ['alternating twice', new TimelineOptions(repeat: 1, alternate: true), 0], ['alternating three times', new TimelineOptions(repeat: 2, alternate: true), 100]] as [$name, $lo, $want]) {
    $t = timelineTester(function (Context $c) use (&$x, &$p, $lo, $lin) {
        $tl = $c->timeline($c->box(10, 10, 0), 'less', $lo);
        $x = $tl->tween(new Tween(0, 100, 0.4, $lin)); $p = $tl->progress();
    }, $now);
    $t->rt->reduceMotion = true; $t->frame(1);
    step($t, $now, 1);
    closeFloat($want, $x, "less motion, $name"); closeFloat($want / 100, $p, "less motion, $name, the progress"); resting($t, "less motion, $name");
}

// Frames at rest are not time played: a longer timeline goes on from the end.
$span = 0.4;
$t = timelineTester(function (Context $c) use (&$x, &$span, $lin) {
    $x = $c->timeline($c->box(10, 10, 0), 'rest')->tween(new Tween(0, 100, $span, $lin));
}, $now);
step($t, $now, 400); step($t, $now, 1000);
$span = 0.8; $t->frame(1); closeFloat(50, $x, 'longer, from the end it rested at');
step($t, $now, 200); closeFloat(75, $x, 'longer, playing on');

// Without a runtime a timeline shows each tween's from.
$tl = (new Context(new Metrics))->timeline(new \MyPHP\UI\Node(\MyPHP\UI\Node::BOX, 0, 0, 0, 1, 1, 0, null, 0), 'nowhere');
same(3.0, $tl->tween(new Tween(3, 9, 1, $lin)), 'a timeline without a runtime shows its from');
$tl->label('nowhere');
check($tl->duration() == 0 && $tl->time() == 0 && $tl->progress() == 0 && !$tl->settled() && !$tl->arrived(), 'a timeline without a runtime answers nothing');

// What a timeline rejects.
foreach ([
    'a negative duration' => fn(Context $c) => $c->timeline($c->box(1, 1, 0), 't')->tween(new Tween(0, 1, -0.001, $lin)),
    'seek and progress' => fn(Context $c) => $c->timeline($c->box(1, 1, 0), 't', new TimelineOptions(seek: 0, progress: 0)),
    'one key twice in a build' => function (Context $c) { $b = $c->box(1, 1, 0); $c->timeline($b, 't'); $c->timeline($b, 't'); },
    'a value that is not finite' => fn(Context $c) => $c->timeline($c->box(1, 1, 0), 't')->tween(new Tween(0, INF, 1)),
    'a label that was not marked' => fn(Context $c) => $c->timeline($c->box(1, 1, 0), 't')->tween(new Tween(0, 1, 1, $lin), Placement::atLabel('nowhere', 0)),
    'a repeat below REPEAT_FOREVER' => fn(Context $c) => $c->timeline($c->box(1, 1, 0), 't', new TimelineOptions(repeat: -2)),
    'a negative timeline delay' => fn(Context $c) => $c->timeline($c->box(1, 1, 0), 't', new TimelineOptions(delay: -0.001)),
] as $name => $view) {
    $threw = false;
    try { (new Headless($view))->frame(1); } catch (\InvalidArgumentException | \LogicException) { $threw = true; }
    check($threw, "$name accepted");
}

// A tween that takes no time is at its to at once, and the timeline rests there.
$t = timelineTester(function (Context $c) use (&$x, &$done, $lin) {
    $tl = $c->timeline($c->box(10, 10, 0), 'instant');
    $x = $tl->tween(new Tween(0, 100, 0, $lin)); $done = $tl->settled();
}, $now);
step($t, $now, 1); closeFloat(100, $x, 'an instant tween'); check($done, 'one instant tween, done'); resting($t, 'instant');

// An instant tween at the end is where the playhead comes to rest.
$last = 0.0;
$t = timelineTester(function (Context $c) use (&$x, &$last, &$done, $lin) {
    $tl = $c->timeline($c->box(10, 10, 0), 'instant-end');
    $x = $tl->tween(new Tween(0, 100, 0.4, $lin)); $last = $tl->tween(new Tween(0, 1, 0, $lin)); $done = $tl->settled();
}, $now);
closeFloat(0, $last, 'its time has not come yet');
step($t, $now, 400); closeFloat(100, $x, 'at the end, x'); closeFloat(1, $last, 'at the end, the instant one');
check($done, 'ending in an instant tween, done'); resting($t, 'instant end');

// Paused time is not played time, however long.
$paused = false;
$t = timelineTester(function (Context $c) use (&$x, &$paused, $lin) {
    $x = $c->timeline($c->box(10, 10, 0), 'pause-idle', new TimelineOptions(paused: $paused))->tween(new Tween(0, 100, 1, $lin));
}, $now);
step($t, $now, 250); closeFloat(25, $x, 'before a long pause');
$paused = true; $t->frame(1); closeFloat(25, $x, 'paused long'); resting($t, 'paused long');
$now += 5_000_000_000; $paused = false; $t->frame(1); closeFloat(25, $x, 'let go after five seconds');
step($t, $now, 100); closeFloat(35, $x, 'plays on');

// A part or a time asked for in the first frame lands where asked.
$t = timelineTester(function (Context $c) use (&$x, $lin) {
    $x = $c->timeline($c->box(10, 10, 0), 'part-at-once', new TimelineOptions(progress: 0.25))->tween(new Tween(0, 100, 0.4, $lin));
}, $now);
closeFloat(25, $x, 'a part at once'); resting($t, 'a part at once');
$t = timelineTester(function (Context $c) use (&$x, $lin) {
    $x = $c->timeline($c->box(10, 10, 0), 'seek-at-once', new TimelineOptions(seek: 0.1))->tween(new Tween(0, 100, 0.4, $lin));
}, $now);
closeFloat(25, $x, 'a time at once'); resting($t, 'a time at once');

// A definition that goes on plays on.
$span = 0.4;
$t = timelineTester(function (Context $c) use (&$x, &$done, &$span, $lin) {
    $tl = $c->timeline($c->box(10, 10, 0), 'grow');
    $x = $tl->tween(new Tween(0, 100, $span, $lin)); $done = $tl->settled();
}, $now);
step($t, $now, 400); closeFloat(100, $x, 'grown, at the end'); resting($t, 'grown');
$span = 0.8; $t->frame(1);
check(!$done, 'a timeline that goes on is not done'); closeFloat(50, $x, 'grown, from where the playhead is');
check($t->rt->needsFrame, 'a timeline that goes on asks for frames');
step($t, $now, 200); closeFloat(75, $x, 'grown, playing on');

// It arrives again after a seek back.
$seek = null;
$t = timelineTester(function (Context $c) use (&$x, &$arrived, &$seek, $lin) {
    $tl = $c->timeline($c->box(10, 10, 0), 'arrive-again', new TimelineOptions(seek: $seek));
    $x = $tl->tween(new Tween(0, 100, 0.4, $lin)); $arrived = $tl->arrived();
}, $now);
step($t, $now, 400); closeFloat(100, $x, 'arrived'); check($arrived, 'the timeline arrived');
$seek = 0.2; $t->frame(1); check(!$arrived, 'not arrived while sought back');
$seek = null; $t->frame(1);
step($t, $now, 200); closeFloat(100, $x, 'at rest again'); check($arrived, 'arrived again');

// Options apply in the frame they change in: a button that plays it over.
$run = 0;
$t = timelineTester(function (Context $c) use (&$x, &$run, $lin) {
    $x = $c->timeline($c->box(10, 10, 0), 'restart', new TimelineOptions(run: $run))->tween(new Tween(0, 100, 1, $lin));
    if ($c->clicked($c->button('Restart'))) { $run++; }
}, $now);
step($t, $now, 1000); closeFloat(100, $x, 'played'); resting($t, 'played');
$t->clickText('Restart');
closeFloat(0, $x, 'clicked, it plays over in that frame');
step($t, $now, 200); closeFloat(20, $x, 'clicked, playing');
