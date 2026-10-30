<?php
declare(strict_types=1);

namespace MyPHP\UI;

/**
 * Bands (mygo's gpu/bands.go). A ring, a border-only box, writes nothing
 * where the coverages of its outer and inner edges are both 1, nor
 * outside its outer edge, and a box shadow nothing inside the box casting
 * it; yet the shaders evaluate the shape in every pixel of its quad, so a
 * circle a pixel wide shades its whole box. Such a shape is cut into bands
 * of rows instead, each drawing only the columns between where its outer
 * edge may reach and where its inner one certainly covers, on either side:
 * DisplayList draws its quad once a piece, each clipped to its piece, and
 * the vertex shader draws a quad only within its clip, so what is shaded
 * grows with the ring's length, not its area. The bands of the rows across
 * its corners are as many as pay for the quads they add (COST); the
 * pixels are the same either way, as the pieces never overlap and hold
 * every pixel the shaders may draw.
 *
 * In points, as the display list; corners are circular, of radii (top
 * left, top right, bottom right, bottom left) fitted to the rect.
 *
 * @internal DisplayList
 */
final class Bands
{
    /** What a piece of its own costs, in square points shaded: mygo's 4096 pixels at a scale of 2. */
    public const COST = 1024;
    /** How far, in points, from the edge of a shape a point is taken to be covered by 0 or 1, beyond the half pixel antialiasing reaches. */
    private const MARGIN = 2.0;
    /** The fewest rows a band across corners has. */
    private const MIN_ROWS = 8;

    /**
     * The columns that rounded rect $s, grown by $t (shrunk when negative),
     * covers at row $y, or null for none (mygo's rounded.span).
     * @param array{0: array{0: float, 1: float, 2: float, 3: float}, 1: array{0: float, 1: float, 2: float, 3: float}} $s the rect (x, y, w, h) and its radii
     * @return ?array{0: float, 1: float}
     */
    public static function span(array $s, float $y, float $t): ?array
    {
        [[$x, $sy, $w, $h], $radii] = $s;
        [$x0, $y0, $x1, $y1] = [$x - $t, $sy - $t, $x + $w + $t, $sy + $h + $t];
        if ($y < $y0 || $y > $y1 || $x1 <= $x0) { return null; }
        $top = $y < $sy + $h / 2;
        [$left, $right] = $top ? [$radii[0], $radii[1]] : [$radii[3], $radii[2]];
        $dy = $top ? $y - $y0 : $y1 - $y; // from the nearer of the top and the bottom
        $in = function (float $radius) use ($t, $dy): float {
            $rho = max($radius + $t, 0.0); // the corner's radius grown by t
            if ($dy >= $rho) { return 0.0; }
            $d = $rho - $dy;
            return $rho - sqrt(max($rho * $rho - $d * $d, 0.0));
        };
        [$l, $r] = [$x0 + $in($left), $x1 - $in($right)];
        return $l < $r ? [$l, $r] : null;
    }

    /** The columns the shape, grown by $t, covers at some row between $y0 and $y1: those at the row nearest its middle, the widest of a convex shape. */
    private static function widest(array $s, float $y0, float $y1, float $t): ?array
    {
        return self::span($s, min(max($s[0][1] + $s[0][3] / 2, $y0), $y1), $t);
    }

    /** The columns the shape, shrunk by $t, covers at every row between $y0 and $y1: those it covers at both, as it is convex. */
    private static function narrowest(array $s, float $y0, float $y1, float $t): ?array
    {
        $a = self::span($s, $y0, -$t); $b = self::span($s, $y1, -$t);
        if ($a === null || $b === null) { return null; }
        [$l, $r] = [max($a[0], $b[0]), min($a[1], $b[1])];
        return $l < $r ? [$l, $r] : null;
    }

    /** The area of rect [x0, y0, x1, y1], 0 for an empty one. */
    private static function area(array $r): float
    {
        return $r[2] > $r[0] && $r[3] > $r[1] ? ($r[2] - $r[0]) * ($r[3] - $r[1]) : 0.0;
    }

    /** @param list<array{0: float, 1: float, 2: float, 3: float}> $pieces */
    private static function cost(array $pieces): float
    {
        $c = 0.0;
        foreach ($pieces as $p) { $c += self::area($p) + self::COST; }
        return $c;
    }

