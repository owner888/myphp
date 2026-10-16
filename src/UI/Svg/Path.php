<?php
declare(strict_types=1);

namespace MyPHP\UI\Svg;

/**
 * A path in user units, as mygo's internal/svg path: its ops, with the
 * points of each flat in $pts (x, y, x, y…); its curves are cubic Bézier
 * curves, quadratic curves and arcs converted. $start is the first point
 * of the subpath, $cur the pen; $open is false before the first move and
 * after a close.
 */
final class Path
{
    public const MOVE = 0, LINE = 1, CUBIC = 2, CLOSE = 3;
    private const KAPPA = 0.5522847498307936; // places the control points of a quarter circle's cubic curve
    /** Beyond it, curves are drawn as lines, and strokes are left out. */
    public const MAX_POINTS = 1 << 20;

    /** @var list<int> */
    public array $ops = [];
    /** @var list<float> */
    public array $pts = [];
    public float $sx = 0, $sy = 0, $cx = 0, $cy = 0;
    public bool $open = false;

    public function moveTo(float $x, float $y): void
    {
        $this->ops[] = self::MOVE; $this->pts[] = $x; $this->pts[] = $y;
        $this->sx = $this->cx = $x; $this->sy = $this->cy = $y; $this->open = true;
    }

    /** Starts a subpath where the pen is, when drawing follows a close: the subpath after a close starts where the closed one did. */
    private function begin(): void
    {
        if (!$this->open) { $this->moveTo($this->cx, $this->cy); }
    }

    public function lineTo(float $x, float $y): void
    {
        $this->begin();
        $this->ops[] = self::LINE; $this->pts[] = $x; $this->pts[] = $y;
        $this->cx = $x; $this->cy = $y;
    }

    public function cubicTo(float $x1, float $y1, float $x2, float $y2, float $x, float $y): void
    {
        $this->begin();
        $this->ops[] = self::CUBIC; array_push($this->pts, $x1, $y1, $x2, $y2, $x, $y);
        $this->cx = $x; $this->cy = $y;
    }

    /** A quadratic curve, as the cubic curve it is. */
    public function quadTo(float $qx, float $qy, float $x, float $y): void
    {
        $ax = $this->cx; $ay = $this->cy;
        $this->cubicTo($ax + 2.0 / 3 * ($qx - $ax), $ay + 2.0 / 3 * ($qy - $ay), $x + 2.0 / 3 * ($qx - $x), $y + 2.0 / 3 * ($qy - $y), $x, $y);
    }

    public function close(): void
    {
        if ($this->open) {
            $this->ops[] = self::CLOSE;
            $this->cx = $this->sx; $this->cy = $this->sy; $this->open = false;
        }
    }

    /** An elliptical arc to (x, y) as cubic curves, converting SVG's endpoint parameters as appendix F.6 of SVG 1.1 says. */
    public function arcTo(float $rx, float $ry, float $rotation, bool $large, bool $sweep, float $px, float $py): void
    {
        $ax = $this->cx; $ay = $this->cy;
        if ($ax === $px && $ay === $py) { return; }
        $rx = abs($rx); $ry = abs($ry);
        if ($rx == 0 || $ry == 0) { $this->lineTo($px, $py); return; }
        $sin = sin($rotation * M_PI / 180); $cos = cos($rotation * M_PI / 180);
        $hx = ($ax - $px) / 2; $hy = ($ay - $py) / 2; // half the chord, in the ellipse's axes
        $x1 = $cos * $hx + $sin * $hy;
        $y1 = -$sin * $hx + $cos * $hy;
        $l = $x1 * $x1 / ($rx * $rx) + $y1 * $y1 / ($ry * $ry);
        if ($l > 1) { $l = sqrt($l); $rx *= $l; $ry *= $l; } // radii too small to reach p grow
        $num = $rx * $rx * $ry * $ry - $rx * $rx * $y1 * $y1 - $ry * $ry * $x1 * $x1;
        $den = $rx * $rx * $y1 * $y1 + $ry * $ry * $x1 * $x1;
        $k = $den > 0 ? sqrt(max($num / $den, 0)) : 0.0;
        if ($large === $sweep) { $k = -$k; }
        $cx1 = $k * $rx * $y1 / $ry; $cy1 = -$k * $ry * $x1 / $rx;
        $cx = $cos * $cx1 - $sin * $cy1 + ($ax + $px) / 2;
        $cy = $sin * $cx1 + $cos * $cy1 + ($ay + $py) / 2;
        $ux = ($x1 - $cx1) / $rx; $uy = ($y1 - $cy1) / $ry;
        $vx = (-$x1 - $cx1) / $rx; $vy = (-$y1 - $cy1) / $ry;
        $theta = atan2($uy, $ux);
        $delta = atan2($ux * $vy - $uy * $vx, $ux * $vx + $uy * $vy);
        if (!$sweep && $delta > 0) { $delta -= 2 * M_PI; } elseif ($sweep && $delta < 0) { $delta += 2 * M_PI; }
        $n = max((int)ceil(abs($delta) / (M_PI / 2) - 1e-7), 1); // quarter turns at most per curve
        $step = $delta / $n;
        $t = 4.0 / 3 * tan($step / 4);
        $on = fn(float $ex, float $ey) => [$cos * $ex * $rx - $sin * $ey * $ry + $cx, $sin * $ex * $rx + $cos * $ey * $ry + $cy];
        for ($i = 0; $i < $n; $i++) {
            $s1 = sin($theta); $c1 = cos($theta);
            $s2 = sin($theta + $step); $c2 = cos($theta + $step);
            [$ex, $ey] = $i === $n - 1 ? [$px, $py] : $on($c2, $s2);
            [$p1x, $p1y] = $on($c1 - $t * $s1, $s1 + $t * $c1);
            [$p2x, $p2y] = $on($c2 + $t * $s2, $s2 - $t * $c2);
            $this->cubicTo($p1x, $p1y, $p2x, $p2y, $ex, $ey);
            $theta += $step;
        }
    }

