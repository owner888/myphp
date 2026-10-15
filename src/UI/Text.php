<?php
declare(strict_types=1);

namespace MyPHP\UI;

use MyPHP\Native\{CoreText, Mem};

/**
 * Shaped lines, cached by size and string, painted as glyph quads from the
 * atlas. A cache hit costs one array lookup per frame. Lines unused for
 * KEEP_FRAMES frames are dropped, so a list scrolled through a million
 * strings keeps only what it showed lately. When the atlas fills, it and
 * the line cache start over: one frame re-shapes what is in view.
 */
final class Text implements TextMetrics
{
    private const KEEP_FRAMES = 30;
    private const SWEEP_EVERY = 15;

    /** @var array<string, array{w: float, h: float, ascent: float, quads: list<float|int>, gen: int}> quads: x y w h u v per glyph, flat */
    private array $lines = [];
    /** @var array<string, array{0: int, 1: int}> bitmap offsets by glyph key, in device pixels */
    private array $offsets = [];
    private int $gen = 0;
    /** How many times the atlas filled and was rebuilt; a diagnostic. */
    public int $resets = 0;

    public function __construct(private CoreText $ct, public GlyphAtlas $atlas) {}

    /** Call once per frame: ages the cache and sweeps stale lines now and then. */
    public function beginFrame(): void
    {
        $this->gen++;
        if ($this->gen % self::SWEEP_EVERY === 0) {
            $limit = $this->gen - self::KEEP_FRAMES;
            foreach ($this->lines as $key => $l) {
                if ($l['gen'] < $limit) {
                    unset($this->lines[$key]);
                }
            }
        }
    }

    public function lineCount(): int
    {
        return count($this->lines);
    }

    public function measure(string $text, float $size): array
    {
        $l = $this->line($text, $size);
        return [$l['w'], $l['h']];
    }

    public function xForIndex(string $text, float $size, int $byte): float
    {
        $carets = $this->line($text, $size)['carets'];
        $u = TextState::utf16Index($text, $byte);
        // Inside a ligature or cluster there is no caret; use the nearest before.
        while ($u > 0 && !isset($carets[$u])) {
            $u--;
        }
        return $carets[$u] ?? 0.0;
    }

    public function indexForX(string $text, float $size, float $x): int
    {
        $best = 0; $bestD = INF;
        foreach ($this->line($text, $size)['carets'] as $u => $cx) {
            if (($d = abs($cx - $x)) < $bestD) {
                $bestD = $d; $best = $u;
            }
        }
        return TextState::byteIndex($text, $best);
    }

    /** Paints $text with its top-left corner at ($x, $y), in points. */
    public function paint(DisplayList $list, string $text, float $size, float $x, float $y, int $rgb, float $alpha = 1.0): void
    {
        $l = $this->line($text, $size);
        $baseline = $y + $l['ascent'];
        $q = $l['quads'];
        for ($i = 0, $n = count($q); $i < $n; $i += 6) {
            $list->glyph($x + $q[$i], $baseline + $q[$i + 1], $q[$i + 2], $q[$i + 3], $rgb, $alpha, $q[$i + 4], $q[$i + 5]);
        }
    }

    private function line(string $text, float $size): array
    {
        $key = "$size\0$text";
        $l = &$this->lines[$key];
        if ($l === null) {
            $l = $this->shape($text, $size);
        }
        $l['gen'] = $this->gen;
        return $l;
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
                if (!$this->atlas->fits($bitmap['w'], $bitmap['h'])) {
                    // Start over: every cached line refers to atlas slots that are gone.
                    $this->atlas->reset();
                    $this->lines = [];
                    $this->offsets = [];
                    $this->resets++;
                }
                $slot = $this->atlas->add($key, $bitmap['w'], $bitmap['h'], $bitmap['addr']);
                Mem::free($bitmap['rows']);
                $this->offsets[$key] = [$bitmap['ox'], $bitmap['oy']];
            }
            [$ox, $oy] = $this->offsets[$key];
            // Snap the pen to device pixels so the bitmap lands on its own grid.
            $px = round($g['x'] * $s) / $s;
            $py = round(-$g['y'] * $s) / $s;
            array_push($quads, $px + $ox / $s, $py - ($oy + $slot['h']) / $s, $slot['w'] / $s, $slot['h'] / $s, $slot['u'], $slot['v']);
        }
        return [
            'w' => $shaped['width'], 'h' => $shaped['ascent'] + $shaped['descent'],
            'ascent' => $shaped['ascent'], 'quads' => $quads, 'carets' => $shaped['carets'], 'gen' => $this->gen,
        ];
    }
}
