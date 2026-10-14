<?php
declare(strict_types=1);

namespace MyPHP\UI;

use MyPHP\Native\{CoreText, Mem};

/**
 * Shaped lines, cached by size and string, painted as glyph quads from the
 * atlas. A cache hit costs one array lookup per frame.
 */
final class Text implements TextMetrics
{
    /** @var array<string, array{w: float, h: float, ascent: float, quads: list<array{x: float, y: float, w: float, h: float, u: int, v: int}>}> */
    private array $lines = [];
    /** @var array<string, array{0: int, 1: int}> bitmap offsets by glyph key, in device pixels */
    private array $offsets = [];

    public function __construct(private CoreText $ct, public GlyphAtlas $atlas) {}

    public function measure(string $text, float $size): array
    {
        $l = $this->line($text, $size);
        return [$l['w'], $l['h']];
    }

    /** Paints $text with its top-left corner at ($x, $y), in points. */
    public function paint(DisplayList $list, string $text, float $size, float $x, float $y, int $rgb, float $alpha = 1.0): void
    {
        $l = $this->line($text, $size);
        $baseline = $y + $l['ascent'];
        foreach ($l['quads'] as $q) {
            $list->glyph($x + $q['x'], $baseline + $q['y'], $q['w'], $q['h'], $rgb, $alpha, $q['u'], $q['v']);
        }
    }

    private function line(string $text, float $size): array
    {
        return $this->lines["$size\0$text"] ??= $this->shape($text, $size);
    }

    private function shape(string $text, float $size): array
    {
        $s = $this->ct->scale;
        $shaped = $this->ct->shape($text, $size);
        $quads = [];
        foreach ($shaped['glyphs'] as $g) {
            $key = $g['font'] . ':' . $g['glyph'];
            $slot = $this->atlas->get($key);
            if ($slot === null) {
                $bitmap = $this->ct->rasterize($g['font'], $g['glyph']);
                if ($bitmap === null) {
                    continue;
                }
                $slot = $this->atlas->add($key, $bitmap['w'], $bitmap['h'], $bitmap['addr']);
                Mem::free($bitmap['rows']);
                $this->offsets[$key] = [$bitmap['ox'], $bitmap['oy']];
            }
            [$ox, $oy] = $this->offsets[$key];
            // Snap the pen to device pixels so the bitmap lands on its own grid.
            $px = round($g['x'] * $s) / $s;
            $py = round(-$g['y'] * $s) / $s;
            $quads[] = [
                'x' => $px + $ox / $s, 'y' => $py - ($oy + $slot['h']) / $s,
                'w' => $slot['w'] / $s, 'h' => $slot['h'] / $s, 'u' => $slot['u'], 'v' => $slot['v'],
            ];
        }
        return [
            'w' => $shaped['width'], 'h' => $shaped['ascent'] + $shaped['descent'],
            'ascent' => $shaped['ascent'], 'quads' => $quads,
        ];
    }
}
