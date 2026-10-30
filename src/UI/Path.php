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
    /** How a stroke ends where its subpath or a dash of it does (mygo's LineCap): a half disc (the default), square where its line ends, or square half its width past it. */
    public const CAP_ROUND = 0, CAP_BUTT = 1, CAP_SQUARE = 2;
    /** How a stroke turns at a corner (mygo's LineJoin): in an arc (the default), in a point where its sides meet, bevelled past the miter limit, or cut straight across. */
    public const JOIN_ROUND = 0, JOIN_MITER = 1, JOIN_BEVEL = 2;
    /** How the path is stroked (cap(), join(), miterLimit(), dash()): none set strokes it as before, round and whole. */
    public int $cap = self::CAP_ROUND, $join = self::JOIN_ROUND;
    public float $miter = 4.0, $dashOffset = 0.0;
    /** @var list<float> the dashes and gaps, in points, an even count; none for a whole stroke */
    public array $dash = [];
    /** @var ?list<float> @internal where each subpath trim() kept starts on the subpath it was cut from, in points: its dashes stay where they are on the path */
    public ?array $phases = null;

    /** Sets how the stroke ends at the ends of open subpaths and of dashes, as SVG's stroke-linecap (mygo's Cap), and returns the path. */
    public function cap(int $cap): static { $this->cap = $cap; return $this; }

    /** Sets how the stroke turns at the path's corners, as SVG's stroke-linejoin (mygo's Join), and returns the path. */
    public function join(int $join): static { $this->join = $join; return $this; }

    /** Sets how far the point of a miter join may reach from its corner, in half the stroke's width, as SVG's stroke-miterlimit (mygo's MiterLimit): 4 by default, which bevels corners sharper than about 29 degrees, and at least 1. */
    public function miterLimit(float $limit): static
    {
        Motion::finite('miter limit', $limit);
        $this->miter = max($limit, 1.0);
        return $this;
    }

    /**
     * Strokes the path in dashes, as SVG's stroke-dasharray and
     * stroke-dashoffset (mygo's Dash), and returns the path: $lengths are
     * those of the dashes and the gaps between them in turn, in points,
     * repeated along it, twice over when there is an odd number of them;
     * each subpath starts them anew, $offset points into them. A dash of no
     * length shows its caps alone (a dot with CAP_ROUND). No lengths, a
     * negative one, or lengths adding up to none stroke it whole. As
     * trim() trims the path, its dashes stay where they are on it.
     */
    public function dash(float $offset, float ...$lengths): static
    {
        Motion::finite('dash', $offset, ...$lengths);
        [$this->dash, $this->dashOffset] = [[], $offset];
        foreach ($lengths as $l) { if ($l < 0) { return $this; } }
        if (array_sum($lengths) > 0) {
            $this->dash = count($lengths) % 2 === 1 ? [...$lengths, ...$lengths] : array_values($lengths);
        }
        return $this;
    }

    /** How far past its points the stroke $width wide reaches: half the width, past it where square caps and miters reach. */
    public function reach(float $width): float
    {
        $hw = max($width, 0) / 2;
        return $hw * max($this->join === self::JOIN_MITER ? $this->miter : 1.0, $this->cap === self::CAP_SQUARE ? M_SQRT2 : 1.0);
    }

    /** Empties the path and removes its trim, keeping how it is stroked (mygo's Reset), and returns it. */
    public function reset(): static
    {
        $this->cmds = [];
        $this->kept = $this->phases = null;
        return $this;
    }

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
        $this->cmds = []; $phases = []; $was = $this->phases ?? []; // trimmed again: from where each started on the path first trimmed
        $at = 0.0;
        foreach ($polys as $pi => $poly) {
            $open = false; $subStart = $at;
            for ($i = 1; $i < count($poly); $i++) {
                [$x0, $y0] = $poly[$i - 1]; [$x1, $y1] = $poly[$i];
                $l = hypot($x1 - $x0, $y1 - $y0);
                $s0 = max($a, $at); $s1 = min($b, $at + $l);
                if ($l > 0 && $s1 > $s0) {
                    $k0 = ($s0 - $at) / $l; $k1 = ($s1 - $at) / $l;
                    if (!$open) { $this->moveTo($x0 + ($x1 - $x0) * $k0, $y0 + ($y1 - $y0) * $k0); $open = true; $phases[] = ($was[$pi] ?? 0.0) + $s0 - $subStart; }
                    $this->lineTo($x0 + ($x1 - $x0) * $k1, $y0 + ($y1 - $y0) * $k1);
                }
                $at += $l;
            }
        }
        $this->kept = max(0.0, $b - $a);
        $this->phases = $phases;
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
