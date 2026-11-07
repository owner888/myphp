<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use Closure;

/**
 * A mark of a Chart, as Swift Charts' BarMark, LineMark, AreaMark,
 * PointMark, RuleMark and SectorMark: where it goes, each position a
 * PlottableValue (PlottableValue::value('Month', $m)) or a bare value, and
 * how it looks, its modifiers' (foregroundStyle, by a series or a color;
 * cornerRadius, opacity, interpolationMethod, lineStyle, symbolSize,
 * annotation). Chart lays them out on its scales. @internal its kinds are made by the functions BarMark() and the others
 */
final class ChartMark
{
    public const BAR = 'bar', LINE = 'line', AREA = 'area', POINT = 'point', RULE = 'rule', SECTOR = 'sector', PLOT = 'plot';

    /** @var list<float> a LinePlot's x values, and its y values */
    public array $xValues = [], $yValues = [];

    public ?ColorValue $color = null;
    public ?PlottableValue $by = null;
    public float $radius = 0, $alpha = 1.0, $lineWidth = 2.0;
    public ?float $symbolSize = null;
    public ?\InterpolationMethod $interpolation = null;
    /** @var array{0: \AnnotationPosition, 1: Closure}|null */
    public ?array $annotation = null;

    public function __construct(
        public readonly string $kind,
        public readonly ?PlottableValue $x = null,
        public readonly ?PlottableValue $y = null,
        public readonly ?PlottableValue $yStart = null,
        public readonly ?PlottableValue $yEnd = null,
        public readonly ?PlottableValue $series = null,
        public readonly ?\MarkStackingMethod $stacking = null,
        public readonly ?PlottableValue $angle = null,
        public readonly ?MarkDimension $innerRadius = null,
        public readonly ?MarkDimension $outerRadius = null,
        public readonly float $angularInset = 0,
        public readonly ?float $width = null,
    ) {}

    /** Its color, $style; or its series' color, of the value $by, which the chart's legend names, as Swift Charts' foregroundStyle(by:). */
    public function foregroundStyle(\Color|ColorValue|null $style = null, ?PlottableValue $by = null): static
    {
        if ($style !== null) { $this->color = ColorValue::of($style); }
        $this->by = $by ?? $this->by;
        return $this;
    }

    /** Rounds a bar's corners, or a sector's. */
    public function cornerRadius(float $radius): static { $this->radius = $radius; return $this; }

    public function opacity(float $opacity): static { $this->alpha = $opacity; return $this; }

    /** How a line or an area goes from point to point: straight (linear, the default), smoothly (monotone, catmullRom), or in steps. */
    public function interpolationMethod(\InterpolationMethod $method): static { $this->interpolation = $method; return $this; }

    /** The width of a line or a rule, as Swift Charts' lineStyle(StrokeStyle(lineWidth:)). */
    public function lineStyle(StrokeStyle $style): static { $this->lineWidth = $style->lineWidth; return $this; }

    /** A point's size, as the area of its circle in square points. */
    public function symbolSize(float $size): static { $this->symbolSize = $size; return $this; }

    /** The view $content returns, next to the mark on the side $position names: over a bar's top, above a point. */
    public function annotation(Closure $content, \AnnotationPosition $position = \AnnotationPosition::automatic): static { $this->annotation = [$position, $content]; return $this; }
}
