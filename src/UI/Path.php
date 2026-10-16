<?php
declare(strict_types=1);

namespace MyPHP\UI;

/**
 * A path of lines and curves in points, as mygo's Path: moveTo, lineTo,
 * quadTo, cubeTo, close and circle build it, DisplayList::path fills or
 * strokes it. Points are absolute, in the window.
 */
final class Path
{
    public const MOVE = 0, LINE = 1, QUAD = 2, CUBE = 3, CLOSE = 4;
    /** @var list<array{0: int, 1: float, 2: float, 3: float, 4: float, 5: float, 6: float}> the commands: kind, then up to three points */
    public array $cmds = [];

    public function moveTo(float $x, float $y): static { $this->cmds[] = [self::MOVE, $x, $y, 0, 0, 0, 0]; return $this; }
    public function lineTo(float $x, float $y): static { $this->cmds[] = [self::LINE, $x, $y, 0, 0, 0, 0]; return $this; }
    public function quadTo(float $cx, float $cy, float $x, float $y): static { $this->cmds[] = [self::QUAD, $cx, $cy, $x, $y, 0, 0]; return $this; }
    public function cubeTo(float $c1x, float $c1y, float $c2x, float $c2y, float $x, float $y): static { $this->cmds[] = [self::CUBE, $c1x, $c1y, $c2x, $c2y, $x, $y]; return $this; }
    public function close(): static { $this->cmds[] = [self::CLOSE, 0, 0, 0, 0, 0, 0]; return $this; }

    /** A circle of radius $r around ($cx, $cy), as mygo's: four cubic arcs. */
    public function circle(float $cx, float $cy, float $r): static
    {
        $k = 0.5522847498 * $r;
        return $this->moveTo($cx + $r, $cy)
            ->cubeTo($cx + $r, $cy + $k, $cx + $k, $cy + $r, $cx, $cy + $r)
            ->cubeTo($cx - $k, $cy + $r, $cx - $r, $cy + $k, $cx - $r, $cy)
            ->cubeTo($cx - $r, $cy - $k, $cx - $k, $cy - $r, $cx, $cy - $r)
            ->cubeTo($cx + $k, $cy - $r, $cx + $r, $cy - $k, $cx + $r, $cy)
            ->close();
    }

    /** The bounds of the control points: x0, y0, x1, y1 (what the curves cover lies within). */
    public function bounds(): ?array
    {
        $x0 = INF; $y0 = INF; $x1 = -INF; $y1 = -INF;
        foreach ($this->cmds as $c) {
            $n = match ($c[0]) { self::MOVE, self::LINE => 1, self::QUAD => 2, self::CUBE => 3, default => 0 };
            for ($i = 0; $i < $n; $i++) {
                $x = $c[1 + 2 * $i]; $y = $c[2 + 2 * $i];
                $x0 = min($x0, $x); $y0 = min($y0, $y); $x1 = max($x1, $x); $y1 = max($y1, $y);
            }
        }
        return $x0 === INF ? null : [$x0, $y0, $x1, $y1];
    }
}
