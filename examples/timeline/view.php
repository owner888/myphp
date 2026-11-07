<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

$intro = Intro::timeline();

// One playhead for the whole intro: a TimelineView moves it every frame while it plays, the card shows the timeline's value there.
return fn() => TimelineView(paused: $this->paused || $this->held || ($this->reverse ? $this->time <= 0 : $this->time >= $intro->duration()))(function (TimelineViewContext $context) use ($intro) {
    $this->advance($context->nanoseconds, $intro->duration());
    $v = $intro->value(time: $this->time);
    $scrub = new Binding(get: fn() => $this->time / $intro->duration(), set: fn(float $f) => $this->time = $f * $intro->duration());
    return VStack(spacing: 18)(
        VStack(alignment: HorizontalAlignment::leading, spacing: 10)(
            Text('A timeline')->font(Font::title2)->bold()->opacity($v['fade'])->offset(y: (1 - $v['fade']) * 12),
            Each(['Plan', 'Build', 'Ship'])(fn(string $row) => Text($row)->foregroundStyle(Color::secondary)->opacity($v[$row])->offset(y: (1 - $v[$row]) * 14)),
        )
            ->padding(22)->frame(width: 300, alignment: Alignment::leading)->background(Color::gray->opacity(0.12))->cornerRadius(16)
            ->scaleEffect($v['scale'])->offset(x: $v['x'], y: $v['y']),
        HStack(spacing: 8)(
            Button('Restart', action: function () { [$this->time, $this->reverse, $this->paused] = [0.0, false, false]; })->buttonStyle(ButtonStyle::borderedProminent),
            Button('Pause', action: fn() => $this->paused = true)->disabled($this->paused),
            Button('Play', action: fn() => $this->paused = false)->disabled(!$this->paused),
            Button('Reverse', action: fn() => $this->reverse = !$this->reverse),
        ),
        HStack(spacing: 10)(
            Slider(value: $scrub, onEditingChanged: fn(bool $editing) => $this->held = $editing),
            Text(verbatim: sprintf('%d / %d ms', round($this->time * 1000), round($intro->duration() * 1000)))->font(Font::caption)->foregroundStyle(Color::secondary)->frame(width: 96, alignment: Alignment::leading),
        )->frame(width: 360),
    )->frame(maxWidth: INF, maxHeight: INF);
});
