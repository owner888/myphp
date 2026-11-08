# Motion

> This page is about the engine under the views: its builder, `$c` a
> `Context`, which an app reaches inside a `Representable(fn(Context $c) =>
> …)`, as SwiftUI's `NSViewRepresentable` ([custom widgets](custom-widgets.md)). For animating views, `->animation()`, `withAnimation()` and `->transition()` ([Coming from SwiftUI](../swiftui.md#animation)).

A node animates values for you: you give the target, it returns the value
to show in this frame, and draws frames until the value gets there. The
view says where things are; the animation finds the way:

```php
$app = new class { public bool $open = true; };
$view = function (Context $c) use ($app) {
    $card = $c->column()->size(120, 80)->radius(12)->background($c->theme->accent);
    $c->end();
    $card->translate($c->animateTo($card, 'x', $app->open ? 200 : 0, Animation::snappy()), 0);
};
```

The key (`'x'`) tells apart the values of one node, which needs the same
ID from frame to frame: a `$c->key()`, or the same place among its
siblings. A value starts at its first target. Use the value in any way: a
transform (see [Transforms](transforms.md)), a size, an opacity, a color
mix, or what a `draw()` paints.

## Animations

An `Animation` says how a value goes to a new target:

- **Springs**, `new Animation()` and the presets `Animation::smooth()` (no
  bounce), `Animation::snappy()` (a little) and `Animation::bouncy()` (a
  visible one). A spring keeps its velocity when its target changes on the
  way: clicked again mid-flight, the card above turns around smoothly
  instead of stopping dead.
- **Curves**: a `duration` in seconds and an `ease` (`Easing::easeOut`
  when null), as `new Animation(duration: 0.15, ease:
  Easing::easeInOut(...))`. `$c->animate($n, $key, $target, $seconds)` and
  `$c->animateWith($n, $key, $target, $seconds, $ease)` are short for them.

A spring's `SpringOptions` describe it as one perceives it, with a
`duration` (0.5 seconds when zero), about how long it takes to arrive, and
a `bounce`, from 0, arriving without overshooting, toward 1; negative
values arrive more slowly still. These are SwiftUI's and Motion's
parameters. `stiffness`, `damping` and `mass` describe it physically
instead:

```php
$view = function (Context $c) {
    $card = $c->column()->size(60, 40);
    $c->end();
    $gentle = new Animation(spring: new SpringOptions(duration: 0.7, bounce: 0.1));
    $stiff = new Animation(spring: new SpringOptions(stiffness: 400, damping: 30));
    $x = $c->animateTo($card, 'x', 100, $gentle);
};
```

`$c->spring($n, $key, $target, $options)` is short for `animateTo()` with a
spring. Springs are solved exactly rather than stepped, so a slow frame
does not change their path. A value can change its kind of animation on
the way: a spring taking over a curve keeps the curve's velocity.

`Easing` functions are `Easing::linear(...)`, `Easing::easeIn(...)`,
`Easing::easeOut(...)`, `Easing::easeInOut(...)`, `Easing::bounce($e)`,
CSS's `Easing::cubicBezier($x1, $y1, $x2, $y2)`, or any
`Closure(float): float`.

`$c->animateColor($n, $key, $rgb, $a, $alpha)` animates a color the same
way, mixed with premultiplied alpha, so that a color fading in from
transparent keeps its hue; it returns `[$rgb, $alpha]`:

```php
$view = function (Context $c) {
    $t = $c->theme;
    $row = $c->row(8);
      $c->text('Row');
    $c->end();
    $bg = $c->hovered($row) ? $t->surfaceHover : $t->background;
    [$rgb, $alpha] = $c->animateColor($row, 'bg', $bg, new Animation(duration: 0.12));
    $row->background($rgb, $alpha);
};
```

## Poses that only paint

The values above are read by the view, so every frame that moves runs it.
`animatePose()` moves a node instead: it translates, scales, turns and
fades it, after its own transform, and the frames that move it only paint,
without running the view, as Core Animation and the Web Animations API run
transforms and opacity. It is the cheapest motion there is, which keeps a
large view smooth:

