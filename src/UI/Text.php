<?php
declare(strict_types=1);

namespace MyPHP\UI;

use MyPHP\Native\{CoreText, Mem, TextEngine};
use MyPHP\Text\{Layout, System};

/**
 * Shaped lines, cached by size and string, painted as glyph quads from the
 * atlas. A cache hit costs one array lookup per frame. Lines unused for
 * KEEP_FRAMES frames are dropped, so a list scrolled through a million
 * strings keeps only what it showed lately. When the atlas fills, it and
 * the line cache start over: one frame re-shapes what is in view.
 */
final class Text implements TextPainter
{
    private const KEEP_FRAMES = 30;
    private const SWEEP_EVERY = 15;

    /** @var array<string, array{key: string, w: float, h: float, ascent: float, quads: string, carets: ?array<int, float>, hot?: array, gen: int}> quads: x y w h u v per glyph, float32s packed (a kilobyte a line, not nine), unpacked in hot while the line is in view; carets only once a field asked */
    private array $lines = [];
    /** @var array<string, array{0: int, 1: int}> bitmap offsets by glyph key, in device pixels */
    private array $offsets = [];
    private int $gen = 0;
    /** How many times the atlas filled and was rebuilt; a diagnostic. */
    public int $resets = 0;

    /** Set when an atlas was reset during a paint: quads painted before the reset point at slots that are gone, so paint again. */
    public bool $repaint = false;

    /** @var array<string, Image> SVGs drawn in their own colors, by SVG, size and color, and the frame each was last drawn */
    private array $pictures = [], $pictureUsed = [];

    private System $system;

    public function __construct(private CoreText $ct, public Atlas $atlas, public ImageAtlas $images)
    {
        $this->system = new System(new TextEngine($ct));
    }

    public function textSystem(): System
    {
        return $this->system;
    }

    public function paintLayout(DisplayList $list, Layout $l, float $x, float $y, \Closure $colorAt): void
    {
        $s = $this->ct->scale;
        foreach ($l->lines as $line) {
            $lastC = -2; $rgb = 0; $alpha = 1.0;
            foreach ($line->glyphs as $g) {
                if ($g->object || $g->font === null) {
                    continue;
                }
                $key = $g->font->id . ':' . $g->id;
                $slot = $this->atlas->get($key);
                if ($slot === null) {
                    $bitmap = $this->ct->rasterize($g->font->id, $g->id);
                    if ($bitmap === null) {
                        continue; // no ink, as a space
                    }
                    if (!$this->atlas->fits($bitmap['w'], $bitmap['h'])) {
                        $this->atlas->reset(); $this->lines = []; $this->offsets = []; $this->resets++; $this->repaint = true;
                    }
                    $slot = $this->atlas->add($key, $bitmap['w'], $bitmap['h'], $bitmap['addr']);
                    Mem::free($bitmap['rows']);
                    $this->offsets[$key] = [$bitmap['ox'], $bitmap['oy']];
                }
                [$ox, $oy] = $this->offsets[$key];
                if ($g->cluster !== $lastC) { [$rgb, $alpha] = $colorAt($g->cluster); $lastC = $g->cluster; }
                // The pen and the baseline on device pixels, so the bitmap lands on its own grid.
                $px = round(($x + $g->x) * $s) / $s;
                $py = round(($y + $g->y) * $s) / $s;
                $list->glyph($px + $ox / $s, $py - ($oy + $slot['h']) / $s, $slot['w'] / $s, $slot['h'] / $s, $rgb, $alpha, $slot['u'], $slot['v']);
            }
        }
    }

    /** The scale glyphs are rasterized at: when it changes, every glyph and line is made again. */
    public function rescale(float $scale): void
    {
        if ($scale === $this->ct->scale) {
            return;
        }
        $this->ct->scale = $scale;
        $this->atlas->reset();
        $this->lines = [];
        $this->offsets = [];
        $this->repaint = true;
    }

    public function scale(): float
    {
        return $this->ct->scale;
    }