    /**
     * The pieces, [x0, y0, x1, y1] in points, of quad $whole of a shape that
     * writes nothing within all of $holes, shrunk by MARGIN (where they all
     * cover by 1), nor, unless $outer is null, outside $outer grown by it;
     * null when drawing it whole costs less (mygo's planBands).
     * @param array{0: float, 1: float, 2: float, 3: float} $whole
     * @param ?array{0: array{0: float, 1: float, 2: float, 3: float}, 1: array{0: float, 1: float, 2: float, 3: float}} $outer
     * @param list<array{0: array{0: float, 1: float, 2: float, 3: float}, 1: array{0: float, 1: float, 2: float, 3: float}}> $holes
     * @return ?list<array{0: float, 1: float, 2: float, 3: float}>
     */
    public static function plan(array $whole, ?array $outer, array $holes): ?array
    {
        $m = self::MARGIN;
        $whole = [floor($whole[0]), floor($whole[1]), ceil($whole[2]), ceil($whole[3])];
        if (self::area($whole) <= self::COST || $holes === []) { return null; } // two pieces cost more than it
        [$top, $bottom] = [$whole[1], $whole[3]];
        if ($outer !== null) {
            $top = max($top, floor($outer[0][1] - $m));
            $bottom = min($bottom, ceil($outer[0][1] + $outer[0][3] + $m));
        }
        // The rows where the holes may hide columns, and the rows across their corners, at the top and the bottom.
        [$hTop, $hBottom, $rTop, $rBottom] = [-INF, INF, 0.0, 0.0];
        $hole = $whole;
        foreach ($holes as [$r, $radii]) {
            $hTop = max($hTop, ceil($r[1] + $m));
            $hBottom = min($hBottom, floor($r[1] + $r[3] - $m));
            $rTop = max($rTop, $radii[0], $radii[1]);
            $rBottom = max($rBottom, $radii[2], $radii[3]);
            $hole = [max($hole[0], ceil($r[0] + $m)), max($hole[1], ceil($r[1] + $m)), min($hole[2], floor($r[0] + $r[2] - $m)), min($hole[3], floor($r[1] + $r[3] - $m))];
        }
        if ($top >= $bottom) { return []; } // nothing to draw
        // What bands could leave out at most: the holes' rect, and what lies outside outer's, near its corners.
        $saved = self::area($hole);
        if ($outer !== null) {
            $o = $outer[0];
            $in = [max($whole[0], floor($o[0] - $m)), max($whole[1], floor($o[1] - $m)), min($whole[2], ceil($o[0] + $o[2] + $m)), min($whole[3], ceil($o[1] + $o[3] + $m))];
            $saved += self::area($whole) - self::area($in);
            foreach ($outer[1] as $r) { $saved += ($r + $m) * ($r + $m); }
        }
        if ($saved <= 2 * self::COST) { return null; }
        $mTop = $hTop + ceil($rTop); $mBottom = $hBottom - ceil($rBottom);
        if ($mTop > $mBottom) { $mTop = $mBottom = floor(($mTop + $mBottom) / 2); }
        $clamp = fn(float $y): float => min(max($y, $top), $bottom);
        [$hTop, $hBottom, $mTop, $mBottom] = [$clamp($hTop), $clamp($hBottom), $clamp($mTop), $clamp($mBottom)];
        $plan = function (int $k) use ($whole, $outer, $holes, $m, $top, $bottom, $hTop, $hBottom, $mTop, $mBottom): array {
            $out = [];
            $add = function (float $y0, float $y1) use (&$out, $whole, $outer, $holes, $m, $hTop, $hBottom): void {
                if ($y0 >= $y1) { return; }
                [$left, $right] = [$whole[0], $whole[2]];
                if ($outer !== null) {
                    $w = self::widest($outer, $y0, $y1, $m);
                    if ($w === null) { return; }
                    [$left, $right] = [max($left, floor($w[0])), min($right, ceil($w[1]))];
                }
                [$holeL, $holeR] = [0.0, 0.0];
                if ($y0 >= $hTop && $y1 <= $hBottom) {
                    [$hl, $hr, $ok] = [-INF, INF, true];
                    foreach ($holes as $h) {
                        $n = self::narrowest($h, $y0, $y1, $m);
                        if ($n === null) { $ok = false; break; }
                        [$hl, $hr] = [max($hl, $n[0]), min($hr, $n[1])];
                    }
                    if ($ok) { [$holeL, $holeR] = [max(ceil($hl), $left), min(floor($hr), $right)]; }
                }
                if ($left >= $right) { return; }
                if ($holeL >= $holeR) {
                    $out[] = [$left, $y0, $right, $y1];
                } else {
                    if ($holeL > $left) { $out[] = [$left, $y0, $holeL, $y1]; }
                    if ($right > $holeR) { $out[] = [$holeR, $y0, $right, $y1]; }
                }
            };
            if ($hTop >= $hBottom) {
                $add($top, $bottom);
                return $out;
            }
            $add($top, $hTop);
            for ($i = 0; $i < $k; $i++) { $add($hTop + floor(($mTop - $hTop) * $i / $k), $hTop + floor(($mTop - $hTop) * ($i + 1) / $k)); }
            $add($mTop, $mBottom);
            for ($i = 0; $i < $k; $i++) { $add($mBottom + floor(($hBottom - $mBottom) * $i / $k), $mBottom + floor(($hBottom - $mBottom) * ($i + 1) / $k)); }
            $add($hBottom, $bottom);
            return $out;
        };
        // As many bands across the corners as pay for their pieces: twice as many while that costs less.
        [$best, $found, $last] = [self::area($whole) + self::COST, null, INF];
        $rows = max($mTop - $hTop, $hBottom - $mBottom);
        for ($k = 1; $k <= 32 && ($k === 1 || $rows / $k >= self::MIN_ROWS); $k *= 2) {
            $try = $plan($k);
            $c = self::cost($try);
            if ($c < $best) { [$best, $found] = [$c, $try]; }
            if ($c >= $last) { break; }
            $last = $c;
        }
        return $found;
    }
}