```php
$view = function (Context $c) {
    $card = $c->column(12)->radius(12)->background($c->theme->surface);
      $c->text('Card');
    $c->end();
    $lift = $c->hovered($card) ? new Pose(y: -4, scale: 1.02) : new Pose();
    $card->animatePose($lift, Animation::snappy());
};
```

A `Pose` moves by `x` and `y`, scales by `scale` (0 keeps the size), turns
by `rotate` degrees, and fades by `fade`, from 0, opaque, to 1, gone. The
pointer, carets and overlays follow the node as it moves, and
`$c->transitioning()` reports whether its pose was moving.

Poses nest: each acts after those of the nodes around it, so a node inside
another adds its own motion, along its own curve. In the
[confetti example](../../examples/confetti), a burst falls and fades, each
piece in it bursts out along a curve that slows down, and spins inside:
hundreds of pieces fly without building the view again. Each node posed
still costs every frame that paints it, so share a pose among the nodes
that move alike, as the burst's fall, rather than giving each its own.

## Delays and staggering

`delay` keeps the value going as it was for a while after its target
changes. Delays growing with the index stagger a list:

```php
$items = [['id' => 'a'], ['id' => 'b'], ['id' => 'c']];
$view = function (Context $c) use ($items) {
    foreach ($items as $i => $it) {
        $row = $c->key($it['id'])->row();
          $c->text($it['id']);
        $c->end();
        $a = new Animation(spring: Animation::snappy()->spring, delay: $i * 0.03);
        $row->opacity($c->animateTo($row, 'shown', 1, $a));
    }
};
```

## Gestures

`$c->draggable()` lets the pointer move a node and throw it, as
motion.dev's `drag` does: the node follows the pointer, and once let go,
the pointer's velocity carries it to where it comes to rest, with a
spring:

```php
$app = new class { public ?array $card = [0, 0]; };
$view = function (Context $c) use ($app) {
    $card = $c->column()->size(90, 60)->radius(10)->background($c->theme->accent);
    $c->end();
    $c->draggable($card, $app->card, new DraggableOptions(
        axis: Node::HORIZONTAL,
        bounds: [0, 0, 200, 0],
        snap: Motion::nearest([0, 0], [200, 0]),
    ));
};
```

`$app->card` is a point, `[x, y]`, the node's offset from its place in the
layout. As the pointer drags the node, it follows the pointer; once the
pointer lets go, it is where the node comes to rest; and setting it moves
the node there, as a button turning a page does. Without an offset, the
node keeps one of its own. The options say how it moves:

- **`axis`**, `Node::HORIZONTAL` or `Node::VERTICAL`, is the direction it
  moves in; both when zero.
- **`bounds`**, `[x, y, w, h]`, hold the offset within a rectangle at
  rest, from `x` to `x + w` and from `y` to `y + h`. The pointer pulls the
  node past them less and less far, as iOS holds content pulled past its
  edges, and it springs back within them once let go. `rigid` stops it at
  them.
- **`snap`** chooses where it comes to rest, from where the throw would
  carry it (`Motion::project()`, held within the bounds), so that a flick
  goes on to the next page or height: `Motion::nearest(...$points)`
  chooses the nearest of points. A `snap` returning its point lets the
  node glide where the throw carries it. Without `snap`, it rests where the
  pointer lets it go, as an item placed on a canvas.
- **`animation`** is how it goes to rest, and to an offset the app sets:
  `Animation::smooth()` when null. A spring starts at the pointer's
  velocity.

The pointer drags the node once it moved a few points, and that press is
no click; a press on the node as it moves catches it. The node moves after
its own transform and its pose, along its parent's axes, so it stays under
the pointer as it scales or turns, and it goes to rest in frames that only
paint, without building the view, as `animatePose()` moves it.
`$c->dragging()` reports that the pointer drags it, to lift it as it does:

```php
$app = new class { public ?array $card = [0, 0]; };
$view = function (Context $c) use ($app) {
    $card = $c->column()->size(90, 60)->radius(10)->background($c->theme->accent);
    $c->end();
    $c->draggable($card, $app->card);
    $card->animatePose($c->dragging($card) ? new Pose(scale: 1.05) : new Pose(), Animation::snappy());
};
```

`$c->transitioning()` is false once it is at rest, for the view to go on,
as removing a row swiped away:

