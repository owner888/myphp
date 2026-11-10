<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use MyPHP\UI\{Context, GradientStop, LinearGradient, Node};

/**
 * LinearGradient, as SwiftUI's: fills the room it is given with a gradient
 * of $colors (evenly spaced) or $stops ([color, location]), from $startPoint
 * to $endPoint (or at $angle degrees clockwise from upwards).
 */
final class LinearGradientView extends View
{
    /** @param list<\Color|ColorValue> $colors @param list<array{0: \Color|ColorValue, 1: float}> $stops */
    public function __construct(
        public readonly array $colors = [],
        public readonly \UnitPoint $startPoint = \UnitPoint::top,
        public readonly \UnitPoint $endPoint = \UnitPoint::bottom,
        public readonly ?float $angle = null,
        public readonly array $stops = [],
    ) {}

    public function toGradient(Env $env): LinearGradient
    {
        $deg = $this->angle ?? self::angleBetween($this->startPoint, $this->endPoint);
        $list = [];
        if ($this->stops !== []) {
            foreach ($this->stops as [$c, $at]) {
                $cv = ColorValue::of($c);
                $list[] = new GradientStop($cv->rgb($env), (float)$at, $cv->alpha);
            }
        } else {
            $n = count($this->colors);
            foreach ($this->colors as $i => $c) {
                $cv = ColorValue::of($c);
                $list[] = new GradientStop($cv->rgb($env), $n > 1 ? $i / ($n - 1) : 0.0, $cv->alpha);
            }
        }
        return new LinearGradient(angle: $deg, stops: $list);
    }

    private static function angleBetween(\UnitPoint $a, \UnitPoint $b): float
    {
        [$x0, $y0] = $a->coords();
        [$x1, $y1] = $b->coords();
        $dx = $x1 - $x0;
        $dy = $y1 - $y0;
        if ($dx == 0.0 && $dy == 0.0) {
            return 180.0;
        }
        $deg = rad2deg(atan2($dx, -$dy));
        return $deg < 0 ? $deg + 360.0 : $deg;
    }

    protected function build(Context $c, Env $env): ?Node
    {
        $n = $c->column(0, 0, 1);
        $c->end();
        $n->alignSelf(Node::STRETCH)->linearGradient($this->toGradient($env));
        return $n;
    }
}