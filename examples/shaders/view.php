<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\UI\Painter;
use MyPHP\UI\Shader\Options;

/** A card of $title, $h tall, $body in it. */
$card = fn(string $title, float $h, View $body) => VStack(alignment: HorizontalAlignment::leading, spacing: 8)(
    Text($title)->font(Font::subheadline)->foregroundStyle(Color::secondary),
    $body->frame(maxWidth: INF, minHeight: $h, maxHeight: $h)->clipShape(RoundedRectangle(cornerRadius: 8)),
)->padding(12)->background(Color::gray->opacity(0.12))->cornerRadius(12);

// Every frame (a TimelineView), the shaders' time in seconds: the waves a Canvas draws with a shader, text and a path
// filled with one, text through a DistortionEffect, and a frosted pane reading what moves behind it.
return fn() => TimelineView()(function (TimelineViewContext $context) use ($card) {
    $t = ($context->nanoseconds - $this->start) / 1e9;
    return ScrollView()(VStack(alignment: HorizontalAlignment::leading, spacing: 16)(
        Text('Shaders')->font(Font::system(size: 22)),
        LazyVGrid(columns: [GridItem::adaptive(minimum: 320)], spacing: 16)(
            $card('draw(): waves, Values set each frame', 160, Canvas(fn(GraphicsContext $g) => $g->withPainter(function (Painter $p, array $r) use ($t): void {
                $this->values->float($this->time, $t)->color($this->tint, $p->theme()->accent);
                $this->waves->draw($p, $r, new Options(values: $this->values));
            }))),
            $card('fill(): text and a path', 160, Canvas(fn(GraphicsContext $g) => $g->withPainter(function (Painter $p, array $r) use ($t): void {
                $o = new Options(uniforms: ['Time' => $t]);
                $this->rainbow->text($p, $r[0] + 130, $r[1] + 40, 'Hello', 44, $o);
                $p->transform(\MyPHP\UI\Transform::translation($r[0], $r[1] + 20), 0, 0, fn() => $this->rainbow->strokePath($p, $this->ring, 10, $o));
            }))),
            $card('distortionEffect(): wavy text', 160, Text('Wavy text')->font(Font::system(size: 34))
                ->distortionEffect($this->wavy, maxSampleOffset: new CGSize(0, 8), uniforms: ['Time' => $t])),
            $card('backdropSample(): a frosted pane', 160, Canvas(fn(GraphicsContext $g) => $g->withPainter(function (Painter $p, array $r) use ($t): void {
                foreach ([0xff5a5a, 0x5ac8ff, 0xffd25a] as $i => $rgb) {
                    $x = $r[0] + 148 + 110 * sin($t + $i * 2.1) - 30;
                    $p->fill([$x, $r[1] + 30 + 40 * $i, 60, 40], $rgb, 10);
                }
                $this->frost->draw($p, [$r[0] + 60, $r[1] + 30, 176, 100], new Options(uniforms: ['Tint' => 0xffffff], radius: 14, backdropBlur: 8));
            }))),
        ),
    )->padding(20));
});