    public function ellipse(float $cx, float $cy, float $rx, float $ry): void
    {
        $kx = $rx * self::KAPPA; $ky = $ry * self::KAPPA;
        $this->moveTo($cx + $rx, $cy);
        $this->cubicTo($cx + $rx, $cy + $ky, $cx + $kx, $cy + $ry, $cx, $cy + $ry);
        $this->cubicTo($cx - $kx, $cy + $ry, $cx - $rx, $cy + $ky, $cx - $rx, $cy);
        $this->cubicTo($cx - $rx, $cy - $ky, $cx - $kx, $cy - $ry, $cx, $cy - $ry);
        $this->cubicTo($cx + $kx, $cy - $ry, $cx + $rx, $cy - $ky, $cx + $rx, $cy);
        $this->close();
    }

    /** A rectangle with corners rounded $rx by $ry. */
    public function rect(float $x, float $y, float $w, float $h, float $rx = 0, float $ry = 0): void
    {
        if ($rx <= 0 || $ry <= 0) {
            $this->moveTo($x, $y); $this->lineTo($x + $w, $y); $this->lineTo($x + $w, $y + $h); $this->lineTo($x, $y + $h); $this->close();
            return;
        }
        $kx = $rx * self::KAPPA; $ky = $ry * self::KAPPA;
        $r = $x + $w; $b = $y + $h;
        $this->moveTo($x + $rx, $y);
        $this->lineTo($r - $rx, $y);
        $this->cubicTo($r - $rx + $kx, $y, $r, $y + $ry - $ky, $r, $y + $ry);
        $this->lineTo($r, $b - $ry);
        $this->cubicTo($r, $b - $ry + $ky, $r - $rx + $kx, $b, $r - $rx, $b);
        $this->lineTo($x + $rx, $b);
        $this->cubicTo($x + $rx - $kx, $b, $x, $b - $ry + $ky, $x, $b - $ry);
        $this->lineTo($x, $y + $ry);
        $this->cubicTo($x, $y + $ry - $ky, $x + $rx - $kx, $y, $x + $rx, $y);
        $this->close();
    }