    /** The image atlas slot of $image, added to a page on first use; a page emptied for it has this frame painted again. */
    public function imageSlot(Image $image): array
    {
        $slot = $this->images->get($image->key);
        if ($slot === null) {
            [$slot, $emptied] = $this->images->add($image->key, $image->w, $image->h, $image->addr);
            if ($emptied) {
                $this->repaint = true;
            }
        }
        return $slot;
    }

    public function iconQuad(SVG $svg, float $x, float $y, float $w, float $h, float $rotate = 0.0): ?array
    {
        $s = $this->ct->scale;
        $dx = round($x * $s) / $s; $dy = round($y * $s) / $s; // snapped to the device grid
        $pw = (int)round($w * $s); $ph = (int)round($h * $s);
        if ($pw <= 0 || $ph <= 0 || $pw > 4096 || $ph > 4096) { return null; }
        // Turned by a sixteenth of a degree at the finest, in a mask as large as it then covers, around the same center.
        $rotate = fmod($rotate, 360); if ($rotate < 0) { $rotate += 360; }
        $rotate = round($rotate * 16) / 16; if ($rotate == 360) { $rotate = 0.0; }
        $cw = $pw; $ch = $ph;
        if ($rotate != 0) {
            $sin = abs(sin($rotate * M_PI / 180)); $cos = abs(cos($rotate * M_PI / 180));
            $cw = (int)ceil($pw * $cos + $ph * $sin) + 2; $ch = (int)ceil($pw * $sin + $ph * $cos) + 2;
            $cw += ($cw - $pw) & 1; $ch += ($ch - $ph) & 1; // of the same parity, so that the center stays on the grid
        }
        $key = "s{$svg->id}/{$pw}x{$ph}/$rotate";
        $slot = $this->atlas->get($key);
        if ($slot === null) {
            if (!$this->atlas->fits($cw, $ch)) {
                $this->atlas->reset(); $this->lines = []; $this->offsets = []; $this->resets++; $this->repaint = true;
            }
            $pix = $rotate != 0 ? $svg->doc->rotated($pw, $ph, $cw, $ch, $rotate) : $svg->doc->pixels($pw, $ph);
            $mask = ''; // the coverage of the SVG, whatever its colors: its alpha
            for ($i = 3, $n = count($pix); $i < $n; $i += 4) { $mask .= chr($pix[$i]); }
            $rows = Mem::alloc($cw * $ch);
            \FFI::memcpy($rows, $mask, $cw * $ch);
            $slot = $this->atlas->add($key, $cw, $ch, Mem::addr($rows));
            Mem::free($rows);
        }
        return [$dx - ($cw - $pw) / 2 / $s, $dy - ($ch - $ph) / 2 / $s, $cw / $s, $ch / $s, $slot['u'], $slot['v']];
    }

    public function svgSlot(SVG $svg, int $w, int $h, int $current): array
    {
        $key = "p{$svg->id}/{$w}x{$h}/" . ($svg->doc->usesCurrentColor ? $current : ''); // the color matters only where currentColor is drawn
        $pic = $this->pictures[$key] ?? null;
        if ($pic === null) {
            $pix = $svg->doc->pixels($w, $h, [($current >> 16 & 255) / 255, ($current >> 8 & 255) / 255, ($current & 255) / 255, 1.0], true);
            $pic = Image::fromPixels($w, $h, pack('C*', ...$pix));
            $this->pictures[$key] = $pic;
        }
        $this->pictureUsed[$key] = $this->gen;
        return $this->imageSlot($pic);
    }

