<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Loading;

use MyPHP\UI\Transform;

/**
 * Maps (x, y) to (a*x + c*y + e, b*x + d*y + f): where an indicator's
 * parts are, in a viewBox or its own square (mygo's loading affine).
 *
 * @internal Loading
 */
final class Matrix
{
    public function __construct(public float $a = 1, public float $b = 0, public float $c = 0, public float $d = 1, public float $e = 0, public float $f = 0) {}

    /** @return array{0: float, 1: float} the point (x, y) maps to */
    public function apply(float $x, float $y): array
    {
        return [$this->a * $x + $this->c * $y + $this->e, $this->b * $x + $this->d * $y + $this->f];
    }

    /** This after $n: $n maps first. */
    public function then(self $n): self
    {
        return new self(
            $this->a * $n->a + $this->c * $n->b, $this->b * $n->a + $this->d * $n->b,
            $this->a * $n->c + $this->c * $n->d, $this->b * $n->c + $this->d * $n->d,
            $this->a * $n->e + $this->c * $n->f + $this->e, $this->b * $n->e + $this->d * $n->f + $this->f,
        );
    }

    /** Turning what it maps by $deg degrees clockwise around ($cx, $cy), as CSS's rotate(). */
    public function rotate(float $deg, float $cx, float $cy): self
    {
        [$s, $c] = [sin($deg * M_PI / 180), cos($deg * M_PI / 180)];
        return $this->then(new self($c, $s, -$s, $c, $cx - $c * $cx + $s * $cy, $cy - $s * $cx - $c * $cy));
    }

    /** Scaling what it maps by $sx and $sy around ($cx, $cy). */
    public function scale(float $sx, float $sy, float $cx, float $cy): self
    {
        return $this->then(new self($sx, 0, 0, $sy, $cx - $sx * $cx, $cy - $sy * $cy));
    }

    /** Moving what it maps by ($dx, $dy). */
    public function move(float $dx, float $dy): self
    {
        return $this->then(new self(1, 0, 0, 1, $dx, $dy));
    }

    /** How many points a length of 1 is through it, for strokes. */
    public function unit(): float
    {
        return sqrt(abs($this->a * $this->d - $this->b * $this->c));
    }

    /** As a UI\Transform, null if it collapses what it maps: a scaling, a skew along x, a rotation, then a translation. */
    public function transform(): ?Transform
    {
        $sx = hypot($this->a, $this->b);
        $det = $this->a * $this->d - $this->b * $this->c;
        if ($sx == 0 || $det == 0) {
            return null;
        }
        $sy = $det / $sx;
        $t = Transform::scaling($sx, $sy);
        if (($k = ($this->a * $this->c + $this->b * $this->d) / $sx) != 0) {
            $t = $t->then(Transform::skewing(rad2deg(atan($k / $sy)), 0));
        }
        if ($this->b != 0 || $this->a < 0) {
            $t = $t->then(Transform::rotation(rad2deg(atan2($this->b, $this->a))));
        }
        return $t->then(Transform::translation($this->e, $this->f));
    }
}
