<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use Closure;
use MyPHP\UI\{Painter, Path};

/**
 * What a Canvas draws in, as SwiftUI's GraphicsContext, its origin the
 * canvas's top left: paths filled and stroked with a color, at the
 * context's opacity; withPainter() hands myphp's Painter over for what it
 * has more of (images, text, gradients, shaders), in the canvas's box, as
 * SwiftUI's withCGContext hands over Core Graphics.
 */
final class GraphicsContext
{
    /** How opaque what it draws is, as SwiftUI's opacity. */
    public float $opacity = 1.0;

    public readonly float $width, $height;

    /** @internal Canvas makes it, of its box $r in the window @param array{0: float, 1: float, 2: float, 3: float} $r */
    public function __construct(private readonly Painter $painter, private readonly Env $env, private readonly array $r)
    {
        [$this->width, $this->height] = [$r[2], $r[3]];
    }

    /** Runs $draw with the painter's origin at the canvas's top left. */
    private function local(\Closure $draw): void
    {
        $this->painter->transform(\MyPHP\UI\Transform::translation($this->r[0], $this->r[1]), 0, 0, $draw);
    }

    /** Fills $path with $with, a color. */
    public function fill(Path $path, \Color|ColorValue $with): void
    {
        $c = ColorValue::of($with);
        $this->local(fn() => $this->painter->fillPath($path, $c->rgb($this->env), $c->alpha * $this->opacity));
    }

    /** Strokes $path $lineWidth points wide with $with, a color. */
    public function stroke(Path $path, \Color|ColorValue $with, float $lineWidth = 1): void
    {
        $c = ColorValue::of($with);
        $this->local(fn() => $this->painter->strokePath($path, $lineWidth, $c->rgb($this->env), $c->alpha * $this->opacity));
    }

    /** Draws what $draw(Painter $p, array $box) paints, $box the canvas's in the window, as the engine's draw functions get theirs. */
    public function withPainter(Closure $draw): void
    {
        $draw($this->painter, $this->r);
    }
}