    /**
     * The glyph quad of $path filled, or stroked $width points wide (round
     * joins and caps), from a mask cached in the atlas by the shape, as
     * mygo's drawPath: x, y, w, h in points and the slot's u, v, or null
     * for an empty path. The key covers the shape relative to the pixel
     * grid in quarters of a pixel, so a path looks the same wherever it is.
     * @return array{0: float, 1: float, 2: float, 3: float, 4: int, 5: int}|null
     */
    public function pathQuad(Path $path, float $width): ?array
    {
        $b = $path->bounds();
        if ($b === null) { return null; }
        $s = $this->ct->scale; $hw = max($width, 0) / 2;
        $x0 = floor(($b[0] - $hw) * $s) / $s; $y0 = floor(($b[1] - $hw) * $s) / $s; // on the device grid
        $w = (int)ceil(($b[2] + $hw - $x0) * $s) + 1; $h = (int)ceil(($b[3] + $hw - $y0) * $s) + 1;
        if ($w > 4096 || $h > 4096) { return null; }
        $key = 'p' . $hw;
        foreach ($path->cmds as $c) {
            $key .= chr($c[0]);
            $n = match ($c[0]) { Path::MOVE, Path::LINE => 1, Path::QUAD => 2, Path::CUBE => 3, default => 0 };
            for ($i = 0; $i < $n; $i++) { $key .= pack('vv', (int)round(($c[1 + 2 * $i] - $x0) * $s * 4), (int)round(($c[2 + 2 * $i] - $y0) * $s * 4)); }
        }
        $slot = $this->atlas->get($key);
        if ($slot === null) {
            if (!$this->atlas->fits($w, $h)) {
                $this->atlas->reset(); $this->lines = []; $this->offsets = []; $this->resets++; $this->repaint = true;
            }
            $rows = Mem::alloc($w * $h);
            \FFI::memcpy($rows, Svg\Mask::of($path, $width, $s, $x0, $y0, $w, $h), $w * $h);
            $slot = $this->atlas->add($key, $w, $h, Mem::addr($rows));
            Mem::free($rows);
        }
        return [$x0, $y0, $slot['w'] / $s, $slot['h'] / $s, $slot['u'], $slot['v']];
    }

    /** Call once per frame: ages the cache and sweeps stale lines now and then. */
    public function beginFrame(): void
    {
        $this->gen++;
        $this->system->endFrame();
        $this->images->beginFrame();
        if ($this->gen % self::SWEEP_EVERY === 0) {
            $limit = $this->gen - self::KEEP_FRAMES;
            foreach ($this->lines as $key => $l) {
                if ($l['gen'] < $limit) {
                    unset($this->lines[$key]);
                } elseif ($l['gen'] < $this->gen - 1 && isset($l['hot'])) {
                    unset($this->lines[$key]['hot']); // out of view: the packed quads are enough until it shows again
                }
            }
            foreach ($this->pictureUsed as $key => $used) { // the pictures no frame drew for a while are forgotten (mygo's prunePictures)
                if ($used < $limit) { unset($this->pictures[$key], $this->pictureUsed[$key]); }
            }
        }
    }

    /** How many glyph bitmaps the atlas holds: a diagnostic. */
    public function glyphCount(): int
    {
        return count($this->offsets);
    }

    public function lineCount(): int
    {
        return count($this->lines);
    }

    public function measure(string $text, float $size, int $style = 0): array
    {
        $l = $this->line($text, $size, $style);
        return [$l['w'], $l['h']];
    }

    public function ascent(float $size, int $style = 0): float
    {
        return $this->line('X', $size, $style)['ascent'];
    }

    public function xForIndex(string $text, float $size, int $byte, int $style = 0): float
    {
        $carets = $this->carets($text, $size, $style);
        $u = TextState::utf16Index($text, $byte);
        // Inside a ligature or cluster there is no caret; use the nearest before.
        while ($u > 0 && !isset($carets[$u])) {
            $u--;
        }
        return $carets[$u] ?? 0.0;
    }

    public function indexForX(string $text, float $size, float $x, int $style = 0): int
    {
        $best = 0; $bestD = INF;
        foreach ($this->carets($text, $size, $style) as $u => $cx) {
            if (($d = abs($cx - $x)) < $bestD) {
                $bestD = $d; $best = $u;
            }
        }
        return TextState::byteIndex($text, $best);
    }

