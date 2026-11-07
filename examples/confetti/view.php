<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);


/** One of canvas-confetti's demos: [name, about, run(Confetti, now)]. */
$presets = function (): array {
    $fire = function (Confetti $s, float $share, Closure $change): void {
        $o = new Options();
        [$o->count, $o->y] = [(int)(200 * $share), 0.7];
        $change($o);
        $s->burst($o);
    };
    return [
        ['Basic Cannon', 'One burst, up from below', function (Confetti $s) { $o = new Options(); [$o->count, $o->spread, $o->y] = [100, 70, 0.6]; $s->burst($o); }],
        ['Random Direction', 'Another angle every time', function (Confetti $s) {
            $o = new Options();
            [$o->angle, $o->spread, $o->count, $o->y] = [55 + rnd() * 70, 50 + rnd() * 20, 50 + mt_rand(0, 49), 0.6];
            $s->burst($o);
        }],
        ['Realistic Look', 'Five bursts, fast to slow', function (Confetti $s) use ($fire) {
            $fire($s, 0.25, function (Options $o) { [$o->spread, $o->startVelocity] = [26, 55]; });
            $fire($s, 0.2, function (Options $o) { $o->spread = 60; });
            $fire($s, 0.35, function (Options $o) { [$o->spread, $o->decay, $o->scalar] = [100, 0.91, 0.8]; });
            $fire($s, 0.1, function (Options $o) { [$o->spread, $o->startVelocity, $o->decay, $o->scalar] = [120, 25, 0.92, 1.2]; });
            $fire($s, 0.1, function (Options $o) { [$o->spread, $o->startVelocity] = [120, 45]; });
        }],
        ['Fireworks', 'Bursts all around for 5 s', function (Confetti $s) {
            $s->repeat(0.25, 20, function (int $i, int $n) use ($s) {
                foreach ([[0.1, 0.3], [0.7, 0.9]] as [$a, $b]) {
                    $o = new Options();
                    [$o->count, $o->startVelocity, $o->spread, $o->ticks] = [intdiv(50 * ($n - $i), $n), 30, 360, 60];
                    [$o->x, $o->y] = [$a + rnd() * ($b - $a), rnd() - 0.2];
                    $s->burst($o);
                }
            });
        }],
        ['Stars', 'Golden stars floating out', function (Confetti $s) {
            $s->repeat(0.1, 3, function () use ($s) {
                $o = new Options();
                [$o->spread, $o->ticks, $o->gravity, $o->decay, $o->startVelocity] = [360, 50, 0, 0.94, 30];
                $o->colors = [0xffe400, 0xffbd00, 0xe89400, 0xffca6c, 0xfdffb8];
                [$o->count, $o->scalar, $o->shapes] = [40, 1.2, [new Shape(Shape::STAR)]];
                $s->burst($o);
                [$o->count, $o->scalar, $o->shapes] = [10, 0.75, [new Shape(Shape::CIRCLE)]];
                $s->burst($o);
            });
        }],
        ['Snow', 'Flakes drifting down for 8 s', function (Confetti $s) {
            $skew = 1.0;
            $s->repeat(0.03, 270, function (int $i, int $n) use ($s, &$skew) {
                $left = ($n - $i) / $n;
                $skew = max(0.8, $skew - 0.001);
                $o = new Options();
                [$o->count, $o->startVelocity, $o->shapes, $o->ticks] = [1, 0, [new Shape(Shape::CIRCLE)], (int)max(200, 500 * $left)];
                [$o->x, $o->y] = [rnd(), rnd() * $skew - 0.2];
                $o->colors = $s->dark ? [0xffffff, 0xdbeafe, 0xbfdbfe] : [0x93c5fd, 0xbfdbfe, 0xcbd5e1];
                [$o->gravity, $o->scalar, $o->drift] = [0.4 + rnd() * 0.2, 0.4 + rnd() * 0.6, -0.4 + rnd() * 0.8];
                $s->burst($o);
            });
        }],
        ['School Pride', 'Cannons from both sides', function (Confetti $s) {
            $s->repeat(0.025, 160, function () use ($s) {
                foreach ([[60, 0], [120, 1]] as [$angle, $x]) {
                    $o = new Options();
                    [$o->count, $o->angle, $o->spread, $o->x] = [2, $angle, 55, $x];
                    $o->colors = [0xbb0000, $s->dark ? 0xffffff : 0x1e293b];
                    $s->burst($o);
                }
            });
        }],
        ['Emoji', 'Unicorns and rainbows', function (Confetti $s) {
            $s->repeat(0.1, 3, function () use ($s) {
                $o = new Options();
                [$o->spread, $o->ticks, $o->gravity, $o->decay, $o->startVelocity] = [360, 60, 0, 0.96, 20];
                [$o->shapes, $o->scalar, $o->count] = [[Shape::text('🦄'), Shape::text('🌈')], 2, 30];
                $s->burst($o);
                [$o->count, $o->flat] = [5, true];
                $s->burst($o);
                [$o->count, $o->flat, $o->scalar, $o->shapes] = [15, false, 1, [new Shape(Shape::CIRCLE)]];
                $s->burst($o);
            });
        }],
        ['Hearts', 'A shape drawn as a path', function (Confetti $s) {
            $s->repeat(0.1, 3, function () use ($s) {
                $o = new Options();
                [$o->spread, $o->ticks, $o->gravity, $o->decay, $o->startVelocity] = [360, 70, 0.3, 0.95, 25];
                [$o->count, $o->scalar, $o->shapes, $o->flat] = [25, 1.5, [new Shape(Shape::HEART)], true];
                $o->colors = [0xff4d6d, 0xff758f, 0xc9184a, 0xffb3c1];
                $s->burst($o);
            });
        }],
    ];
};

