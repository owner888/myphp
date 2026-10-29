<?php
declare(strict_types=1);

namespace MyPHP\UI\Svg;

/**
 * Outlines polylines as polygons that all turn the same way, so that the
 * non-zero rule fills their union: a quadrilateral per segment, and the
 * shapes of the joins and caps (mygo's internal/svg stroker).
 */
final class Stroker
{
    public float $hw = 0.5; // half the width
    public int $join = Style::JOIN_MITER, $cap = Style::CAP_BUTT;
    public float $miter = 4; // the miter limit
    public float $tol = 0.1; // how far arcs may stray from circles
    public Polys $out;

    public function __construct() { $this->out = new Polys; }

    public function stroke(Polys $in): void
    {
        for ($i = 0, $n = $in->count(); $i < $n; $i++) {
            $closed = $in->closed[$i];
            [$from, $to] = $in->range($i);
            $pts = [];
            for ($k = $from; $k < $to; $k += 2) {
                $x = $in->pts[$k]; $y = $in->pts[$k + 1];
                if ($pts === [] || $x !== $pts[count($pts) - 2] || $y !== $pts[count($pts) - 1]) { $pts[] = $x; $pts[] = $y; }
            }
            if ($closed && count($pts) > 2 && $pts[0] === $pts[count($pts) - 2] && $pts[1] === $pts[count($pts) - 1]) { array_splice($pts, -2); }
            $cnt = intdiv(count($pts), 2);
            if ($cnt === 1) { $this->dot($pts[0], $pts[1]); continue; }
            $segs = $closed ? $cnt : $cnt - 1;
            for ($k = 0; $k < $segs; $k++) { $j = ($k + 1) % $cnt; $this->segment($pts[2 * $k], $pts[2 * $k + 1], $pts[2 * $j], $pts[2 * $j + 1]); }
            if ($closed) {
                for ($k = 0; $k < $cnt; $k++) { $a = ($k + $cnt - 1) % $cnt; $b = ($k + 1) % $cnt; $this->joinAt($pts[2 * $a], $pts[2 * $a + 1], $pts[2 * $k], $pts[2 * $k + 1], $pts[2 * $b], $pts[2 * $b + 1]); }
                continue;
            }
            for ($k = 1; $k < $cnt - 1; $k++) { $this->joinAt($pts[2 * $k - 2], $pts[2 * $k - 1], $pts[2 * $k], $pts[2 * $k + 1], $pts[2 * $k + 2], $pts[2 * $k + 3]); }
            $this->capAt($pts[0], $pts[1], $pts[2], $pts[3]);
            $this->capAt($pts[2 * $cnt - 2], $pts[2 * $cnt - 1], $pts[2 * $cnt - 4], $pts[2 * $cnt - 3]);
        }
    }

    /** Whether the outline has all the points it may have. */
    private function full(): bool { return count($this->out->pts) >= 4 * Path::MAX_POINTS; }

    /** Adds a polygon of the points (x, y, x, y…) to the outline, turned the way they all turn. */
    private function polygon(float ...$pts): void
    {
        if ($this->full()) { return; }
        $o = $this->out;
        $o->start($pts[0], $pts[1]);
        for ($i = 2, $n = count($pts); $i < $n; $i += 2) { $o->add($pts[$i], $pts[$i + 1]); }
        $this->finish();
    }

    /** Ends the polygon being built, reversing it if it turns the other way. */
    private function finish(): void
    {
        $o = $this->out; $begin = $o->begin;
        $o->end(true);
        if ($o->starts === [] || end($o->starts) !== $begin) { return; }
        $pts = &$o->pts; $n = count($pts);
        $area = 0.0; $ax = $pts[$n - 2]; $ay = $pts[$n - 1];
        for ($i = $begin; $i < $n; $i += 2) { $bx = $pts[$i]; $by = $pts[$i + 1]; $area += $ax * $by - $bx * $ay; $ax = $bx; $ay = $by; }
        if ($area < 0) {
            for ($i = $begin, $j = $n - 2; $i < $j; $i += 2, $j -= 2) { [$pts[$i], $pts[$i + 1], $pts[$j], $pts[$j + 1]] = [$pts[$j], $pts[$j + 1], $pts[$i], $pts[$i + 1]]; }
        }
    }

    private function segment(float $ax, float $ay, float $bx, float $by): void
    {
        $l = hypot($bx - $ax, $by - $ay); $dx = ($bx - $ax) / $l; $dy = ($by - $ay) / $l;
        $nx = -$dy * $this->hw; $ny = $dx * $this->hw;
        $this->polygon($ax + $nx, $ay + $ny, $bx + $nx, $by + $ny, $bx - $nx, $by - $ny, $ax - $nx, $ay - $ny);
    }

