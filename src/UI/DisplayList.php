<?php
declare(strict_types=1);

namespace MyPHP\UI;

use FFI\CData;
use RuntimeException;

/**
 * A frame's drawing commands, written straight into a GPU buffer's memory.
 * One quad is 12 floats: x y w h, r g b a, then radius, kind, u, v.
 * kind 0 is a filled (rounded) rect; kind 1 samples the glyph atlas at u, v.
 */
final class DisplayList
{
    public const FLOATS_PER_RECT = 12;
    public int $n = 0;

    public function __construct(public CData $buf, public int $capacity) {}

    public function reset(): void
    {
        $this->n = 0;
    }

    public function rect(float $x, float $y, float $w, float $h, int $rgb, float $alpha = 1.0, float $radius = 0.0): void
    {
        if ($this->n === $this->capacity) {
            throw new RuntimeException("display list full ({$this->capacity} rects)");
        }
        $b = $this->buf; $k = $this->n * 12;
        $b[$k] = $x; $b[$k + 1] = $y; $b[$k + 2] = $w; $b[$k + 3] = $h;
        $b[$k + 4] = (($rgb >> 16) & 255) / 255; $b[$k + 5] = (($rgb >> 8) & 255) / 255; $b[$k + 6] = ($rgb & 255) / 255; $b[$k + 7] = $alpha;
        $b[$k + 8] = $radius; $b[$k + 9] = 0.0;
        $this->n++;
    }

    /** A glyph quad: its atlas bitmap starts at pixel ($u, $v). */
    public function glyph(float $x, float $y, float $w, float $h, int $rgb, float $alpha, int $u, int $v): void
    {
        if ($this->n === $this->capacity) {
            throw new RuntimeException("display list full ({$this->capacity} quads)");
        }
        $b = $this->buf; $k = $this->n * 12;
        $b[$k] = $x; $b[$k + 1] = $y; $b[$k + 2] = $w; $b[$k + 3] = $h;
        $b[$k + 4] = (($rgb >> 16) & 255) / 255; $b[$k + 5] = (($rgb >> 8) & 255) / 255; $b[$k + 6] = ($rgb & 255) / 255; $b[$k + 7] = $alpha;
        $b[$k + 8] = 0.0; $b[$k + 9] = 1.0; $b[$k + 10] = $u; $b[$k + 11] = $v;
        $this->n++;
    }
}