    /** Parses path data up to its first error, as SVG asks. */
    public static function parse(string $d): self
    {
        $pa = new self; $p = new Scanner($d);
        $cmd = ''; $last = ''; // the command, and the last one in upper case
        $ctrlX = 0.0; $ctrlY = 0.0; // the last control point, for S and T
        $coord = function () use ($p): ?float { $p->skipSep(); return $p->number(); };
        $pt = function (float $bx, float $by) use ($coord): ?array {
            $x = $coord(); if ($x === null) { return null; }
            $y = $coord(); return $y === null ? null : [$bx + $x, $by + $y];
        };
        $flag = function () use ($p): ?bool {
            $p->skipSep();
            $c = $p->peek();
            if ($c === '0' || $c === '1') { $p->i++; return $c === '1'; }
            return null;
        };
        while (true) {
            $p->skipSpace();
            if ($p->done()) { break; }
            $c = $p->peek();
            if (strpos('MmLlHhVvCcSsQqTtAaZz', $c) !== false) { $cmd = $c; $p->i++; }
            elseif ($cmd === '' || $cmd === 'Z' || $cmd === 'z') { break; } // numbers without a command
            if ($pa->ops === [] && $cmd !== 'M' && $cmd !== 'm') { break; } // a path starts with a move
            $bx = 0.0; $by = 0.0;
            if ($cmd >= 'a') { $bx = $pa->cx; $by = $pa->cy; }
            $up = strtoupper($cmd);
            $ok = true;
            switch ($up) {
                case 'M':
                    if (($a = $pt($bx, $by)) !== null) { $pa->moveTo($a[0], $a[1]); $cmd = $cmd === 'm' ? 'l' : 'L'; } else { $ok = false; } // more coordinates are lines
                    break;
                case 'L':
                    if (($a = $pt($bx, $by)) !== null) { $pa->lineTo($a[0], $a[1]); } else { $ok = false; }
                    break;
                case 'H':
                    if (($x = $coord()) !== null) { $pa->lineTo($bx + $x, $pa->cy); } else { $ok = false; }
                    break;
                case 'V':
                    if (($y = $coord()) !== null) { $pa->lineTo($pa->cx, $by + $y); } else { $ok = false; }
                    break;
                case 'C': case 'S':
                    $c1 = [$pa->cx, $pa->cy];
                    if ($up === 'C') { $c1 = $pt($bx, $by); $ok = $c1 !== null; }
                    elseif ($last === 'C' || $last === 'S') { $c1 = [2 * $pa->cx - $ctrlX, 2 * $pa->cy - $ctrlY]; }
                    $c2 = $ok ? $pt($bx, $by) : null; $ok = $c2 !== null;
                    $e = $ok ? $pt($bx, $by) : null; $ok = $e !== null;
                    if ($ok) { $pa->cubicTo($c1[0], $c1[1], $c2[0], $c2[1], $e[0], $e[1]); [$ctrlX, $ctrlY] = $c2; }
                    break;
                case 'Q': case 'T':
                    $q = [$pa->cx, $pa->cy];
                    if ($up === 'Q') { $q = $pt($bx, $by); $ok = $q !== null; }
                    elseif ($last === 'Q' || $last === 'T') { $q = [2 * $pa->cx - $ctrlX, 2 * $pa->cy - $ctrlY]; }
                    $e = $ok ? $pt($bx, $by) : null; $ok = $e !== null;
                    if ($ok) { $pa->quadTo($q[0], $q[1], $e[0], $e[1]); [$ctrlX, $ctrlY] = $q; }
                    break;
                case 'A':
                    $rx = $coord(); $ry = $rx === null ? null : $coord(); $rot = $ry === null ? null : $coord();
                    $large = $rot === null ? null : $flag(); $sweep = $large === null ? null : $flag();
                    $e = $sweep === null ? null : $pt($bx, $by);
                    if ($e !== null) { $pa->arcTo($rx, $ry, $rot, $large, $sweep, $e[0], $e[1]); } else { $ok = false; }
                    break;
                case 'Z':
                    $pa->close();
                    break;
            }
            if (!$ok) { break; }
            $last = $up;
        }
        return $pa;
    }

    /** The box around the path's geometry, in user units, or null for none. */
    public function bounds(): ?array
    {
        $x0 = $y0 = INF; $x1 = $y1 = -INF; $found = false;
        $add = function (float $x, float $y) use (&$x0, &$y0, &$x1, &$y1, &$found) {
            $found = true;
            if ($x < $x0) { $x0 = $x; } if ($x > $x1) { $x1 = $x; } if ($y < $y0) { $y0 = $y; } if ($y > $y1) { $y1 = $y; }
        };
        $cx = $cy = 0.0; $i = 0; $pts = $this->pts;
        foreach ($this->ops as $op) {
            if ($op === self::MOVE) { $cx = $pts[$i]; $cy = $pts[$i + 1]; $i += 2; }
            elseif ($op === self::LINE) { $add($cx, $cy); $cx = $pts[$i]; $cy = $pts[$i + 1]; $add($cx, $cy); $i += 2; }
            elseif ($op === self::CUBIC) {
                $x1c = $pts[$i]; $y1c = $pts[$i + 1]; $x2c = $pts[$i + 2]; $y2c = $pts[$i + 3]; $ex = $pts[$i + 4]; $ey = $pts[$i + 5];
                $add($cx, $cy); $add($ex, $ey);
                foreach ([...self::extrema($cx, $x1c, $x2c, $ex), ...self::extrema($cy, $y1c, $y2c, $ey)] as $t) {
                    $u = 1 - $t; $k0 = $u * $u * $u; $k1 = 3 * $u * $u * $t; $k2 = 3 * $u * $t * $t; $k3 = $t * $t * $t;
                    $add($k0 * $cx + $k1 * $x1c + $k2 * $x2c + $k3 * $ex, $k0 * $cy + $k1 * $y1c + $k2 * $y2c + $k3 * $ey);
                }
                $cx = $ex; $cy = $ey; $i += 6;
            }
        }
        return $found ? [$x0, $y0, $x1 - $x0, $y1 - $y0] : null;
    }

