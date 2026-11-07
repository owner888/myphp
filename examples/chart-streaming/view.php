<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

// Three waveforms streamed at 1000 samples a second, 15 seconds of each, a LinePlot apiece, built anew every frame.
return fn() => TimelineView()(function (TimelineViewContext $context) {
    $this->update($context->nanoseconds);
    return Chart()(...array_map(fn(int $k) => LinePlot(x: $this->xs, y: $this->ys[$k])->foregroundStyle(Color::rgb(COLORS[$k])), array_keys(COLORS)))
        ->chartXScale(domain: [-15, 0])->chartYScale(domain: [0, 1])
        ->chartXAxis(new AxisMarks(desiredCount: 5, format: fn(float $v) => sprintf('%gs', $v)))
        ->accessibilityLabel('Three live waveforms')
        ->padding(8);
});
