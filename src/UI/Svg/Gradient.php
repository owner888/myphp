<?php
declare(strict_types=1);

namespace MyPHP\UI\Svg;

/** A linear or radial gradient: the line of a linear one, the circles of a radial one, its stops [offset, color, current, opacity]. */
final class Gradient
{
    public const PAD = 0, REFLECT = 1, REPEAT = 2;
    public bool $radial = false;
    public bool $bbox = true; // in objectBoundingBox units
    public array $m = Matrix::IDENTITY; // gradientTransform
    public int $spread = self::PAD;
    public float $x1 = 0, $y1 = 0, $x2 = 0, $y2 = 0;
    public float $cx = 0, $cy = 0, $r = 0, $fx = 0, $fy = 0, $fr = 0;
    /** @var list<array{0: float, 1: array, 2: bool, 3: float}> */
    public array $stops = [];

    /** Where a point in the gradient's space falls along it, spread into [0, 1]; null where a radial gradient does not reach. */
    public function at(float $px, float $py): ?float
    {
        if (!$this->radial) {
            $dx = $this->x2 - $this->x1; $dy = $this->y2 - $this->y1;
            $d = $dx * $dx + $dy * $dy;
            if ($d == 0) { return 1.0; } // the last stop's color
            $t = (($px - $this->x1) * $dx + ($py - $this->y1) * $dy) / $d;
        } else {
            if ($this->r <= 0) { return 1.0; }
            // The largest t for which p is on the circle around f + t(c - f) of radius fr + t(r - fr).
            $cdx = $this->cx - $this->fx; $cdy = $this->cy - $this->fy;
            $pdx = $px - $this->fx; $pdy = $py - $this->fy;
            $dr = $this->r - $this->fr;
            $a = $cdx * $cdx + $cdy * $cdy - $dr * $dr;
            $b = $pdx * $cdx + $pdy * $cdy + $this->fr * $dr;
            $c = $pdx * $pdx + $pdy * $pdy - $this->fr * $this->fr;
            if (abs($a) < 1e-12) {
                if ($b == 0) { return null; }
                $t = $c / (2 * $b);
            } else {
                $disc = $b * $b - $a * $c;
                if ($disc < 0) { return null; }
                $s = sqrt($disc);
                $t0 = ($b + $s) / $a; $t1 = ($b - $s) / $a;
                $t = max($t0, $t1);
                if ($this->fr + $t * $dr < 0) {
                    $t = min($t0, $t1);
                    if ($this->fr + $t * $dr < 0) { return null; }
                }
            }
        }
        if ($this->spread === self::REFLECT) { $t = fmod(abs($t), 2); if ($t > 1) { $t = 2 - $t; } }
        elseif ($this->spread === self::REPEAT) { $t -= floor($t); }
        else { $t = max(0.0, min(1.0, $t)); }
        return $t >= 0 && $t <= 1 ? $t : null;
    }

    /** The gradient's colors at 256 positions, with an opacity, premultiplied as [r, g, b, a] of 0..255. @return list<array> */
    public function lut(array $current, float $alpha): array
    {
        $stops = $this->stops; $last = count($stops) - 1;
        $color = function (array $s) use ($current): array {
            $c = $s[2] ? $current : $s[1];
            $c[3] *= $s[3];
            return $c;
        };
        $out = []; $j = 0;
        for ($i = 0; $i < 256; $i++) {
            $t = $i / 255;
            while ($j + 1 <= $last && $stops[$j + 1][0] <= $t) { $j++; }
            if ($t <= $stops[0][0]) { $c = $color($stops[0]); }
            elseif ($j === $last) { $c = $color($stops[$j]); }
            else {
                $a = $color($stops[$j]); $b = $color($stops[$j + 1]);
                $f = ($t - $stops[$j][0]) / ($stops[$j + 1][0] - $stops[$j][0]);
                $c = [$a[0] + ($b[0] - $a[0]) * $f, $a[1] + ($b[1] - $a[1]) * $f, $a[2] + ($b[2] - $a[2]) * $f, $a[3] + ($b[3] - $a[3]) * $f];
            }
            $out[] = Renderer::premul($c, $alpha);
        }
        return $out;
    }
}