```php
$app = new class {
    public array $rows = [['id' => 1, 'swipe' => [0, 0]], ['id' => 2, 'swipe' => [0, 0]]];
    public function remove(int $id): void { $this->rows = array_values(array_filter($this->rows, fn($r) => $r['id'] !== $id)); }
};
$view = function (Context $c) use ($app) {
    $gone = null;
    foreach ($app->rows as $i => $r) {
        $row = $c->key($r['id'])->row(8);
          $c->text('Row ' . $r['id']);
        $c->end();
        $c->draggable($row, $app->rows[$i]['swipe'], new DraggableOptions(
            axis: Node::HORIZONTAL,
            snap: fn(array $p) => $p[0] < -160 ? [-400, 0] : [0, 0], // away to the left, or back
        ));
        if ($app->rows[$i]['swipe'][0] == -400 && !$c->transitioning($row)) {
            $gone = $r['id'];
        }
    }
    if ($gone !== null) {
        $app->remove($gone);
    }
};
```

`$c->dragOffset()` returns where the node shows in this frame, following
the pointer or on its way to rest, for what follows it, as a backdrop
fading as a sheet goes down. Reading it builds the view in every frame the
node moves in, as `animateTo()` does; call it after `draggable()`:

```php
$app = new class { public ?array $sheet = [0, 0]; };
$view = function (Context $c) use ($app) {
    $backdrop = $c->column()->absolute()->fill()->background(0x000000, 0.4);
    $c->end();
    $sheet = $c->column();
      $c->text('Sheet');
    $c->end();
    $c->draggable($sheet, $app->sheet, new DraggableOptions(
        axis: Node::VERTICAL,
        bounds: [0, 0, 0, 400],
        snap: Motion::nearest([0, 0], [0, 400]),
    ));
    $backdrop->opacity(1 - $c->dragOffset($sheet)[1] / 400);
};
```

While the desktop asks for less motion, the node follows the pointer, and
goes to rest at once.

### By hand

For a value other than an offset that follows the pointer, as the angle of
a dial, build the gesture from smaller pieces. `$c->jump()` sets a value at
once, following the pointer, and keeps track of how fast it moves; the
spring that takes over when the pointer lets go starts at that speed.
`$c->dragEnded()` reports the pointer's velocity as it let go, which
`SpringOptions::$velocity` hands to the spring:

```php
$app = new class { public float $turn = 0; };
$view = function (Context $c) use ($app) {
    $dial = $c->column()->size(80, 80)->background($c->theme->surface)->radius(40);
    $c->end();
    if (($d = $c->dragged($dial)) !== null) {
        $app->turn += $d[0];
        $turn = $c->jump($dial, 'turn', $app->turn);
    } else {
        $velocity = 0.0;
        if (($v = $c->dragEnded($dial)) !== null) {
            // Where the throw would carry it decides the notch it lands on.
            $velocity = $v[0];
            $app->turn = round(Motion::project($app->turn, $velocity) / 45) * 45;
        }
        $snappy = Animation::snappy()->spring;
        $a = new Animation(spring: new SpringOptions($snappy->duration, $snappy->bounce, velocity: $velocity));
        $turn = $c->animateTo($dial, 'turn', $app->turn, $a);
    }
    $dial->transform(Transform::rotation($turn));
};
```

`$c->dragVelocity()` is the pointer's velocity while it presses, in points
per second along the node's axes, zero once it rests a moment.
`$c->velocity($n, $key)` is the velocity of an animated value. A click,
which does not move, ends no drag.

`Motion::project($value, $velocity)` is where a fling would carry a value
as iOS's scroll views slow down, to choose where it lands, as a page of a
pager. `Motion::rubberband($value, $lo, $hi, $dimension)` holds a value
dragged past lo or hi as iOS holds content pulled past its edges, going
less and less far:

```php
$app = new class { public float $y = 0; };
$view = function (Context $c) use ($app) {
    $sheet = $c->column()->height(200);
    $c->end();
    $y = $app->y;
    if (($d = $c->dragged($sheet)) !== null) {
        $app->y += $d[1];
        $y = $c->jump($sheet, 'y', Motion::rubberband($app->y, 0, 400, 600));
    }
    $sheet->translate(0, $y);
};
```

## When it is done

