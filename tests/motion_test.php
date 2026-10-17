<?php
// Values that move (mygo's motion_test.go and motion_api_test.go): curves,
// springs, delays, jumps, colors and keyframes, on a clock the test sets.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Animation, Context, Easing, Keyframe, KeyframeOptions, KeyframeSequence, Motion, MotionChannel, Node, ScrollState, SpringOptions};

/** A harness whose frames happen at $now, in nanoseconds, which the test moves on (mygo's motionTester). */
function motionTester(\Closure $view, ?int &$now): Headless
{
    $now = 1000 * 1_000_000_000;
    $t = new Headless($view, 300, 150);
    $t->rt->clock = function () use (&$now): int { return $now; };
    $t->frame(1);
    return $t;
}

const MS = 1_000_000;

/** Fails unless $got is within 1e-3 of $want (mygo's closeFloat). */
function closeFloat(float $want, float $got, string $what): void
{
    check(abs($got - $want) <= 1e-3 * max(1.0, abs($want)), "$what: expected $want, got $got");
}

/** Whether $fn throws an InvalidArgumentException. */
function rejects(\Closure $fn): bool
{
    try { $fn(); } catch (\InvalidArgumentException) { return true; }
    return false;
}

// A spring keeps its velocity when its target changes between frames.
$target = 0.0; $value = 0.0; $velocity = 0.0; $e = null;
$t = motionTester(function (Context $c) use (&$target, &$value, &$velocity, &$e) {
    $e = $c->box(10, 10, 0); $value = $c->spring($e, 'x', $target); $velocity = $c->velocity($e, 'x');
}, $now);
$target = 100.0;
$t->frame(1);
$now += 120 * MS;
$t->frame(1);
check($value > 0 && $velocity > 0, "the spring advances: $value at $velocity/s");
// Retargeted 30 ms after the last frame, the old trajectory is sampled at the interruption before its new target is adopted.
$expected = clone $t->rt->motions[$e->id]['x'];
$now += 30 * MS;
$expected->advance($now);
$target = -50.0;
$t->frame(1);
closeFloat($expected->value, $value, 'retargeted, the value');
closeFloat($expected->velocity, $velocity, 'retargeted, the velocity');
$now += 5 * MS;
$t->frame(1);
check($value >= $expected->value, 'a retarget keeps the forward momentum');
$now += 5000 * MS;
$t->frame(1);
closeFloat(-50, $value, 'at rest, the value');
closeFloat(0, $velocity, 'at rest, the velocity');
check(!$t->rt->needsFrame, 'a spring at rest asks for no frames');

// The trajectory of a spring is the same whatever the frame rate, damped under, critically and over.
foreach ([5, 2 * sqrt(170), 60] as $damping) {
    [$m, $k, $c] = (new SpringOptions(damping: $damping))->physics();
    [$ax, $av] = Motion::solveSpring(-100, 0, $m, $k, $c, 1);
    $bx = -100.0; $bv = 0.0;
    for ($i = 0; $i < 120; $i++) { [$bx, $bv] = Motion::solveSpring($bx, $bv, $m, $k, $c, 1 / 120); }
    check(abs($ax - $bx) <= 1e-6 && abs($av - $bv) <= 1e-6, "damped $damping, the frame rate changes nothing: $ax, $av / $bx, $bv");
}

// Keyframes: their timing, a restart from where the sequence is, and alternating.
$run = 0;
$frames = [new Keyframe(0, 0), new Keyframe(0.1, 10), new Keyframe(0.3, 30, Easing::easeIn(...))];
$t = motionTester(function (Context $c) use (&$value, &$run, $frames) { $value = $c->keyframes($c->box(10, 10, 0), 'sequence', $frames, new KeyframeOptions(run: $run)); }, $now);
$now += 100 * MS; $t->frame(1);
closeFloat(10, $value, 'at the second frame');
$now += 100 * MS; $t->frame(1);
closeFloat(12.5, $value, 'eased in toward the third');
$now += 50 * MS; $run++; $t->frame(1);
closeFloat(18.4375, $value, 'restarted, from the value on the old trajectory');
$now += 400 * MS; $t->frame(1);
closeFloat(30, $value, 'played through');
check(!$t->rt->needsFrame, 'a sequence played through asks for no frames');
$s = new KeyframeSequence($frames, new KeyframeOptions(repeat: 1, alternate: true), 0, 0);
[$v, $done] = $s->sample(500 * MS);
closeFloat(10, $v, 'alternating, on the way back');
check(!$done, 'alternating, not done on the way back');
[$v, $done] = $s->sample(600 * MS);
closeFloat(0, $v, 'alternating, back at the first value');
check($done, 'alternating, done at its first value');

