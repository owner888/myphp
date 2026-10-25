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
    /** How long the part trim() kept is, in points; null for a path not trimmed. */
    public ?float $kept = null;

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

    /**
     * Keeps of the path the part of its length from $from to $to, as
     * fractions of it, its subpaths one after another, and returns the
     * path: 0 to 0.5 keeps its first half, and a check mark draws itself
     * as $to animates from 0 to 1 (mygo's Trim, SwiftUI's trim(from:to:)).
     * Trimmed, closed subpaths are open, and its curves are lines a
     * quarter of a point long at most; a stroke trimmed shorter than its
     * width fades with its length (Painter::strokePath), rather than its
     * round caps showing a dot that comes or goes at once.
     */
    public function trim(float $from, float $to): static
    {
        Motion::finite('trim', $from, $to);
        $from = max(0.0, min($from, 1.0)); $to = max(0.0, min($to, 1.0));
        // The subpaths as polylines.
        $polys = []; $pen = [0.0, 0.0]; $start = [0.0, 0.0];
        $flat = static function (array $p0, array $c1, array $c2, array $p1, bool $cubic) use (&$polys): void {
            $len = hypot($c1[0] - $p0[0], $c1[1] - $p0[1]) + hypot($c2[0] - $c1[0], $c2[1] - $c1[1]) + hypot($p1[0] - $c2[0], $p1[1] - $c2[1]);
            $n = max(1, min(256, (int)ceil($len / 0.25)));
            for ($i = 1; $i <= $n; $i++) {
                $t = $i / $n; $u = 1 - $t;
                $polys[count($polys) - 1][] = $cubic
                    ? [$u * $u * $u * $p0[0] + 3 * $u * $u * $t * $c1[0] + 3 * $u * $t * $t * $c2[0] + $t * $t * $t * $p1[0], $u * $u * $u * $p0[1] + 3 * $u * $u * $t * $c1[1] + 3 * $u * $t * $t * $c2[1] + $t * $t * $t * $p1[1]]
                    : [$u * $u * $p0[0] + 2 * $u * $t * $c1[0] + $t * $t * $p1[0], $u * $u * $p0[1] + 2 * $u * $t * $c1[1] + $t * $t * $p1[1]];
            }
        };
        foreach ($this->cmds as $c) {
            switch ($c[0]) {
                case self::MOVE: $pen = $start = [$c[1], $c[2]]; $polys[] = [$pen]; break;
                case self::LINE: if ($polys === []) { $polys[] = [$pen]; } $pen = [$c[1], $c[2]]; $polys[count($polys) - 1][] = $pen; break;
                case self::QUAD: if ($polys === []) { $polys[] = [$pen]; } $flat($pen, [$c[1], $c[2]], [$c[1], $c[2]], [$c[3], $c[4]], false); $pen = [$c[3], $c[4]]; break;
                case self::CUBE: if ($polys === []) { $polys[] = [$pen]; } $flat($pen, [$c[1], $c[2]], [$c[3], $c[4]], [$c[5], $c[6]], true); $pen = [$c[5], $c[6]]; break;
                case self::CLOSE: if ($polys !== []) { $polys[count($polys) - 1][] = $start; } $pen = $start; break;
            }
        }
        $total = 0.0;
        foreach ($polys as $poly) {
            for ($i = 1; $i < count($poly); $i++) { $total += hypot($poly[$i][0] - $poly[$i - 1][0], $poly[$i][1] - $poly[$i - 1][1]); }
        }
        $a = $from * $total; $b = $to * $total;
        $this->cmds = [];
        $at = 0.0;
        foreach ($polys as $poly) {
            $open = false;
            for ($i = 1; $i < count($poly); $i++) {
                [$x0, $y0] = $poly[$i - 1]; [$x1, $y1] = $poly[$i];
                $l = hypot($x1 - $x0, $y1 - $y0);
                $s0 = max($a, $at); $s1 = min($b, $at + $l);
                if ($l > 0 && $s1 > $s0) {
                    $k0 = ($s0 - $at) / $l; $k1 = ($s1 - $at) / $l;
                    if (!$open) { $this->moveTo($x0 + ($x1 - $x0) * $k0, $y0 + ($y1 - $y0) * $k0); $open = true; }
                    $this->lineTo($x0 + ($x1 - $x0) * $k1, $y0 + ($y1 - $y0) * $k1);
                }
                $at += $l;
            }
        }
        $this->kept = max(0.0, $b - $a);
        return $this;
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
