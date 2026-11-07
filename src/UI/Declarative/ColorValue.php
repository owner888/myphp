<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

/** A color, as SwiftUI's Color: one of the system's (\Color::blue, \Color::primary…), which follow light and dark, or one of the app's (\Color::rgb). */
final class ColorValue
{
    public function __construct(
        /** A system color, or null for $rgb. */
        public readonly ?\Color $system = null,
        public readonly int $value = 0,
        public readonly float $alpha = 1.0,
    ) {}

    public static function of(\Color|self $c): self
    {
        return $c instanceof self ? $c : new self($c, 0, $c === \Color::clear ? 0.0 : 1.0); // clear: none of it shows
    }

    /** This color with $opacity, as SwiftUI's .opacity() of a color. */
    public function opacity(float $opacity): self
    {
        return new self($this->system, $this->value, $this->alpha * $opacity);
    }

    /** The color as 0xRRGGBB in $env's theme, light or dark. @internal */
    public function rgb(Env $env): int
    {
        return $this->system === null ? $this->value : $this->system->resolve($env->theme);
    }
}