/** A slider of the stage's bursts: its label, the slider, its value. */
$slider = fn(string $label, float &$v, float $lo, float $hi, string $format) => HStack(spacing: 8)(
    Text($label)->font(Font::caption)->frame(width: 62, alignment: Alignment::leading),
    Slider(value: $v, in: [$lo, $hi], title: $label)->labelsHidden(),
    Text(verbatim: sprintf($format, $v))->font(Font::caption)->foregroundStyle(Color::secondary)->frame(width: 40, alignment: Alignment::trailing),
);

// The demos' tiles, which lift under the pointer; the stage, which throws a burst of the sliders' from where it is
// clicked; the sliders. Over it all, the bursts in the air, which let the pointer through.
return fn() => TimelineView(explicit: fn() => $this->due())(fn(TimelineViewContext $context) => GeometryReader(function (GeometryProxy $g) use ($presets, $slider, $context) {
    [$this->w, $this->h] = $g->size;
    $this->tick($context->nanoseconds); // the demos' bursts due, those to launch and those gone
    return ZStack(alignment: Alignment::topLeading)(
        VStack(alignment: HorizontalAlignment::leading, spacing: 20)(
            HStack(alignment: VerticalAlignment::bottom, spacing: 12)(
                VStack(alignment: HorizontalAlignment::leading, spacing: 4)(
                    Text('Confetti')->font(Font::system(size: 28, weight: Weight::bold)),
                    Text('canvas-confetti\'s demos in native UI, every piece animated by modifiers that only paint.')->foregroundStyle(Color::secondary),
                ),
                Spacer(),
                Text(verbatim: $this->inAir() . ' in the air')->font(Font::caption)->foregroundStyle(Color::secondary),
            ),
            HStack(alignment: VerticalAlignment::top, spacing: 16)(
                VStack(spacing: 16)(
                    LazyVGrid(columns: [GridItem::adaptive(minimum: 180)], spacing: 12)(Each($presets(), id: fn(array $p) => $p[0])(fn(array $p, int $i) => Button(action: fn() => $p[2]($this), label: VStack(alignment: HorizontalAlignment::leading, spacing: 4)(
                        Text($p[0])->bold(),
                        Text($p[1])->font(Font::caption)->foregroundStyle(Color::secondary),
                    )->padding(Edge::vertical, 12)->padding(Edge::horizontal, 14)->frame(maxWidth: INF, alignment: Alignment::leading)
                        ->background(Color::gray->opacity($this->hover === $i ? 0.2 : 0.12))->cornerRadius(10)->overlay(RoundedRectangle(cornerRadius: 10)->stroke(Color::separator)))
                        ->buttonStyle(ButtonStyle::plain)
                        ->offset(y: $this->hover === $i ? -2 : 0)->animation(Animation::snappy(), value: $this->hover === $i) // it lifts under the pointer
                        ->onHover(function (bool $over) use ($i) { $this->hover = $over ? $i : ($this->hover === $i ? null : $this->hover); }))),
                    VStack(spacing: 6)(
                        Text('Click anywhere here')->bold(),
                        Text('to throw your burst from the pointer')->font(Font::caption)->foregroundStyle(Color::secondary),
                    )
                        ->frame(maxWidth: INF, maxHeight: INF)
                        ->overlay(RoundedRectangle(cornerRadius: 12)->stroke(Color::separator, style: new StrokeStyle(lineWidth: 1, dash: [4, 4])))
                        ->onTapGesture(fn(CGPoint $at) => $this->burst($this->custom($at->x, $at->y)), coordinateSpace: CoordinateSpace::global),
                ),
                VStack(alignment: HorizontalAlignment::leading, spacing: 10)(
                    Text('Your burst')->bold(),
                    $slider('Count', $this->count, 1, 400, '%.0f'),
                    $slider('Angle', $this->angle, 0, 360, '%.0f°'),
                    $slider('Spread', $this->spread, 0, 360, '%.0f°'),
                    $slider('Velocity', $this->velocity, 0, 90, '%.0f'),
                    $slider('Decay', $this->decay, 0.8, 0.99, '%.2f'),
                    $slider('Gravity', $this->gravity, -1, 3, '%.1f'),
                    $slider('Drift', $this->drift, -2, 2, '%.1f'),
                    $slider('Size', $this->scalar, 0.4, 3, '%.1f×'),
                    Spacer(),
                    Button('Reset', action: fn() => $this->resetOptions()),
                )->padding(16)->frame(width: 280)->frame(maxHeight: INF)->background(Color::gray->opacity(0.12))->cornerRadius(12)->overlay(RoundedRectangle(cornerRadius: 12)->stroke(Color::separator)),
            )->frame(maxHeight: INF),
        )->padding(24)->frame(maxWidth: INF, maxHeight: INF, alignment: Alignment::topLeading),
        Each($this->bursts, id: fn(Burst $b) => $b->id)(fn(Burst $b) => $b->view()),
    );
}));