    /** Joins the segments a→p and p→b on the outer side of the turn. */
    private function joinAt(float $ax, float $ay, float $px, float $py, float $bx, float $by): void
    {
        $l0 = hypot($px - $ax, $py - $ay); $d0x = ($px - $ax) / $l0; $d0y = ($py - $ay) / $l0;
        $l1 = hypot($bx - $px, $by - $py); $d1x = ($bx - $px) / $l1; $d1y = ($by - $py) / $l1;
        $cross = $d0x * $d1y - $d0y * $d1x; $dot = $d0x * $d1x + $d0y * $d1y;
        if (abs($cross) < 1e-12 && $dot > 0) { return; } // straight on
        $side = $cross > 0 ? -$this->hw : $this->hw;
        $n0x = -$d0y * $side; $n0y = $d0x * $side; $n1x = -$d1y * $side; $n1y = $d1x * $side;
        $o0x = $px + $n0x; $o0y = $py + $n0y; $o1x = $px + $n1x; $o1y = $py + $n1y;
        if ($this->join === Style::JOIN_ROUND) {
            $turn = atan2(abs($cross), $dot);
            if ($this->hw * (1 - cos($turn / 2)) < $this->tol) { $this->polygon($px, $py, $o0x, $o0y, $o1x, $o1y); return; }
            $this->wedge($px, $py, $n0x, $n0y, atan2($n0x * $n1y - $n0y * $n1x, $n0x * $n1x + $n0y * $n1y));
        } elseif ($this->join === Style::JOIN_BEVEL) {
            $this->polygon($px, $py, $o0x, $o0y, $o1x, $o1y);
        } else {
            $cosHalf = sqrt(max((1 + $dot) / 2, 0)); // the miter's length over the stroke's width is 1/cos(turn/2)
            if ($cosHalf * $this->miter < 1) { $this->polygon($px, $py, $o0x, $o0y, $o1x, $o1y); return; }
            $ux = $n0x + $n1x; $uy = $n0y + $n1y; $ul = hypot($ux, $uy);
            $l = $this->hw / $cosHalf;
            $this->polygon($px, $py, $o0x, $o0y, $px + $ux / $ul * $l, $py + $uy / $ul * $l, $o1x, $o1y);
        }
    }

    /** Caps the end p of a polyline whose last segment comes from prev. */
    private function capAt(float $px, float $py, float $prevX, float $prevY): void
    {
        $l = hypot($px - $prevX, $py - $prevY); $dx = ($px - $prevX) / $l; $dy = ($py - $prevY) / $l;
        $nx = $dy * $this->hw; $ny = -$dx * $this->hw;
        if ($this->cap === Style::CAP_ROUND) { $this->wedge($px, $py, $nx, $ny, M_PI); } // half a turn from n, through the direction d
        elseif ($this->cap === Style::CAP_SQUARE) {
            $ex = $dx * $this->hw; $ey = $dy * $this->hw;
            $this->polygon($px + $nx, $py + $ny, $px + $nx + $ex, $py + $ny + $ey, $px - $nx + $ex, $py - $ny + $ey, $px - $nx, $py - $ny);
        }
    }

    /** A subpath of no length: a disc with round caps, a square with square caps. */
    private function dot(float $px, float $py): void
    {
        if ($this->cap === Style::CAP_ROUND) { $this->wedge($px, $py, $this->hw, 0, 2 * M_PI); }
        elseif ($this->cap === Style::CAP_SQUARE) { $h = $this->hw; $this->polygon($px - $h, $py - $h, $px + $h, $py - $h, $px + $h, $py + $h, $px - $h, $py + $h); }
    }

    /** The slice of the disc around c of radius hw from the vector from, sweeping $sweep radians. */
    private function wedge(float $cx, float $cy, float $fx, float $fy, float $sweep): void
    {
        if ($this->full()) { return; }
        $step = 2 * acos(max(1 - $this->tol / $this->hw, -1));
        $n = $step > 0 ? max(1, min((int)ceil(abs($sweep) / $step), 256)) : 1;
        $a0 = atan2($fy, $fx);
        $o = $this->out;
        $full = abs($sweep) >= 2 * M_PI;
        if ($full) { $n = max($n, 8); }
        // Points between the ends lie a little outside the circle, so that the polygon covers as much as the slice of the disc does.
        $a = abs($sweep) / $n;
        $r = $this->hw * sqrt($a / sin($a));
        if ($full) { $o->start($cx + $fx * $r / $this->hw, $cy + $fy * $r / $this->hw); }
        else { $o->start($cx, $cy); $o->add($cx + $fx, $cy + $fy); }
        for ($k = 1; $k < $n; $k++) { $t = $a0 + $sweep * $k / $n; $o->add($cx + cos($t) * $r, $cy + sin($t) * $r); }
        if (!$full) { $o->add($cx + cos($a0 + $sweep) * $this->hw, $cy + sin($a0 + $sweep) * $this->hw); }
        $this->finish();
    }