`$c->settled($n, $key)` reports whether a value rests at its target, and
`$c->arrived($n, $key)` is true in the one frame in which it came to rest,
for the view to go on, as removing what faded out:

```php
$app = new class { public bool $shown = true; };
$view = function (Context $c) use ($app) {
    if (!$app->shown) { return; }
    $toast = $c->column(8);
      $c->text('Saved');
    $c->end();
    $toast->opacity($c->animateTo($toast, 'fade', 0, new Animation(duration: 0.2)));
    if ($c->arrived($toast, 'fade')) {
        $app->shown = false;
    }
};
```

## Without animation

`$c->disableAnimations()` makes the values, transitions, poses and router
pages of the frame go to their targets at once, as SwiftUI's
disablesAnimations: for a change that should not show as motion, as
restoring a window's state or jumping to a search result. Call it before
building what changes. `keyframes()` and timelines play on their own
rather than towards a target, and go on as they were; seek a timeline to
jump it.

## Keyframes

`$c->keyframes()` plays timed values, each segment along the `ease` of the
frame it arrives at, and holds the last:

```php
$app = new class { public int $shakes = 0; };
$view = function (Context $c) use ($app) {
    $tile = $c->key('tile')->column()->size(80, 50)->background($c->theme->accent);
    $c->end();
    $x = $c->keyframes($tile, 'x', [
        new Keyframe(0, 0),
        new Keyframe(0.2, 100, Easing::easeOut(...)),
        new Keyframe(0.7, 40),
        new Keyframe(1.0, 0, Easing::easeInOut(...)),
    ], new KeyframeOptions(run: $app->shakes));
    $tile->translate($x, 0);
};
```

A `Keyframe` takes its time in seconds and its value. The first time must
be zero and later times must increase. Change `run` to play the sequence
again, or a changed one: interrupted, it starts from the value shown rather
than jumping to its first, the first time through. `repeat` is how many
more times it plays after the first, `KeyframeOptions::REPEAT_FOREVER` for
as long as it is built, with `alternate` playing every other time
backward; `delay` holds the first value for a while before it plays, and
`repeatDelay` the value it got to between plays.

## Timelines

A `Timeline` plays several tweens along one playhead, so motion that
belongs together is arranged, controlled and tested together: a card
arriving while its title fades in, rows following one another, a small
bounce after the card lands. `animateTo()` moves one value of one node
towards a target, and `keyframes()` plays one value along timed stops; a
timeline coordinates many such values, each tween of its own, around one
clock.

A node keeps a timeline under a key, as it keeps keyframes: build it in
the view, as everything else, and give each tween the value it shows this
frame:

```php
$app = new class { public int $opened = 1; };
$view = function (Context $c) use ($app) {
    $card = $c->column(12);
      $c->text('Card');
    $c->end();
    $tl = $c->timeline($card, 'intro', new TimelineOptions(run: $app->opened));
    $x = $tl->tween(new Tween(from: -200, to: 0, duration: 0.4));
    $fade = $tl->tween(new Tween(from: 0, to: 1, duration: 0.3), Placement::afterPrevious(-0.15));
    $card->translate($x, 0)->opacity($fade);
};
```

A tween eases out (`Easing::easeOut`) unless its `ease` says otherwise, as
an `Animation` does. A tween with no placement follows the one before it at
once, so here the second would start at 0.4 seconds; placements put it
elsewhere, as GSAP's position parameter does:

| Placement | Starts the tween |
| --- | --- |
| `Placement::afterPrevious(0.05)` | 50 ms after the one before it ends; a negative offset overlaps it, as `-0.15` above starts the fade at 250 ms |
| `Placement::withPrevious(0)` | where the one before it starts, to move along with it, or an offset after that |
| `Placement::atLabel('rows', 0.1)` | 100 ms after a label |
| `Placement::atTime(0.5)` | at a time of the timeline |

Nothing starts before the timeline does: a placement before its start is
at its start.

### Labels and stagger

`label()` marks a time of the timeline for tweens to start at, and an
offset from it staggers them by index:

