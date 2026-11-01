<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Loading;

use MyPHP\UI\Path;

/**
 * A path drawn through a transform, in the coordinates of a viewBox or of
 * the indicator's own (mygo's loading shape); its storage kept from frame
 * to frame.
 *
 * @internal Loading
 */
final class Shape
{
    public Path $path;
    public Matrix $m;

    public function __construct()
    {
        $this->path = new Path();
        $this->m = new Matrix();
    }

    public function move(float $x, float $y): self { $this->path->moveTo(...$this->m->apply($x, $y)); return $this; }

    public function line(float $x, float $y): self { $this->path->lineTo(...$this->m->apply($x, $y)); return $this; }

    public function cube(float $x1, float $y1, float $x2, float $y2, float $x, float $y): self
    {
        $this->path->cubeTo(...[...$this->m->apply($x1, $y1), ...$this->m->apply($x2, $y2), ...$this->m->apply($x, $y)]);
        return $this;
    }

    public function close(): self { $this->path->close(); return $this; }

    /**
     * Adds the arc of the circle around ($cx, $cy) of radius $r from angle
     * $a0 to $a1, radians clockwise from the right, as SVG's circles start
     * and go: from its start if $first, or a line to it.
     */
    public function arc(float $cx, float $cy, float $r, float $a0, float $a1, bool $first): self
    {
        [$sx, $sy] = [$cx + $r * cos($a0), $cy + $r * sin($a0)];
        $first ? $this->move($sx, $sy) : $this->line($sx, $sy);
        $n = (int)ceil(abs($a1 - $a0) / (M_PI / 2));
        if ($n === 0) { return $this; }
        $h = ($a1 - $a0) / $n;
        $k = 4.0 / 3 * tan($h / 4);
        for ($i = 0; $i < $n; $i++) {
            [$b0, $b1] = [$a0 + $i * $h, $a0 + ($i + 1) * $h];
            [$s0, $c0, $s1, $c1] = [sin($b0), cos($b0), sin($b1), cos($b1)];
            [$x0, $y0, $x1, $y1] = [$cx + $r * $c0, $cy + $r * $s0, $cx + $r * $c1, $cy + $r * $s1];
            $this->cube($x0 - $k * $r * $s0, $y0 + $k * $r * $c0, $x1 + $k * $r * $s1, $y1 - $k * $r * $c1, $x1, $y1);
        }
        return $this;
    }

    /** Adds a circle, counterclockwise if $ccw, as a hole in one. */
    public function circle(float $cx, float $cy, float $r, bool $ccw): self
    {
        return $this->arc($cx, $cy, $r, 0, $ccw ? -2 * M_PI : 2 * M_PI, true)->close();
    }

    /** Adds the ring between radii $in and $out, as a circle's border. */
    public function ring(float $cx, float $cy, float $out, float $in): self
    {
        $this->circle($cx, $cy, $out, false);
        if ($in > 0) { $this->circle($cx, $cy, $in, true); }
        return $this;
    }

    /** Empties the path, keeping its storage. */
    public function reset(): void
    {
        $this->path->reset();
    }
}
