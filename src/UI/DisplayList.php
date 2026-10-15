<?php
declare(strict_types=1);

namespace MyPHP\UI;

use FFI\CData;
use RuntimeException;

/**
 * A frame's drawing commands, written straight into a GPU buffer's memory.
 * One quad is 16 floats: x y w h, r g b a, radius kind u v, clip x y w h.
 * kind 0 is a filled (rounded) rect; kind 1 samples the glyph atlas at u, v;
 * kind 2 samples the image atlas, with the slot's pixel size in r, g;
 * kind 3 is a soft shadow (u = blur), kind 4 a ring (u = thickness).
 * The clip rect is the innermost pushClip() in effect; fragments outside
 * it are discarded.
 */
final class DisplayList
{
    public const FLOATS_PER_RECT = 16;
    private const NO_CLIP = [-1e6, -1e6, 2e6, 2e6];
    public int $n = 0;
    private array $clip = self::NO_CLIP;
    private array $clips = [];

    public function __construct(public CData $buf, public int $capacity) {}

    public function reset(): void
    {
        $this->n = 0;
        $this->clip = self::NO_CLIP;
        $this->clips = [];
    }

    /** Clips what follows to the intersection of the current clip and this rect. */
    public function pushClip(float $x, float $y, float $w, float $h): void
    {
        $this->clips[] = $c = $this->clip;
        $x0 = max($c[0], $x); $y0 = max($c[1], $y);
        $x1 = min($c[0] + $c[2], $x + $w); $y1 = min($c[1] + $c[3], $y + $h);
        $this->clip = [$x0, $y0, max(0, $x1 - $x0), max(0, $y1 - $y0)];
    }

    public function popClip(): void
    {
        $this->clip = array_pop($this->clips);
    }

    /** Whether a rect lies entirely outside the current clip (nothing to draw). */
    public function clipped(float $x, float $y, float $w, float $h): bool
    {
        $c = $this->clip;
        return $x >= $c[0] + $c[2] || $y >= $c[1] + $c[3] || $x + $w <= $c[0] || $y + $h <= $c[1];
    }

    public function rect(float $x, float $y, float $w, float $h, int $rgb, float $alpha = 1.0, float $radius = 0.0): void
    {
        if ($this->n === $this->capacity) {
            throw new RuntimeException("display list full ({$this->capacity} rects)");
        }
        $b = $this->buf; $k = $this->n * 16; $c = $this->clip;
        $b[$k] = $x; $b[$k + 1] = $y; $b[$k + 2] = $w; $b[$k + 3] = $h;
        $b[$k + 4] = (($rgb >> 16) & 255) / 255; $b[$k + 5] = (($rgb >> 8) & 255) / 255; $b[$k + 6] = ($rgb & 255) / 255; $b[$k + 7] = $alpha;
        $b[$k + 8] = $radius; $b[$k + 9] = 0.0;
        $b[$k + 12] = $c[0]; $b[$k + 13] = $c[1]; $b[$k + 14] = $c[2]; $b[$k + 15] = $c[3];
        $this->n++;
    }

    /** A soft shadow of a rounded rect, offset by (dx, dy), its edge feathered over $blur points each way. */
    public function shadow(float $x, float $y, float $w, float $h, int $rgb, float $alpha, float $radius, float $blur, float $dx = 0, float $dy = 0): void
    {
        $this->quad($x + $dx - $blur, $y + $dy - $blur, $w + 2 * $blur, $h + 2 * $blur, $rgb, $alpha, $radius, 3.0, $blur);
    }

    /** A ring: the rounded rect's outline, $thickness points wide inside its edge. */
    public function ring(float $x, float $y, float $w, float $h, int $rgb, float $alpha, float $radius, float $thickness): void
    {
        $this->quad($x, $y, $w, $h, $rgb, $alpha, $radius, 4.0, $thickness);
    }

    private function quad(float $x, float $y, float $w, float $h, int $rgb, float $alpha, float $radius, float $kind, float $u): void
    {
        if ($this->n === $this->capacity) {
            throw new RuntimeException("display list full ({$this->capacity} quads)");
        }
        $b = $this->buf; $k = $this->n * 16; $c = $this->clip;
        $b[$k] = $x; $b[$k + 1] = $y; $b[$k + 2] = $w; $b[$k + 3] = $h;
        $b[$k + 4] = (($rgb >> 16) & 255) / 255; $b[$k + 5] = (($rgb >> 8) & 255) / 255; $b[$k + 6] = ($rgb & 255) / 255; $b[$k + 7] = $alpha;
        $b[$k + 8] = $radius; $b[$k + 9] = $kind; $b[$k + 10] = $u; $b[$k + 11] = 0.0;
        $b[$k + 12] = $c[0]; $b[$k + 13] = $c[1]; $b[$k + 14] = $c[2]; $b[$k + 15] = $c[3];
        $this->n++;
    }

    /** An image quad: its atlas pixels start at ($u, $v) and span $sw × $sh, drawn into w × h with rounded corners. */
    public function image(float $x, float $y, float $w, float $h, int $u, int $v, int $sw, int $sh, float $alpha = 1.0, float $radius = 0.0): void
    {
        if ($this->n === $this->capacity) {
            throw new RuntimeException("display list full ({$this->capacity} quads)");
        }
        $b = $this->buf; $k = $this->n * 16; $c = $this->clip;
        $b[$k] = $x; $b[$k + 1] = $y; $b[$k + 2] = $w; $b[$k + 3] = $h;
        $b[$k + 4] = $sw; $b[$k + 5] = $sh; $b[$k + 6] = 0.0; $b[$k + 7] = $alpha;
        $b[$k + 8] = $radius; $b[$k + 9] = 2.0; $b[$k + 10] = $u; $b[$k + 11] = $v;
        $b[$k + 12] = $c[0]; $b[$k + 13] = $c[1]; $b[$k + 14] = $c[2]; $b[$k + 15] = $c[3];
        $this->n++;
    }

    /** A glyph quad: its atlas bitmap starts at pixel ($u, $v). */
    public function glyph(float $x, float $y, float $w, float $h, int $rgb, float $alpha, int $u, int $v): void
    {
        if ($this->n === $this->capacity) {
            throw new RuntimeException("display list full ({$this->capacity} quads)");
        }
        $b = $this->buf; $k = $this->n * 16; $c = $this->clip;
        $b[$k] = $x; $b[$k + 1] = $y; $b[$k + 2] = $w; $b[$k + 3] = $h;
        $b[$k + 4] = (($rgb >> 16) & 255) / 255; $b[$k + 5] = (($rgb >> 8) & 255) / 255; $b[$k + 6] = ($rgb & 255) / 255; $b[$k + 7] = $alpha;
        $b[$k + 8] = 0.0; $b[$k + 9] = 1.0; $b[$k + 10] = $u; $b[$k + 11] = $v;
        $b[$k + 12] = $c[0]; $b[$k + 13] = $c[1]; $b[$k + 14] = $c[2]; $b[$k + 15] = $c[3];
        $this->n++;
    }
}