    /** Paints $text with its top-left corner at ($x, $y), in points. */
    public function paint(DisplayList $list, string $text, float $size, float $x, float $y, int $rgb, float $alpha = 1.0, int $style = 0): void
    {
        $l = $this->line($text, $size, $style);
        $baseline = $y + $l['ascent'];
        if ($l['quads'] === '') { return; }
        $q = $this->lines[$l['key']]['hot'] ??= unpack('g*', $l['quads']); // unpacked once for the lines in view (1-based), dropped by the sweep once they leave it
        for ($i = 1, $n = count($q); $i <= $n; $i += 7) {
            $list->glyph($x + $q[$i], $baseline + $q[$i + 1], $q[$i + 2], $q[$i + 3], $rgb, $alpha, (int)$q[$i + 4], (int)$q[$i + 5]);
        }
    }

    public function paintRuns(DisplayList $list, string $text, float $size, float $x, float $y, int $style, \Closure $colorAt): void
    {
        $l = $this->line($text, $size, $style);
        $baseline = $y + $l['ascent'];
        if ($l['quads'] === '') { return; }
        $q = $this->lines[$l['key']]['hot'] ??= unpack('g*', $l['quads']);
        $bytes = null; // the byte each UTF-16 index starts at, for text beyond ASCII
        if (preg_match('/[\x80-\xFF]/', $text) === 1) {
            $bytes = []; $b = 0;
            foreach (mb_str_split($text, 1, 'UTF-8') as $r) { $bytes[] = $b; if (strlen($r) === 4) { $bytes[] = $b; } $b += strlen($r); }
            $bytes[] = $b;
        }
        $lastU = -1; $rgb = 0; $alpha = 1.0;
        for ($i = 1, $n = count($q); $i <= $n; $i += 7) {
            $u = (int)$q[$i + 6];
            if ($u !== $lastU) { [$rgb, $alpha] = $colorAt($bytes === null ? $u : $bytes[$u]); $lastU = $u; }
            $list->glyph($x + $q[$i], $baseline + $q[$i + 1], $q[$i + 2], $q[$i + 3], $rgb, $alpha, (int)$q[$i + 4], (int)$q[$i + 5]);
        }
    }

    private function line(string $text, float $size, int $style = 0): array
    {
        $key = "$size:$style\0$text";
        $l = &$this->lines[$key];
        if ($l === null) {
            $l = $this->shape($text, $size, $style);
        }
        $l['gen'] = $this->gen;
        return $l;
    }

    /** The carets of $text at $size: the x of the caret before each UTF-16 index at a glyph boundary; kept with the line once a field asks (most lines are never edited). @return array<int, float> */
    private function carets(string $text, float $size, int $style = 0): array
    {
        $key = "$size:$style\0$text";
        $l = &$this->lines[$key];
        if ($l === null || $l['carets'] === null) {
            $l = $this->shape($text, $size, $style, true);
        }
        $l['gen'] = $this->gen;
        return $l['carets'];
    }

    private function shape(string $text, float $size, int $style, bool $carets = false): array
    {
        $s = $this->ct->scale;
        $shaped = $this->ct->shape($text, $size, $style);
        $quads = '';
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
                    $this->repaint = true;
                }
                $slot = $this->atlas->add($key, $bitmap['w'], $bitmap['h'], $bitmap['addr']);
                Mem::free($bitmap['rows']);
                $this->offsets[$key] = [$bitmap['ox'], $bitmap['oy']];
            }
            [$ox, $oy] = $this->offsets[$key];
            // Snap the pen to device pixels so the bitmap lands on its own grid.
            $px = round($g['x'] * $s) / $s;
            $py = round(-$g['y'] * $s) / $s;
            $quads .= pack('g7', $px + $ox / $s, $py - ($oy + $slot['h']) / $s, $slot['w'] / $s, $slot['h'] / $s, $slot['u'], $slot['v'], $g['index']);
        }
        return [
            'key' => "$size:$style\0$text", 'w' => $shaped['width'], 'h' => $shaped['ascent'] + $shaped['descent'],
            'ascent' => $shaped['ascent'], 'quads' => $quads, 'carets' => $carets ? $shaped['carets'] : null, 'gen' => $this->gen,
        ];
    }
}