    /**
     * Cuts the polylines of $in into the dashes of a dash array (of even
     * length), starting $offset into it, as open polylines in $out; $limit
     * bounds the number of dashes. $phases, when given, moves each
     * polyline's start further into the pattern (where a trimmed one began
     * on its subpath).
     * @param ?list<float> $phases
     */
    public static function dash(Polys $in, array $dashes, float $offset, Polys $out, int $limit, ?array $phases = null): void
    {
        $total = array_sum($dashes); $nd = count($dashes);
        for ($i = 0, $n = $in->count(); $i < $n; $i++) {
            [$from, $to] = $in->range($i); $pts = $in->pts;
            // Every subpath starts the pattern anew, offset into it: in dash or gap k, with left of it to go.
            $o = fmod($offset + ($phases[$i] ?? 0.0), $total); if ($o < 0) { $o += $total; }
            $k = 0;
            while ($o > $dashes[$k]) { $o -= $dashes[$k]; $k = ($k + 1) % $nd; }
            $left = $dashes[$k] - $o;
            $on = $k % 2 === 0;
            $first = $on && $in->closed[$i] ? $out->count() : -1; // the dash the subpath starts in, which a closed one's last dash goes on into
            if ($on) { $out->start($pts[$from], $pts[$from + 1]); }
            $cnt = intdiv($to - $from, 2);
            $segs = $in->closed[$i] ? $cnt : $cnt - 1;
            for ($sg = 0; $sg < $segs; $sg++) {
                $ax = $pts[$from + 2 * $sg]; $ay = $pts[$from + 2 * $sg + 1];
                $j = ($sg + 1) % $cnt; $bx = $pts[$from + 2 * $j]; $by = $pts[$from + 2 * $j + 1];
                $l = hypot($bx - $ax, $by - $ay); $pos = 0.0;
                while ($l - $pos > $left) {
                    $pos += $left; $t = $pos / $l;
                    $x = $ax + ($bx - $ax) * $t; $y = $ay + ($by - $ay) * $t;
                    if ($on) {
                        $out->add($x, $y); $out->end(false);
                        if (--$limit <= 0) { self::rest($in, $i, $sg + 1, $x, $y, $out); return; }
                    } else {
                        $out->start($x, $y);
                    }
                    $on = !$on; $k = ($k + 1) % $nd; $left = $dashes[$k];
                }
                $left -= $l - $pos;
                if ($on) { $out->add($bx, $by); }
            }
            if ($on && $first >= 0 && $out->count() > $first) { // a dash through the start of a closed subpath is one, turning there as at its other corners (as Skia's)
                [$f0, $f1] = $out->range($first);
                $run = array_slice($out->pts, $f0 + 2, $f1 - $f0 - 2);
                array_splice($out->pts, $f0, $f1 - $f0);
                array_splice($out->starts, $first, 1); array_splice($out->closed, $first, 1);
                for ($q = $first, $nq = count($out->starts); $q < $nq; $q++) { $out->starts[$q] -= $f1 - $f0; }
                $out->begin -= $f1 - $f0;
                array_push($out->pts, ...$run);
            }
            $out->end(false);
        }
    }

    /** Past the limit of dashes: the rest of $in whole, from (x, y) on segment $sg of polyline $i. */
    private static function rest(Polys $in, int $i, int $sg, float $x, float $y, Polys $out): void
    {
        [$from, $to] = $in->range($i); $pts = $in->pts;
        $cnt = intdiv($to - $from, 2);
        $out->start($x, $y);
        for ($j = $sg, $end = $in->closed[$i] ? $cnt : $cnt - 1; $j <= $end; $j++) { $q = $from + 2 * ($j % $cnt); $out->add($pts[$q], $pts[$q + 1]); }
        $out->end(false);
        for ($i++, $n = $in->count(); $i < $n; $i++) {
            [$from, $to] = $in->range($i);
            $out->start($pts[$from], $pts[$from + 1]);
            for ($q = $from + 2; $q < $to; $q += 2) { $out->add($pts[$q], $pts[$q + 1]); }
            $out->end($in->closed[$i]);
        }
    }
}