```php
$app = new class { public int $shown = 1; public array $rows = ['a', 'b', 'c']; };
$view = function (Context $c) use ($app) {
    $list = $c->column(gap: 4);
      $rows = [];
      foreach ($app->rows as $i => $name) {
          $rows[$i] = $c->key($name)->text($name);
      }
    $c->end();
    $tl = $c->timeline($list, 'rows', new TimelineOptions(run: $app->shown));
    $tl->label('rows', Placement::atTime(0.3));
    foreach ($rows as $i => $row) {
        $shown = $tl->tween(new Tween(from: 0, to: 1, duration: 0.2), Placement::atLabel('rows', $i * 0.04));
        $row->opacity($shown)->translate(0, (1 - $shown) * 12);
    }
};
```

### Playing it

`duration()` is the end of the last tween, `time()` where the playhead is,
and `progress()` how far along it is, from 0 to 1, for a scrubber.
`paused` holds the playhead where it is: time a paused timeline spends is
not time it played, so letting a pause go plays on from where it held,
however long the pause was. `reverse` plays it backward from where the
playhead is to its start, as a panel that closes the way it opened
(`reverse: !$app->open`), and a timeline played over while reversed plays
from its end. `seek` and `progress` hold it at a time or a part of the
timeline for as long as they are given, going on from there when they are
not; give one of them at most. What the options say takes effect in the
frame it is said in, so a button that pauses or plays the timeline again
shows in the frame it was clicked in:

```php
$app = new class { public int $run = 1; public bool $paused = false, $reverse = false, $scrubbing = true; public float $scrub = 0.5; };
$view = function (Context $c) use ($app) {
    $card = $c->column(12);
      $c->text('Card');
    $c->end();
    $tl = $c->timeline($card, 'intro', new TimelineOptions(
        run: $app->run, paused: $app->paused, reverse: $app->reverse,
        progress: $app->scrubbing ? $app->scrub : null,
    ));
    $card->opacity($tl->tween(new Tween(from: 0, to: 1, duration: 0.4)));
};
```

`repeat` is how many more times the timeline plays after the first, as
`KeyframeOptions::$repeat` is, `REPEAT_FOREVER` for as long as the view
builds it, with `alternate` playing every other time backward; `delay`
holds the timeline at its first value for a while before it plays, and
`repeatDelay` the value it got to between plays. `settled()` reports that
it rests, at its end or, in reverse, at its start, and `arrived()` is true
in the one frame in which it came to rest, as `$c->settled()` and
`$c->arrived()` are for a value.

Change `run` to play the timeline again, or a changed one. Building it
without changing `run` keeps the playhead where it is, so a view may
rearrange its tweens from frame to frame: the timeline plays the ones it
arranged this frame. Arranging a longer one goes on from where the playhead
is, and it is not settled until the timeline it arranged has played. A
timeline goes with its node's state when the view stops building the node.
A node asks for each of its timelines once per build, and the `Timeline`
it returns is good while the view builds that frame.

While the desktop asks for less motion, a timeline that would play is at
once where it would come to rest, every tween at its `to`, or at its
`from` when it plays back to its start, as values, poses and keyframes
are, and it asks for no frame. What the view does with the playhead itself
still works, as a drag still moves a draggable node: `seek` and `progress`
put it where a scrubber asks, and `paused` holds it there.
`disableAnimations()` does not change a timeline, which plays on its own
as keyframes do; `seek` jumps it. A timeline draws frames only while it
plays, timed by `$c->now()`.

## Scrolling

`$c->inView()` reports whether some of a node showed in the frame before,
within the scroll containers around it, to bring it in as it scrolls into
view. A `ScrollState` (see [Scrolling](scroll.md)) gives how far its
container scrolled, for effects that follow the scrolling:

```php
$scroll = new ScrollState();
$view = function (Context $c) use ($scroll) {
    $header = $c->text('Header')->fontSize(20);
    $header->opacity(1 - min($scroll->y / 80, 1)); // fades as the page scrolls
    $c->column()->scroll($scroll)->grow(1);
      foreach (range(1, 20) as $id) {
          $card = $c->key($id)->column()->height(120);
            $c->text("Card $id");
          $c->end();
          $card->opacity($c->animateTo($card, 'shown', $c->inView($card) ? 1 : 0, Animation::smooth()));
      }
    $c->end();
};
```

`ScrollState::scrollTo($y, $animation)` scrolls a container smoothly (see
[Scrolling](scroll.md)).

## Content that changes