// Reduced motion mid-flight finishes what moves, sequences played for ever too, and a replay plays again.
$target = 0.0; $run = 0; $spring = $eased = $keyed = 0.0;
$t = motionTester(function (Context $c) use (&$target, &$run, &$spring, &$eased, &$keyed) {
    $e = $c->box(10, 10, 0);
    $spring = $c->spring($e, 'spring', $target);
    $eased = $c->animate($e, 'ease', $target, 1);
    $keyed = $c->keyframes($e, 'keys', [new Keyframe(0, 0), new Keyframe(1, 20)], new KeyframeOptions(run: $run, repeat: -1));
}, $now);
$target = 100.0;
$t->frame(1);
$now += 200 * MS; $t->frame(1);
check($spring != 100 && $eased != 100 && $keyed != 20, 'moving before motion is reduced');
$t->rt->reduceMotion = true; $t->frame(1);
closeFloat(100, $spring, 'reduced, the spring');
closeFloat(100, $eased, 'reduced, the curve');
closeFloat(20, $keyed, 'reduced, the sequence');
check(!$t->rt->needsFrame, 'reduced motion leaves no frames coming');
$t->rt->reduceMotion = false; $t->frame(1);
same(20.0, $keyed, 'motion back, a sequence done is not played again');
$run++; $t->frame(1);
check($t->rt->needsFrame, 'a replay plays the sequence again');

// Invalid motion throws.
foreach ([
    fn(Context $c) => $c->spring($c->box(1, 1, 0), 'x', 0, new SpringOptions(mass: -1)),
    fn(Context $c) => $c->keyframes($c->box(1, 1, 0), 'x', [new Keyframe(1, 0), new Keyframe(2, 0)]),
    fn(Context $c) => $c->keyframes($c->box(1, 1, 0), 'x', [new Keyframe(0, 0), new Keyframe(1, 0), new Keyframe(1, 0)]),
] as $i => $view) {
    check(rejects(fn() => (new Headless($view))->frame(1)), "invalid motion $i accepted");
}

// A spring's duration and bounce, and its physics.
[$m, $k, $c] = (new SpringOptions())->physics();
$w = 2 * M_PI / 0.5;
check($m == 1 && abs($k - $w * $w) <= 1e-9 && abs($c - 2 * $w) <= 1e-9, "the default spring: $m $k $c");
[, $k, $c] = (new SpringOptions(0.25, 0.3))->physics();
$w = 2 * M_PI / 0.25;
check(abs($k - $w * $w) <= 1e-9 && abs($c - 2 * 0.7 * $w) <= 1e-9, "a bouncy spring: $k $c");
[, , $c] = (new SpringOptions(bounce: -0.5))->physics();
check(abs($c - 2 * 2 * (2 * M_PI / 0.5)) <= 1e-9, "an overdamped spring: $c");
[$m, $k, $c] = (new SpringOptions(stiffness: 300))->physics();
check($m == 1 && $k == 300 && $c == 26, "a physical spring: $m $k $c");
foreach ([new SpringOptions(1, stiffness: 100), new SpringOptions(bounce: 0.2, damping: 10), new SpringOptions(bounce: 1), new SpringOptions(bounce: -1), new SpringOptions(-1), new SpringOptions(precision: NAN)] as $i => $o) {
    check(rejects(fn() => $o->physics()), "spring options $i accepted");
}
// A bouncy spring overshoots its target; a smooth one does not.
foreach ([[Animation::smooth(), false], [Animation::bouncy(), true]] as [$a, $over]) {
    $ch = new MotionChannel(0, 0);
    $ch->retarget(100, $a);
    $most = 0.0;
    for ($i = 1; $i <= 120; $i++) { $ch->advance(intdiv($i * 1_000_000_000, 60)); $most = max($most, $ch->value); }
    check(($most > 100.01) === $over && !$ch->moving, "a spring of bounce {$a->spring->bounce} went as far as $most");
}

