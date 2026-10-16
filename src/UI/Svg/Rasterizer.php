<?php
declare(strict_types=1);

namespace MyPHP\UI\Svg;

/**
 * Rasterizes polygons into anti-aliased coverage, as mygo's internal/vec
 * does with the signed area accumulation of font-rs: every edge adds the
 * area it covers to the cells it crosses, and a running sum along each
 * row gives the coverage. Reuse one with reset().
 */
final class Rasterizer
{
    public int $w = 0, $h = 0;
    /** @var list<float> w*h accumulated areas */
    private array $acc = [];
    private float $penX = 0, $penY = 0, $firstX = 0, $firstY = 0;
    private bool $open = false;

    public function reset(int $w, int $h): void
    {
        $this->w = $w; $this->h = $h;
        $this->acc = array_fill(0, $w * $h, 0.0);
        $this->open = false;
    }

    /** Starts a contour, closing the previous one. */
    public function moveTo(float $x, float $y): void
    {
        $this->closePath();
        $this->firstX = $this->penX = $x; $this->firstY = $this->penY = $y;
        $this->open = true;
    }

    /** A line back to the start of the contour. */
    public function closePath(): void
    {
        if ($this->open) { $this->lineTo($this->firstX, $this->firstY); $this->open = false; }
    }

    /** A line from the pen. */
    public function lineTo(float $bx, float $by): void
    {
        $ax = $this->penX; $ay = $this->penY;
        $this->penX = $bx; $this->penY = $by;
        $dir = 1.0;
        if ($ay > $by) { $dir = -1.0; [$ax, $ay, $bx, $by] = [$bx, $by, $ax, $ay]; }
        if ($by - $ay <= 0 || $by <= 0 || $ay >= $this->h) { return; }
        $dxdy = ($bx - $ax) / ($by - $ay);
        $x = $ax;
        $y0 = (int)floor($ay); $y1 = (int)ceil($by);
        if ($y0 < 0) { $x += $dxdy * (0 - $ay); $ay = 0.0; $y0 = 0; } // skip the rows above the mask
        $y1 = min($y1, $this->h);
        $w = $this->w; $acc = &$this->acc;
        for ($y = $y0; $y < $y1; $y++) {
            $dy = min($y + 1, $by) - max($y, $ay);
            $xNext = $x + $dy * $dxdy;
            $d = $dy * $dir;
            $row = $y * $w;
            $x0 = $x; $x1 = $xNext;
            if ($x0 > $x1) { [$x0, $x1] = [$x1, $x0]; }
            $x0i = (int)floor($x0); $x1i = (int)ceil($x1);
            if ($x1i <= $x0i + 1) {
                $xmf = 0.5 * ($x + $xNext) - $x0i;
                $k = max($x0i, 0); if ($k < $w) { $acc[$row + $k] += $d - $d * $xmf; }
                $k = max($x0i + 1, 0); if ($k < $w) { $acc[$row + $k] += $d * $xmf; }
            } else {
                $s = 1 / ($x1 - $x0);
                $x0fr = $x0 - $x0i; $omx = 1 - $x0fr;
                $a0 = 0.5 * $s * $omx * $omx;
                $x1fr = $x1 - $x1i + 1;
                $am = 0.5 * $s * $x1fr * $x1fr;
                $k = max($x0i, 0); if ($k < $w) { $acc[$row + $k] += $d * $a0; }
                if ($x1i === $x0i + 2) {
                    $k = max($x0i + 1, 0); if ($k < $w) { $acc[$row + $k] += $d * (1 - $a0 - $am); }
                } else {
                    $a1 = $s * (1.5 - $x0fr);
                    $k = max($x0i + 1, 0); if ($k < $w) { $acc[$row + $k] += $d * ($a1 - $a0); }
                    for ($xi = $x0i + 2; $xi < $x1i - 1; $xi++) { $k = max($xi, 0); if ($k < $w) { $acc[$row + $k] += $d * $s; } }
                    $a2 = $a1 + $s * ($x1i - $x0i - 3);
                    $k = max($x1i - 1, 0); if ($k < $w) { $acc[$row + $k] += $d * (1 - $a2 - $am); }
                }
                $k = max($x1i, 0); if ($k < $w) { $acc[$row + $k] += $d * $am; }
            }
            $x = $xNext;
        }
    }

    /**
     * The coverage of the accumulated contours as a list of w*h ints from
     * 0 to 255, by the non-zero winding rule, or with $evenOdd by the
     * even-odd rule: what the contours cover an odd number of times is
     * inside.
     * @return list<int>
     */
    public function mask(bool $evenOdd): array
    {
        $this->closePath();
        $w = $this->w; $out = []; $acc = $this->acc;
        for ($y = 0, $n = $w * $this->h; $y < $n; $y += $w) {
            $sum = 0.0;
            for ($x = $y, $e = $y + $w; $x < $e; $x++) {
                $sum += $acc[$x];
                $a = $sum < 0 ? -$sum : $sum;
                if ($evenOdd) { $a -= 2 * (int)($a / 2); if ($a > 1) { $a = 2 - $a; } } // the winding number folded: 0, 2, 4… outside, 1, 3, 5… inside
                elseif ($a > 1) { $a = 1.0; }
                $out[] = (int)($a * 255 + 0.5);
            }
        }
        return $out;
    }
}
