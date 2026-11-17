# Charts

`Chart` draws data as Swift Charts does: `Chart($data)(fn($row) => BarMark(x: …, y: …))`, or `Chart()(marks…)`, of `BarMark`, `LineMark`, `AreaMark`, `PointMark`, `RuleMark`, `SectorMark` and `LinePlot` (tens of thousands of points a frame); `->chartXSelection(value:)`, `->chartAngleSelection(value:)`, `->chartYScale(domain:)`, `->chartXAxis()`, `->chartYAxis()`, `->chartForegroundStyleScale()` and `->chartLegend()` follow Swift Charts' modifiers ([Coming from SwiftUI](../swiftui.md)).

```php
$app = new class {
    public array $sales = [
        ['month' => 'Jan', 'n' => 12],
        ['month' => 'Feb', 'n' => 18],
        ['month' => 'Mar', 'n' => 9],
    ];
    public ?string $hovered = null;
};
$view = fn() => Chart($app->sales)(fn(array $s) =>
    BarMark(
        x: PlottableValue::value('Month', $s['month']),
        y: PlottableValue::value('Sales', $s['n']),
    )
        ->cornerRadius(4)
        ->opacity($app->hovered !== null && $app->hovered !== $s['month'] ? 0.4 : 1.0)
)
    ->chartXSelection(value: $app->hovered)
    ->chartYAxis(new AxisMarks(format: fn(float $v) => sprintf('$%.0fk', $v)))
    ->frame(height: 200)
    ->padding();
```

## Marks

- **`BarMark(x:, y:, yStart:, yEnd:, width:)`** — vertical or horizontal bars, or ranged intervals when `yStart` and `yEnd` are given.
- **`LineMark(x:, y:, series:)`** — lines connecting points in series order; use `->interpolationMethod(InterpolationMethod::monotone)` or `catmullRom` for smooth curves, and `->strokeStyle(new StrokeStyle(lineWidth: 2))` for line width.
- **`AreaMark(x:, y:, stacking:)`** — filled areas under a line, stacked (`MarkStackingMethod::standard`), normalized to 100% (`MarkStackingMethod::normalized`), or unstacked (`MarkStackingMethod::unstacked`).
- **`PointMark(x:, y:)`** — scatter plot symbols sized with `->symbolSize()`.
- **`RuleMark(x:, y:)`** — horizontal or vertical reference rules and crosshair indicators.
- **`SectorMark(angle:, innerRadius:, outerRadius:, angularInset:)`** — pie and donut chart sectors, with `MarkDimension::ratio(0.6)` for donut holes and `->chartAngleSelection(value:)` for interactive sector picking.
- **`LinePlot(x:, y:)`** — vectorized high-density streaming lines that downsample per pixel column, drawing tens of thousands of samples at 60 fps (see [`examples/chart-streaming`](../../examples/chart-streaming)).

See [`examples/charts`](../../examples/charts) for a complete gallery of interactive charts.