// A spring takes a velocity as its target changes.
$target = 0.0; $o = new SpringOptions();
$t = motionTester(function (Context $c) use (&$target, &$o, &$value, &$e) { $e = $c->box(10, 10, 0); $value = $c->spring($e, 'x', $target, $o); }, $now);
$target = 100.0; $o = new SpringOptions(velocity: -2000);
$t->frame(1);
same(-2000.0, $t->rt->motions[$e->id]['x']->velocity, 'the spring starts at the velocity given');
$now += 30 * MS; $t->frame(1);
check($value < 0, "thrown away from its target, the value is $value");
$now += 1000 * MS; $t->frame(1);
check($value > 50, "the spring heads back: $value");

// A delay, settled and arrived.
$target = 0.0; $settled = $arrived = false; $arrivals = 0;
$t = motionTester(function (Context $c) use (&$target, &$value, &$settled, &$arrived, &$arrivals) {
    $e = $c->box(10, 10, 0);
    $value = $c->animateTo($e, 'x', $target, new Animation(0.1, Easing::linear(...), delay: 0.05));
    $settled = $c->settled($e, 'x'); $arrived = $c->arrived($e, 'x');
    if ($arrived) { $arrivals++; }
}, $now);
check($settled && !$arrived, 'a value at its first target is settled, not arrived');
$target = 10.0; $t->frame(1);
check($value == 0 && !$settled, "delayed, the value is $value");
$now += 40 * MS; $t->frame(1);
closeFloat(0, $value, 'still delayed');
$now += 60 * MS; $t->frame(1);
closeFloat(5, $value, 'halfway');
$now += 60 * MS; $t->frame(1);
check($value == 10 && $settled && $arrived && $arrivals === 1, "over, the value is $value, settled " . (int)$settled . ', arrived ' . (int)$arrived . " ($arrivals)");
$now += 10 * MS; $t->frame(1);
check(!$arrived && $arrivals === 1, 'arrived in one frame only');
check(!$t->rt->needsFrame, 'a settled value asks for no frames');

// A spring takes over a curve's velocity.
$target = 0.0; $springy = false;
$t = motionTester(function (Context $c) use (&$target, &$springy, &$value, &$e) {
    $e = $c->box(10, 10, 0);
    $value = $c->animateTo($e, 'x', $target, $springy ? Animation::snappy() : new Animation(1, Easing::linear(...)));
}, $now);
$target = 100.0; $t->frame(1);
$now += 500 * MS; $t->frame(1);
closeFloat(50, $value, 'halfway along the curve');
closeFloat(100, $t->rt->motions[$e->id]['x']->velocity, "the curve's velocity");
$springy = true; $target = 0.0; $t->frame(1);
closeFloat(50, $value, 'the spring takes over where the curve is');
$now += 10 * MS; $t->frame(1);
check($value > 50, "the spring keeps the curve's velocity: $value");

// A jump hands its velocity on to the animation that takes over.
$dragging = true; $x = 0.0;
$t = motionTester(function (Context $c) use (&$dragging, &$x, &$value, &$e) {
    $e = $c->box(10, 10, 0);
    $value = $dragging ? $c->jump($e, 'x', $x) : $c->animateTo($e, 'x', 0, Animation::smooth());
}, $now);
for ($i = 0; $i < 5; $i++) { $now += 10 * MS; $x += 10; $t->frame(1); }
$v = $t->rt->motions[$e->id]['x']->velocity;
check($value == 50 && abs($v - 1000) < 1e-6, "dragged, the value is $value at $v/s");
$dragging = false;
$now += 10 * MS; $t->frame(1);
$now += 10 * MS; $t->frame(1);
check($value > 50, "let go, the value goes on: $value");
$now += 5000 * MS; $t->frame(1);
check($value == 0 && $t->rt->motions[$e->id]['x']->moving === false, "the value came back: $value");

