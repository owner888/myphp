<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\UI\{Context, Node, Painter};
use MyPHP\UI\Shader\Options;

/** A card of $title, $h tall, what $body builds in it. */
$card = function (Context $c, string $title, float $h, Closure $body): void {
    $c->column(12, 8)->size(320, 0)->background($c->theme->surface)->radius(12);
      $c->text($title, 13, $c->theme->textMuted);
      $c->column()->size(296, $h)->radius(8)->clip();
        $body();
      $c->end();
    $c->end();
};

return function (Context $c) use (&$card): void {
    $t = ($c->now() - $this->start) / 1e9;
    $c->animationFrame();
    $c->column(20, 16, 1, $c->theme->background)->fill();
      $c->text('Shaders', 22);
      $c->row(0, 16)->wrap($c->width - 40);
        $card($c, 'draw(): waves, Values set each frame', 160, function () use ($c, $t): void {
            $c->column()->fill()->draw(function (Painter $p, array $r) use ($t): void {
                $this->values->float($this->time, $t)->color($this->tint, $p->theme()->accent);
                $this->waves->draw($p, $r, new Options(values: $this->values));
            });
            $c->end();
        });
        $card($c, 'fill(): text and a path', 160, function () use ($c, $t): void {
            $c->column()->fill()->draw(function (Painter $p, array $r) use ($t): void {
                $o = new Options(uniforms: ['Time' => $t]);
                $this->rainbow->text($p, $r[0] + 130, $r[1] + 40, 'Hello', 44, $o);
                $p->transform(\MyPHP\UI\Transform::translation($r[0], $r[1] + 20), 0, 0, fn() => $this->rainbow->strokePath($p, $this->ring, 10, $o));
            });
            $c->end();
        });
        $card($c, 'effect(): a DistortionEffect', 160, function () use ($c, $t): void {
            $c->column(0, 0, 1)->fill()->alignItems(Node::CENTER)->justify(Node::CENTER);
              $c->text('Wavy text', 34)->effect($this->wavy->effect(new Options(uniforms: ['Time' => $t], maxSampleOffset: [0, 8])));
            $c->end();
        });
        $card($c, 'backdropSample(): a frosted pane', 160, function () use ($c, $t): void {
            $c->column()->fill()->draw(function (Painter $p, array $r) use ($t): void {
                foreach ([0xff5a5a, 0x5ac8ff, 0xffd25a] as $i => $rgb) {
                    $x = $r[0] + 148 + 110 * sin($t + $i * 2.1) - 30;
                    $p->fill([$x, $r[1] + 30 + 40 * $i, 60, 40], $rgb, 10);
                }
                $this->frost->draw($p, [$r[0] + 60, $r[1] + 30, 176, 100], new Options(uniforms: ['Tint' => 0xffffff], radius: 14, backdropBlur: 8));
            });
            $c->end();
        });
      $c->end();
    $c->end();
};
