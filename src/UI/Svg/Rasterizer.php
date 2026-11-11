<?php
declare(strict_types=1);

namespace MyPHP\UI\Svg;

use FFI;
use FFI\CData;
use MyPHP\Native\Mem;

/**
 * Rasterizes polygons into anti-aliased coverage, as mygo's internal/vec
 * does with the signed area accumulation of font-rs: every edge adds the
 * area it covers to the cells it crosses, and a running sum along each
 * row gives the coverage. Uses compact C float/uint8_t buffers via FFI so a
 * full-screen 3x Retina shape takes ~4 MB instead of >100 MB of PHP arrays.
 */
final class Rasterizer
{
    private static ?FFI $c = null;
    public int $w = 0, $h = 0;
    private ?CData $acc = null;
    private int $accCap = 0;
    private ?CData $cov = null;
    private int $covCap = 0;
    private float $penX = 0, $penY = 0, $firstX = 0, $firstY = 0;
    private bool $open = false;

    private static function ffi(): FFI
    {
        return self::$c ??= Layer::ffi();
    }

    public function __destruct()
    {
        $f = self::ffi();
        if ($this->acc !== null) { $f->free($this->acc); $this->acc = null; }
        if ($this->cov !== null) { $f->free($this->cov); $this->cov = null; }
    }

    public function reset(int $w, int $h): void
    {
        $this->w = $w; $this->h = $h;
        $n = max(1, $w * $h);
        if ($n > $this->accCap || $this->acc === null) {
            $f = self::ffi();
            if ($this->acc !== null) { $f->free($this->acc); }
            $this->acc = $f->cast('float*', $f->calloc($n, 4));
            $this->accCap = $n;
        } else {
            FFI::memset($this->acc, 0, $n * 4);
        }
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
        $w = $this->w; $acc = $this->acc;
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
     * Computes coverage into the reusable C uint8_t buffer $this->cov and returns it.
     */
    public function maskBuffer(bool $evenOdd): CData
    {
        $this->closePath();
        $w = $this->w;
        $n = max(1, $w * $this->h);
        if ($n > $this->covCap || $this->cov === null) {
            $f = self::ffi();
            if ($this->cov !== null) { $f->free($this->cov); }
            $this->cov = $f->cast('uint8_t*', $f->calloc($n, 1));
            $this->covCap = $n;
        }
        $out = $this->cov;
        $acc = $this->acc;
        $total = $w * $this->h;
        for ($y = 0; $y < $total; $y += $w) {
            $sum = 0.0;
            for ($x = $y, $e = $y + $w; $x < $e; $x++) {
                $sum += $acc[$x];
                $a = $sum < 0 ? -$sum : $sum;
                if ($evenOdd) { $a -= 2 * (int)($a / 2); if ($a > 1) { $a = 2 - $a; } }
                elseif ($a > 1) { $a = 1.0; }
                $out[$x] = (int)($a * 255 + 0.5);
            }
        }
        return $out;
    }

    /**
     * The coverage of the accumulated contours as a raw byte string (w*h bytes).
     */
    public function maskBytes(bool $evenOdd): string
    {
        $total = $this->w * $this->h;
        if ($total <= 0) { return ''; }
        return FFI::string($this->maskBuffer($evenOdd), $total);
    }

    /**
     * The coverage of the accumulated contours as a list of w*h ints from
     * 0 to 255, by the non-zero winding rule, or with $evenOdd by the
     * even-odd rule.
     * @return list<int>
     */
    public function mask(bool $evenOdd): array
    {
        $bytes = $this->maskBytes($evenOdd);
        return $bytes === '' ? [] : array_values(unpack('C*', $bytes));
    }
}