// Interrupted, keyframes repeat their range, not where they were interrupted.
$frames = [new Keyframe(0, 0), new Keyframe(0.1, 100)];
$s = new KeyframeSequence($frames, new KeyframeOptions(repeat: KeyframeOptions::REPEAT_FOREVER, alternate: true), 0, 50);
$at = fn(int $ms) => $s->sample($ms * MS)[0];
closeFloat(50, $at(0), 'interrupted, at the start');
closeFloat(75, $at(50), 'interrupted, halfway');
closeFloat(1, $at(199), 'back to the first value');
closeFloat(50, $at(250), 'the third time through');
$s = new KeyframeSequence($frames, new KeyframeOptions(repeat: 1), 0, 50);
closeFloat(50, $s->sample(150 * MS)[0], 'repeated once, halfway the second time');
check(!$s->sample(199 * MS)[1], 'repeat 1 plays twice');
[$v, $done] = $s->sample(200 * MS);
check($done && $v == 100, "repeat 1 is done: $v");
$s = new KeyframeSequence($frames, new KeyframeOptions(), 0, 0);
check($s->sample(100 * MS)[1], 'no repeat plays once');
$arrived = false;
$t = motionTester(function (Context $c) use (&$value, &$arrived, $frames) {
    $e = $c->box(10, 10, 0);
    $value = $c->keyframes($e, 'k', $frames, new KeyframeOptions(delay: 0.05));
    $arrived = $c->arrived($e, 'k');
}, $now);
closeFloat(0, $value, 'delayed, the first value');
$now += 100 * MS; $t->frame(1);
closeFloat(50, $value, 'played halfway');
$now += 100 * MS; $t->frame(1);
check($value == 100 && $arrived, "over, the sequence is at $value");

// A cubic Bézier, as CSS's.
$ease = Easing::cubicBezier(0.25, 0.1, 0.25, 1); // CSS's ease
foreach ([[0, 0], [1, 1], [0.25, 0.4085], [0.5, 0.8024], [0.75, 0.9604]] as [$x, $y]) {
    check(abs($ease($x) - $y) <= 1e-3, "ease($x) = {$ease($x)}, not $y");
}
$linear = Easing::cubicBezier(0, 0, 1, 1);
for ($x = 0.0; $x <= 1; $x += 0.1) { check(abs($linear($x) - $x) <= 1e-4, "linear($x) = {$linear($x)}"); }
check(rejects(fn() => Easing::cubicBezier(0, 0, 2, 1)), 'an x2 of 2 accepted');

// In view, within a scrolling container.
$sc = new ScrollState; $topIn = $bottomIn = false;
$t = new Headless(function (Context $c) use ($sc, &$topIn, &$bottomIn) {
    $c->column(0, 0)->size(100, 100)->scroll($sc);
      $top = $c->key('top')->box(100, 60, 0);
      $c->box(100, 200, 0);
      $bottom = $c->key('bottom')->box(100, 60, 0);
      $topIn = $c->inView($top); $bottomIn = $c->inView($bottom);
    $c->end();
}, 200, 200);
$t->frame();
check($topIn && !$bottomIn, 'at the top, the top is in view and the bottom not');
$sc->scrollTo(1000, false);
$t->frame(3);
check(!$topIn && $bottomIn, "at the bottom, the bottom is in view and the top not (scrolled to {$sc->y})");

// Disabled animations go to their targets at once.
$target = 0.0; $instant = false;
$t = motionTester(function (Context $c) use (&$target, &$instant, &$value) {
    if ($instant) { $c->disableAnimations(); }
    $value = $c->animateTo($c->box(10, 10, 0), 'x', $target, Animation::smooth());
}, $now);
$target = 50.0; $instant = true; $t->frame(1);
check($value == 50 && !$t->rt->needsFrame, "without animations, the value is $value");
$instant = false; $target = 0.0;
$now += 10 * MS; $t->frame(1);
check($value != 0, 'with animations again, the value moves');

// A color moves with premultiplied alpha, and smoothly even while motion is reduced.
$color = [0xff0000, 0.0]; $shown = [0, 0.0]; $settled = false;
$t = motionTester(function (Context $c) use (&$color, &$shown, &$settled) {
    $e = $c->box(10, 10, 0);
    $shown = $c->animateColor($e, 'bg', $color[0], new Animation(0.1, Easing::linear(...)), $color[1]);
    $settled = $c->settled($e, 'bg');
}, $now);
$color = [0x0000ff, 1.0]; $t->frame(1);
$now += 50 * MS; $t->frame(1);
check(($shown[0] >> 16) === 0 && ($shown[0] & 255) === 255 && $shown[1] >= 120 / 255 && $shown[1] <= 135 / 255 && !$settled, 'halfway from transparent red, the blue does not go through red: ' . json_encode($shown));
$t->rt->reduceMotion = true;
$color = [0x00ff00, 1.0]; $t->frame(1);
$now += 50 * MS; $t->frame(1);
$g = $shown[0] >> 8 & 255;
check($g !== 255 && $g !== 0, "without motion, colors still change smoothly: $g");
$now += 1000 * MS; $t->frame(1);
check($shown === $color && $settled, 'over, the color is ' . json_encode($shown));