    /** Where in (0, 1) a cubic curve's coordinate turns. @return list<float> */
    private static function extrema(float $a, float $b, float $c, float $d): array
    {
        $qa = -$a + 3 * $b - 3 * $c + $d; $qb = 2 * ($a - 2 * $b + $c); $qc = $b - $a;
        $roots = [];
        if (abs($qa) < 1e-12) { if ($qb != 0) { $roots[] = -$qc / $qb; } }
        elseif (($disc = $qb * $qb - 4 * $qa * $qc) >= 0) { $s = sqrt($disc); $roots = [(-$qb + $s) / (2 * $qa), (-$qb - $s) / (2 * $qa)]; }
        return array_values(array_filter($roots, fn($t) => $t > 0 && $t < 1));
    }

    /**
     * Adds the path's subpaths to $out as polylines, with points enough
     * that they stray less than a tenth of a pixel from its curves once
     * transformed by $m, the transform to pixels: in pixels with $device,
     * in user units otherwise.
     */
    public function flatten(array $m, bool $device, Polys $out): void
    {
        [$m0, $m1, $m2, $m3, $m4, $m5] = $m;
        $cx = $cy = 0.0; $i = 0; $pts = $this->pts;
        foreach ($this->ops as $op) {
            if ($op === self::MOVE) {
                $cx = $pts[$i]; $cy = $pts[$i + 1]; $i += 2;
                $device ? $out->start($m0 * $cx + $m2 * $cy + $m4, $m1 * $cx + $m3 * $cy + $m5) : $out->start($cx, $cy);
            } elseif ($op === self::LINE) {
                $cx = $pts[$i]; $cy = $pts[$i + 1]; $i += 2;
                $device ? $out->add($m0 * $cx + $m2 * $cy + $m4, $m1 * $cx + $m3 * $cy + $m5) : $out->add($cx, $cy);
            } elseif ($op === self::CUBIC) {
                $x1 = $pts[$i]; $y1 = $pts[$i + 1]; $x2 = $pts[$i + 2]; $y2 = $pts[$i + 3]; $ex = $pts[$i + 4]; $ey = $pts[$i + 5]; $i += 6;
                $n = 1;
                if (count($out->pts) < 2 * self::MAX_POINTS) {
                    // The error of n lines is at most 3/4 of the curve's largest second difference over n², in pixels.
                    $ddx = max(abs(($m0 * ($cx - 2 * $x1 + $x2) + $m2 * ($cy - 2 * $y1 + $y2))), abs($m0 * ($x1 - 2 * $x2 + $ex) + $m2 * ($y1 - 2 * $y2 + $ey)));
                    $ddy = max(abs(($m1 * ($cx - 2 * $x1 + $x2) + $m3 * ($cy - 2 * $y1 + $y2))), abs($m1 * ($x1 - 2 * $x2 + $ex) + $m3 * ($y1 - 2 * $y2 + $ey)));
                    $dd = hypot($ddx, $ddy);
                    $n = $dd >= 0 ? max(1, min((int)ceil(sqrt(7.5 * $dd)), 1000)) : 1;
                }
                for ($k = 1; $k < $n; $k++) {
                    $t = $k / $n; $u = 1 - $t; $k0 = $u * $u * $u; $k1 = 3 * $u * $u * $t; $k2 = 3 * $u * $t * $t; $k3 = $t * $t * $t;
                    $x = $k0 * $cx + $k1 * $x1 + $k2 * $x2 + $k3 * $ex; $y = $k0 * $cy + $k1 * $y1 + $k2 * $y2 + $k3 * $ey;
                    $device ? $out->add($m0 * $x + $m2 * $y + $m4, $m1 * $x + $m3 * $y + $m5) : $out->add($x, $y);
                }
                $device ? $out->add($m0 * $ex + $m2 * $ey + $m4, $m1 * $ex + $m3 * $ey + $m5) : $out->add($ex, $ey);
                $cx = $ex; $cy = $ey;
            } else {
                if ($out->building && count($out->pts) - $out->begin === 2) { $out->add($out->pts[$out->begin], $out->pts[$out->begin + 1]); } // a subpath of no length, which round and square caps stroke
                $out->end(true);
            }
        }
        $out->end(false);
    }
}
