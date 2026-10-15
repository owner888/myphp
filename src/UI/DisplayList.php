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
 * kind 3 is a soft shadow (u = blur), kind 4 a ring (u = thickness), kind 5
 * Liquid Glass (u = style, color = tint), kind 6 a vertical alpha gradient
 * (color.a at the top, u at the bottom), kind 7 a backdrop blur and 8 the
 * hard scroll edge's (mixed with the color by its alpha, shown from u at
 * the top to v at the bottom). Kinds 5, 7 and 8 read what was drawn before
 * them, blurred: the renderer splits the frame's draw at each of them.
 * The clip rect is the innermost pushClip() in effect; fragments outside
 * it are discarded.
 */
final class DisplayList
{
    public const FLOATS_PER_RECT = 16;
    private const NO_CLIP = [-1e6, -1e6, 2e6, 2e6];
    public int $n = 0;
    /** @var list<array{0: int, 1: float}> the glass quads: index, blur sigma in pixels */
    public array $glass = [];
    private array $clip = self::NO_CLIP;
    private array $clips = [];

    public function __construct(public CData $buf, public int $capacity) {}

    public function reset(): void
    {
        $this->n = 0;
        $this->glass = [];
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

    /** A pane of Liquid Glass over what was drawn before it: $style 0 regular, 1 clear; tinted by $tint at $tintAlpha; blurred by $sigma pixels. */
    public function glass(float $x, float $y, float $w, float $h, float $radius, int $style, int $tint, float $tintAlpha, float $sigma): void
    {
        $this->glass[] = [$this->n, $sigma];
        $this->quad($x, $y, $w, $h, $tint, $tintAlpha, $radius, 5.0, (float)$style);
    }

    /** A vertical gradient of $rgb from $alphaTop to $alphaBottom. */
    public function gradient(float $x, float $y, float $w, float $h, int $rgb, float $alphaTop, float $alphaBottom, float $radius = 0.0): void
    {
        $this->quad($x, $y, $w, $h, $rgb, $alphaTop, $radius, 6.0, $alphaBottom);
    }

    /**
     * What was drawn before, blurred by $sigma pixels, mixed with $rgb by
     * $mix, shown from $top to $bottom (0..1) down the quad; $hard tones it
     * as macOS's hard scroll edge does.
     */
    public function blur(float $x, float $y, float $w, float $h, float $radius, float $sigma, int $rgb, float $mix, float $top, float $bottom, bool $hard = false): void
    {
        $this->glass[] = [$this->n, $sigma];
        $this->quad($x, $y, $w, $h, $rgb, $mix, $radius, $hard ? 8.0 : 7.0, $top, $bottom);
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

    private function quad(float $x, float $y, float $w, float $h, int $rgb, float $alpha, float $radius, float $kind, float $u, float $v = 0.0): void
    {
        if ($this->n === $this->capacity) {
            throw new RuntimeException("display list full ({$this->capacity} quads)");
        }
        $b = $this->buf; $k = $this->n * 16; $c = $this->clip;
        $b[$k] = $x; $b[$k + 1] = $y; $b[$k + 2] = $w; $b[$k + 3] = $h;
        $b[$k + 4] = (($rgb >> 16) & 255) / 255; $b[$k + 5] = (($rgb >> 8) & 255) / 255; $b[$k + 6] = ($rgb & 255) / 255; $b[$k + 7] = $alpha;
        $b[$k + 8] = $radius; $b[$k + 9] = $kind; $b[$k + 10] = $u; $b[$k + 11] = $v;
        $b[$k + 12] = $c[0]; $b[$k + 13] = $c[1]; $b[$k + 14] = $c[2]; $b[$k + 15] = $c[3];
        $this->n++;
    }

    /** An image quad: its atlas pixels start at ($u, $v) and span $sw × $sh, drawn into w × h with rounded corners. */
    /** An image quad: the slot (u, v, sw*sh pixels) on page $page of the image atlas, drawn at x, y, w*h points. */
    public function image(float $x, float $y, float $w, float $h, int $u, int $v, int $sw, int $sh, int $page, float $alpha = 1.0, float $radius = 0.0): void
    {
        if ($this->n === $this->capacity) {
            throw new RuntimeException("display list full ({$this->capacity} quads)");
        }
        $b = $this->buf; $k = $this->n * 16; $c = $this->clip;
        $b[$k] = $x; $b[$k + 1] = $y; $b[$k + 2] = $w; $b[$k + 3] = $h;
        $b[$k + 4] = $sw; $b[$k + 5] = $sh; $b[$k + 6] = $page; $b[$k + 7] = $alpha;
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