// Rubberband and project.
same(50.0, Motion::rubberband(50, 0, 100, 200), 'within, rubberband');
$over = Motion::rubberband(150, 0, 100, 200); $under = Motion::rubberband(-50, 0, 100, 200);
check($over > 100 && $over < 150 && abs(($over - 100) + $under) < 1e-9, "past either end, $over and $under");
check(Motion::rubberband(1e6, 0, 100, 200) < 300, 'however far dragged, within its dimension');
$v = Motion::project(0, 1000);
check($v >= 498 && $v <= 500, "a fling of 1000/s goes $v");

// A repeat delay holds between plays.
$s = new KeyframeSequence($frames, new KeyframeOptions(repeat: 1, repeatDelay: 0.05), 0, 0);
same(100.0, $s->sample(120 * MS)[0], 'between plays, it holds');
closeFloat(50, $s->sample(200 * MS)[0], 'playing again');
[$v, $done] = $s->sample(250 * MS);
check($v == 100 && $done, "over, $v");

// A delayed sequence holds the value it starts from, whatever its easing.
$jump = fn(float $t): float => 1.0;
$s = new KeyframeSequence([new Keyframe(0, 0), new Keyframe(1, 100, $jump)], new KeyframeOptions(), 10_000 * MS, 5);
[$v, $done] = $s->sample(9_000 * MS);
check($v == 5 && !$done, "a delayed sequence showed $v");

// A loop goes round, and the easings.
$p = 0.0;
$t = motionTester(function (Context $c) use (&$p) { $p = $c->loop($c->box(10, 10, 0), 'spin', 1, Easing::linear(...)); }, $now);
$now += 250 * MS; $t->frame(1);
closeFloat(0.25, $p, 'a quarter of the way round');
check($t->rt->needsFrame, 'a loop keeps frames coming');
$now += 1000 * MS; $t->frame(1);
closeFloat(0.25, $p, 'round again');
closeFloat(0.875, Easing::easeOut(0.5), 'easeOut halfway');
closeFloat(0.125, Easing::easeIn(0.5), 'easeIn halfway');
closeFloat(0.5, Easing::easeInOut(0.5), 'easeInOut halfway');
closeFloat(1, Easing::bounce(Easing::linear(...))(0.5), 'bounced, at the turn');

// The widgets that move: a switch's knob, a collapsible opening.
$on = false; $open = false; $sw = $coll = null;
$t = motionTester(function (Context $c) use (&$on, &$open, &$sw, &$coll) {
    $sw = $c->switch('Wi-Fi', $on);
    $coll = $c->collapsible('More', $open, fn() => $c->box(50, 40, 0));
}, $now);
$on = true; $t->frame(1);
$now += 70 * MS; $t->frame(1);
check($sw->fraction > 0 && $sw->fraction < 1, "the knob on its way: {$sw->fraction}");
$now += 100 * MS; $t->frame(1);
same(1.0, $sw->fraction, 'the knob there');
$open = true; $t->frame(1); $now += 100 * MS; $t->frame(1);
$h = $coll->h; $closed = $coll->kids[0]->h;
$now += 200 * MS; $t->frame(2);
check($h > $closed && $h < $coll->h && count($coll->kids) === 2, "the collapsible grows into view: $closed, $h, {$coll->h}");
check($coll->kids[1]->clip === 0, 'open, the panel is not cut off');
$open = false; $t->frame(1); $now += 100 * MS; $t->frame(1);
check(count($coll->kids) === 2 && $coll->h < $h + 1, "closing, the panel shrinks: {$coll->h}");
$now += 200 * MS; $t->frame(2);
check(count($coll->kids) === 1, 'closed, the panel is gone');