`contentTransition()` animates a node's content as it changes: the text of
a text, the icon of an icon, the picture of an image. By default the old
content fades out as the new fades in, and an icon shrinks away as the new
one grows in. `numeric` rolls the characters of a line of text that
change, as a counter's digits, those that stay staying: up, or with `down`
down, as SwiftUI's numericText:

```php
$play = SVG::fromFile('icons/chevron.svg');
$app = new class { public int $count = 41; public bool $decreased = false; };
$view = function (Context $c) use ($app, $play) {
    $c->text((string)$app->count)->fontSize(32)->bold()
        ->contentTransition(new ContentTransition(numeric: true, down: $app->decreased));
    $c->icon($play)->contentTransition(new ContentTransition());
};
```

Content transitions run in frames that only paint. While the desktop asks
for less motion, content fades without moving.

## Loops and your own motion

`$c->loop()` returns the progress of an animation that starts over every
period, for spinners and pulses (see [Drawing and
animation](drawing.md#animation)). To animate otherwise, compute from
`$c->now()` and call `$c->animationFrame()` in every frame that moves.

## Less motion and frames

When the desktop asks for less motion (`$c->preferences()->reduceMotion`),
values and poses go to their targets at once and sequences end at their
last value. What changes without moving still changes smoothly: colors
(`animateColor()`), a pose's `fade`, content fading across, and
[transitions](transitions.md)' colors and fades. `loop()` goes on, as the
system's spinners do.

Animations draw frames only while something moves, at the display's rate,
and none while the window is out of sight (below). Frames moving values
the view reads run the view; poses, content transitions, and drawings that
animate from their `draw()` only paint.

## Out of sight

While nothing of a window shows (on macOS: hidden, minimized, on another
Space, or entirely covered by other windows), MyPHP draws no frames for it,
as browsers draw nothing for pages out of sight and as Apple asks of Mac
apps. The view does not run, `draw()` is not called, and the frames
`after()` and `Painter::after()` ask for wait. As soon as some of the window
shows, it draws once, with everything that changed meanwhile; until then
the screen shows its last frame, for about a refresh. A frame still builds
after input (keys typed into a key window another app's window covers),
when assistive technology turns on, and for a capture of the window, so
that it shows the window as it is now. Linux does not tell MyPHP when a
window is out of sight, so there it draws as it always did.

What this means for motion:

- **What MyPHP animates goes on from where the time puts it.** A spring,
  an `animate()` on its way, a `loop()`, keyframes and timelines running
  when the window went out of sight are further along, or done, when it
  shows.
- **A target that changed out of sight animates when the window shows.**
  The view first sees the new target then, so the value moves to it from
  where it was, as a CSS transition does for a change made in a hidden
  tab.
- **Compute your own motion from the time, not from frames.** A start time
  taken in the first frame that draws something, or a count of frames,
  starts over when the window shows, and plays what happened meanwhile as
  if it just began. Take the time from when the state changed, in the code
  that changes it, and the view shows a change made out of sight as done:

```php
/** Eases from one value to the next over 0.4 seconds, by the clock. */
final class Counter
{
    public const EASE = 400_000_000; // ns

    public function __construct(public float $from = 0, public float $to = 0, public int $changed = 0) {}

    /** The value shown at $t, in nanoseconds (hrtime(true), as Context::now()). */
    public function at(int $t): float
    {
        $p = min(($t - $this->changed) / self::EASE, 1);
        return $this->from + ($this->to - $this->from) * Easing::easeOut($p);
    }
}

$app = new class { public Counter $count; public function __construct() { $this->count = new Counter(); } };
// Where the count changes, outside the view (then Window::update()):
$now = hrtime(true);
$app->count = new Counter($app->count->at($now), 42, $now);

$view = function (Context $c) use ($app) {
    $value = $app->count->at($c->now());
    if ($c->now() - $app->count->changed < Counter::EASE) {
        $c->animationFrame();
    }
    $c->text(sprintf('%.0f', $value));
};
```

- **Keep work out of the view.** Polling, saving, or starting jobs from the
  view, from `after()` or from a `draw()` waits while the window is out of
  sight. Run such work from the app (`App::after()`, a fiber with
  `App::go()`) and show what it does with `Window::update()`.
