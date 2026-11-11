<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use MyPHP\UI\{Context, GradientStop, Node, RadialGradient};

/** RadialGradient, as SwiftUI's: fills its room with a radial gradient from $center out to $endRadius. */
final class RadialGradientView extends View
{
    /** @param list<\Color|ColorValue> $colors @param list<array{0: \Color|ColorValue, 1: float}> $stops */
    public function __construct(
        public readonly array $colors = [],
        public readonly \UnitPoint $center = \UnitPoint::center,
        public readonly float $startRadius = 0,
        public readonly float $endRadius = 0,
        public readonly array $stops = [],
    ) {}

    public function toGradient(Env $env): RadialGradient
    {
        [$cx, $cy] = $this->center->coords();
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
        return new RadialGradient(centerX: $cx, centerY: $cy, radius: $this->endRadius, stops: $list);
    }

    protected function build(Context $c, Env $env): ?Node
    {
        $n = $c->column(0, 0, 1);
        $c->end();
        $n->alignSelf(Node::STRETCH);
        $n->gradient = $this->toGradient($env);
        return $n;
    }
}