<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\UI\{Animation, BorderStyle, Context, Cursor, Font, InputEvent, Node, Pose};

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
        ['Fireworks', 'Bursts all around for 5 s', function (Confetti $s, int $now) {
            $s->repeat($now, 0.25, 20, function (int $i, int $n) use ($s) {
                foreach ([[0.1, 0.3], [0.7, 0.9]] as [$a, $b]) {
                    $o = new Options();
                    [$o->count, $o->startVelocity, $o->spread, $o->ticks] = [intdiv(50 * ($n - $i), $n), 30, 360, 60];
                    [$o->x, $o->y] = [$a + rnd() * ($b - $a), rnd() - 0.2];
                    $s->burst($o);
                }
            });
        }],
        ['Stars', 'Golden stars floating out', function (Confetti $s, int $now) {
            $s->repeat($now, 0.1, 3, function () use ($s) {
                $o = new Options();
                [$o->spread, $o->ticks, $o->gravity, $o->decay, $o->startVelocity] = [360, 50, 0, 0.94, 30];
                $o->colors = [0xffe400, 0xffbd00, 0xe89400, 0xffca6c, 0xfdffb8];
                [$o->count, $o->scalar, $o->shapes] = [40, 1.2, [new Shape(Shape::STAR)]];
                $s->burst($o);
                [$o->count, $o->scalar, $o->shapes] = [10, 0.75, [new Shape(Shape::CIRCLE)]];
                $s->burst($o);
            });
        }],
        ['Snow', 'Flakes drifting down for 8 s', function (Confetti $s, int $now) {
            $skew = 1.0;
            $s->repeat($now, 0.03, 270, function (int $i, int $n) use ($s, &$skew) {
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
        ['School Pride', 'Cannons from both sides', function (Confetti $s, int $now) {
            $s->repeat($now, 0.025, 160, function () use ($s) {
                foreach ([[60, 0], [120, 1]] as [$angle, $x]) {
                    $o = new Options();
                    [$o->count, $o->angle, $o->spread, $o->x] = [2, $angle, 55, $x];
                    $o->colors = [0xbb0000, $s->dark ? 0xffffff : 0x1e293b];
                    $s->burst($o);
                }
            });
        }],
        ['Emoji', 'Unicorns and rainbows', function (Confetti $s, int $now) {
            $s->repeat($now, 0.1, 3, function () use ($s) {
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
        ['Hearts', 'A shape drawn as a path', function (Confetti $s, int $now) {
            $s->repeat($now, 0.1, 3, function () use ($s) {
                $o = new Options();
                [$o->spread, $o->ticks, $o->gravity, $o->decay, $o->startVelocity] = [360, 70, 0.3, 0.95, 25];
                [$o->count, $o->scalar, $o->shapes, $o->flat] = [25, 1.5, [new Shape(Shape::HEART)], true];
                $o->colors = [0xff4d6d, 0xff758f, 0xc9184a, 0xffb3c1];
                $s->burst($o);
            });
        }],
    ];
};

/** A tile for each of canvas-confetti's demos, which lifts under the pointer and sinks as it presses: a pose, which moves in frames that only paint. */
$presetTiles = function (Context $c, Confetti $s, int $now, bool $still) use (&$presets): void {
    $t = $c->theme;
    $c->grid(3, 12);
      foreach ($presets() as $i => [$name, $about, $run]) {
          $tile = $c->key($i)->buttonBase()->label($name)->padding(12, 14)->radius(10)->border(1, $t->border)->cursor(Cursor::Pointer);
          [$bg, $lift] = $c->pressed($tile) ? [$t->surfacePressed, new Pose(scale: 0.97)] : ($c->hovered($tile) ? [$t->surfaceHover, new Pose(y: -2)] : [$t->surface, new Pose()]);
          $tile->background($bg)->animatePose($lift, Animation::snappy());
            $c->column(0, 4, 1);
              $c->text($name, style: Font::BOLD);
              $c->text($about, 12, $t->textMuted);
            $c->end();
          $c->end();
          if ($c->clicked($tile) && !$still) { $run($s, $now); }
      }
    $c->end();
};

/** The sliders of the stage's bursts. */
$controls = function (Context $c, Confetti $s): void {
    $t = $c->theme;
    $c->column(16, 10, 0, $t->surface)->size(280, 0)->radius(12)->border(1, $t->border);
      $c->text('Your burst', style: Font::BOLD);
      $slider = function (string $label, float &$v, float $lo, float $hi, string $format) use ($c, $t) {
          $c->row(0, 8)->alignItems(Node::CENTER);
            $c->text($label, 12)->size(62, 0);
            $c->key($label)->slider($v, $lo, $hi, 0)->label($label)->grow(1);
            $c->text(sprintf($format, $v), 12, $t->textMuted)->size(40, 0)->textAlign(Node::END);
          $c->end();
      };
      $slider('Count', $s->count, 1, 400, '%.0f');
      $slider('Angle', $s->angle, 0, 360, '%.0f°');
      $slider('Spread', $s->spread, 0, 360, '%.0f°');
      $slider('Velocity', $s->velocity, 0, 90, '%.0f');
      $slider('Decay', $s->decay, 0.8, 0.99, '%.2f');
      $slider('Gravity', $s->gravity, -1, 3, '%.1f');
      $slider('Drift', $s->drift, -2, 2, '%.1f');
      $slider('Size', $s->scalar, 0.4, 3, '%.1f×');
      $c->spacer();
      if ($c->clicked($c->button('Reset'))) { $s->resetOptions(); }
    $c->end();
};

return function (Context $c) use (&$presetTiles, &$controls): void {
    $t = $c->theme;
    $now = $c->now();
    [$this->w, $this->h] = $c->size();
    $this->dark = $t->dark;
    // While the desktop asks for less motion, nothing flies: the pieces would only show where they land, fading.
    $still = $c->preferences()->reduceMotion;
    foreach ($this->emitters as $k => &$e) {
        for (; $e['i'] < $e['n'] && $now >= $e['next']; $e['i']++, $e['next'] += $e['every']) {
            if (!$still) { ($e['fire'])($e['i'], $e['n']); }
        }
        if ($e['i'] >= $e['n']) { unset($this->emitters[$k]); }
    }
    unset($e);
    $this->emitters = array_values($this->emitters);

    $c->column(24, 20, 1);
      $c->row(0, 12)->alignItems(Node::END);
        $c->column(0, 4, 1);
          $c->text('Confetti', 28, style: Font::BOLD);
          $c->muted('canvas-confetti\'s demos in native UI, every piece moved by poses that only paint.');
        $c->end();
        $c->text($this->inAir() . ' in the air', 12, $t->textMuted);
      $c->end();
      if ($still) { $c->text('Confetti rests while the desktop asks for less motion.', color: $t->warning); }
      $c->row(0, 16, 1)->alignItems(Node::STRETCH);
        $c->column(0, 16, 1);
          $presetTiles($c, $this, $now, $still);
          $stage = $c->key('stage')->column(0, 6, 1)->alignItems(Node::CENTER)->justify(Node::CENTER)->radius(12)->border(1, $t->border)->borderStyle(BorderStyle::Dashed)->cursor(Cursor::Pointer);
            $c->text('Click anywhere here', style: Font::BOLD);
            $c->text('to throw your burst from the pointer', 12, $t->textMuted);
          $c->end();
          // Where the stage was pressed, noted as the press comes, for the click after.
          $stage->handleInput(function (InputEvent $ev) {
              if ($ev->kind === InputEvent::POINTER_DOWN) { [$this->pressX, $this->pressY] = [$ev->x, $ev->y]; }
              return false;
          });
          if ($c->clicked($stage) && !$still) { $this->burst($this->custom(...$c->localToWindow($stage, $this->pressX, $this->pressY))); }
        $c->end();
        $controls($c, $this);
      $c->end();
    $c->end();

    // The pieces fly over everything, from the window's (0, 0), and let the pointer through. Bursts that faded away go.
    $this->bursts = array_values(array_filter($this->bursts, fn(Burst $b) => $b->launched === 0 || ($now - $b->launched) / 1e9 < $b->life));
    $c->key('confetti')->column()->absolute()->top(0)->left(0)->right(0)->bottom(0)->passThrough();
      foreach ($this->bursts as $b) { $b->build($c, $now); }
    $c->end();
    // The view runs again once a burst faded, to let it go, and when a demo throws more.
    $gone = null;
    foreach ($this->bursts as $b) {
        if ($b->launched !== 0) { $d = $b->life - ($now - $b->launched) / 1e9; $gone = $gone === null ? $d : min($gone, $d); }
    }
    if ($gone !== null && $gone > 0) { $c->after($gone); }
    foreach ($this->emitters as $e) { $c->after(($e['next'] - $now) / 1e9); }
};
