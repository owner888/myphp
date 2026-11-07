<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use MyPHP\UI\{Context, Node};

/**
 * A shape, as SwiftUI's: a view that fills the room it is given with its
 * foreground color, or ->fill(), ->stroke(); and what clipShape() clips to.
 */
final class Shape extends View
{
    public const RECTANGLE = 0, ROUNDED = 1, CIRCLE = 2, CAPSULE = 3, ELLIPSE = 4;
    private ?ColorValue $fill = null;
    private ?array $stroke = null;

    public function __construct(public readonly int $kind, public readonly float $cornerRadius = 0) {}

    public function fill(\Color|ColorValue $color): static { $this->fill = ColorValue::of($color); return $this; }

    /** Outlines it $lineWidth wide, or as $style says: dashed, with dashes. */
    public function stroke(\Color|ColorValue|\MyPHP\UI\ConicGradient $color, float $lineWidth = 1, ?StrokeStyle $style = null): static
    {
        $this->stroke = [$color instanceof \MyPHP\UI\ConicGradient ? $color : ColorValue::of($color), $style?->lineWidth ?? $lineWidth, $style !== null && $style->dash !== []]; // a ConicGradient: SwiftUI's AngularGradient
        return $this;
    }

    protected function build(Context $c, Env $env): ?Node
    {
        $color = $this->fill ?? ($this->stroke === null ? ($env->color ?? new ColorValue(\Color::primary)) : null);
        $n = $c->column(0, 0, 1);
        $c->end();
        $n->alignSelf(Node::STRETCH);
        if ($color !== null) { $n->background($color->rgb($env), $color->alpha); }
        if ($this->stroke !== null && $this->stroke[0] instanceof \MyPHP\UI\ConicGradient) { $n->border($this->stroke[1])->borderConicGradient($this->stroke[0]); }
        elseif ($this->stroke !== null && $this->stroke[0]->alpha > 0) { $n->border($this->stroke[1], $this->stroke[0]->rgb($env)); if ($this->stroke[2]) { $n->borderStyle(\MyPHP\UI\BorderStyle::Dashed); } }
        $this->clip($n);
        return $n;
    }

    /** Rounds $n's corners as this shape is. @internal */
    public function clip(Node $n): void
    {
        match ($this->kind) {
            self::ROUNDED => $n->radius($this->cornerRadius),
            self::CIRCLE, self::CAPSULE, self::ELLIPSE => $n->radius(100_000),
            default => null,
        };
        $n->clip();
    }
}
