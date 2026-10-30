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
    /** The bytes of a glyph of a shaped line: its pen's x and y (float64s), its font's id (int64), its glyph and its UTF-16 index (uint32s). */
    private const GLYPH_BYTES = 32;

    /** @var array<string, array{key: string, w: float, h: float, ascent: float, glyphs: string, quads: array<int, string>, carets: ?array<int, float>, hot?: array<int, array<int, float>>, gen: int}> glyphs: the line's shaped glyphs, packed; quads, by the shade of the text painted: x y w h u v per glyph, float32s packed (a kilobyte a line, not nine), unpacked in hot while the line is in view; carets only once a field asked */
    private array $lines = [];
    /** @var array<string, array{0: int, 1: int}> bitmap offsets by glyph key, in device pixels */
    private array $offsets = [];
    /** @var list<Image> the pictures of color glyphs, in the order made */
    private array $colors = [];
    /** @var array<string, int> the index in $colors of each color glyph, by glyph key */
    private array $colorIndex = [];
    private int $gen = 0;
    /** How many times the atlas filled and was rebuilt; a diagnostic. */
    public int $resets = 0;
    /** @var array<string, array<string, true>> the lines whose quads draw from each slot of the atlas, by its key (place: made again when it goes) */
    private array $users = [];

    /** @var list<array> what this frame drew from the atlases, in order (drawnSince): ['l', line, shade, its quads' make], ['a', glyph atlas key, u, v], ['i', image key, u, v, page], ['p', picture key] */
    private array $drawn = [];
    /** How many times a line's quads were made: which make a line's quads drawn are. */
    private int $makes = 0;

    /** Set when an atlas was reset during a paint: quads painted before the reset point at slots that are gone, so paint again. */
    public bool $repaint = false;

    /** @var array<string, Image> SVGs drawn in their own colors, by SVG, size and color, and the frame each was last drawn */
    private array $pictures = [], $pictureUsed = [];
    /** @var array<string, true|string> the tiles of large paths' masks found empty, and those covered whole, by key: the shared mask's key (pathMask) */
    private array $emptyTiles = [], $sharedTiles = [];

    private System $system;

    public function __construct(private CoreText $ct, public Atlas $atlas, public ImageAtlas $images)
    {
        $this->system = new System(new TextEngine($ct));
    }

    public function textSystem(): System
    {
        return $this->system;
    }

    public function paintLayout(DisplayList $list, Layout $l, float $x, float $y, \Closure $colorAt, float $stroke = 0.0, float $blur = 0.0, bool $cutOut = false): void
    {
        $s = $this->ct->scale;
        $stroke = round($stroke * 4) / 4; // the outlines of a few widths in the atlas, a quarter point apart
        $blur = round($blur * 8) / 8; // and blurs an eighth of a point apart
        foreach ($l->lines as $line) {
            $lastC = -2; $rgb = 0; $alpha = 1.0; $shade = 0;
            foreach ($line->glyphs as $g) {
                if ($g->object || $g->font === null) {
                    continue;
                }
                if ($g->cluster !== $lastC) { [$rgb, $alpha] = $colorAt($g->cluster); $lastC = $g->cluster; $shade = self::shadeOf($rgb); }
                if ($alpha <= 0) {
                    continue; // clear: nothing to draw
                }
                $glyph = $this->glyph($g->font->id, $g->id, $shade, $stroke, $blur, $cutOut && $stroke > 0);
                if ($glyph === null) {
                    continue; // no ink, as a space
                }
                [$slot, $color, $ox, $oy, $w, $h] = $glyph;
                if ($slot !== null) { $this->drawn[] = ['a', $glyph[6], $slot['u'], $slot['v']]; }
                // The pen and the baseline on device pixels, so the bitmap lands on its own grid.
                $px = round(($x + $g->x) * $s) / $s + $ox / $s;
                $py = round(($y + $g->y) * $s) / $s - ($oy + $h) / $s;
                if ($color !== null) {
                    $this->paintColor($list, $color, $px, $py, $alpha);
                } else {
                    $list->glyph($px, $py, $w / $s, $h / $s, $rgb, $alpha, $slot['u'], $slot['v']);
                }
            }
        }
    }

    public function glyphCoverage(array $glyphs, float $x, float $y, int $w, int $h, float $scale): string
    {
        $out = str_repeat("\0", $w * $h);
        foreach ($glyphs as $g) {
            if ($g->object || $g->font === null) {
                continue;
            }
            $b = $this->ct->rasterize($g->font->id, $g->id, $scale);
            if ($b === null) {
                continue; // no ink, as a space
            }
            $mask = \FFI::string($b['rows'], ($b['color'] ? 4 : 1) * $b['w'] * $b['h']);
            Mem::free($b['rows']);
            if ($b['color']) { // a color glyph covers as its alpha
                $mask = implode('', array_map(static fn(string $px): string => $px[3], str_split($mask, 4)));
            }
            // Placed as paintLayout places it: the pen on a whole pixel, the bitmap's bottom oy pixels below the baseline.
            $left = (int)round(($x + $g->x) * $scale) + $b['ox'];
            $top = (int)round(($y + $g->y) * $scale) - $b['oy'] - $b['h'];
            for ($r = max(0, -$top); $r < $b['h'] && $top + $r < $h; $r++) {
                $row = ($top + $r) * $w;
                for ($c = max(0, -$left); $c < $b['w'] && $left + $c < $w; $c++) {
                    $v = ord($mask[$r * $b['w'] + $c]);
                    if ($v > 0) {
                        $i = $row + $left + $c;
                        $o = ord($out[$i]);
                        $out[$i] = chr($o + intdiv($v * (255 - $o) + 127, 255)); // one glyph over another, as their quads blend
                    }
                }
            }
        }
        return $out;
    }

    /** The scale glyphs are rasterized at: when it changes, every glyph and line is made again. */
    public function rescale(float $scale): void
    {
        if ($scale === $this->ct->scale) {
            return;
        }
        $this->ct->scale = $scale;
        $this->atlas->reset();
        $this->lines = []; $this->users = [];
        $this->offsets = [];
        $this->colors = []; $this->colorIndex = [];
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
        if ($slot !== null && $image->updated) { // new pixels in the old slot (Image::update)
            $this->images->replace($image->key, $image->address());
            $image->uploaded();
        }
        $image->updated = false;
        if ($slot === null) {
            [$slot, $emptied] = $this->images->add($image->key, $image->w, $image->h, $image->address());
            $image->uploaded();
            if ($emptied) {
                $this->repaint = true;
            }
        }
        $this->drawn[] = ['i', $image->key, $slot['u'], $slot['v'], $slot['page'], $image];
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
            $pix = $rotate != 0 ? $svg->doc->rotated($pw, $ph, $cw, $ch, $rotate) : $svg->doc->pixels($pw, $ph);
            $mask = ''; // the coverage of the SVG, whatever its colors: its alpha
            for ($i = 3, $n = count($pix); $i < $n; $i += 4) { $mask .= chr($pix[$i]); }
            $rows = Mem::alloc($cw * $ch);
            \FFI::memcpy($rows, $mask, $cw * $ch);
            $slot = $this->place($key, $cw, $ch, Mem::addr($rows));
            Mem::free($rows);
        }
        $this->drawn[] = ['a', $key, $slot['u'], $slot['v']];
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
        $this->drawn[] = ['p', $key];
        return $this->imageSlot($pic);
    }

    /** The side of a tile of a path's mask too large for the atlas, in device pixels (mygo's pathTiles). */
    public const PATH_TILE = 511;

    /**
     * The glyph quads of $path filled, or stroked $width points wide (round
     * joins and caps), from masks cached in the atlas by the shape, as
     * mygo's drawPath: x, y, w, h in points and the slot's u, v each; none
     * for an empty path. The key covers the shape relative to the pixel
     * grid in quarters of a pixel, so a path looks the same wherever it is.
     * A mask the atlas cannot hold (past 4095 pixels a side) is drawn in
     * tiles of it, PATH_TILE a side (mygo's b0d5f43): each a mask of the
     * whole path cut to the tile, so tiles meet as the pixels of one mask
     * do; those $visible(x, y, w, h) says do not show, and those the path
     * leaves empty, are not made, and those it covers whole share one.
     * @param ?\Closure(float, float, float, float): bool $visible
     * @return list<array{0: float, 1: float, 2: float, 3: float, 4: int, 5: int}>
     */
    public function pathQuad(Path $path, float $width, ?\Closure $visible = null): array
    {
        $b = $path->bounds();
        if ($b === null) { return []; }
        $s = $this->ct->scale; $hw = $path->reach($width); // past half the width where square caps and miters reach
        $x0 = floor(($b[0] - $hw) * $s) / $s; $y0 = floor(($b[1] - $hw) * $s) / $s; // on the device grid
        $w = (int)ceil(($b[2] + $hw - $x0) * $s) + 1; $h = (int)ceil(($b[3] + $hw - $y0) * $s) + 1;
        $key = 'p' . max($width, 0) / 2;
        if ($width > 0 && ($path->cap !== Path::CAP_ROUND || $path->join !== Path::JOIN_ROUND || $path->dash !== [])) {
            $key .= "|$path->cap,$path->join," . ($path->join === Path::JOIN_MITER ? $path->miter : '') . '|' . implode(',', $path->dash);
            if ($path->dash !== []) { // where each subpath starts in the dashes, within one pattern, to a quarter of a pixel: an offset going around it again draws the same masks
                $total = array_sum($path->dash);
                foreach ($path->phases ?? [0.0] as $ph) { $o = fmod($path->dashOffset + $ph, $total); $key .= '@' . (int)round(($o < 0 ? $o + $total : $o) * $s * 4); }
            }
        }
        foreach ($path->cmds as $c) {
            $key .= chr($c[0]);
            $n = match ($c[0]) { Path::MOVE, Path::LINE => 1, Path::QUAD => 2, Path::CUBE => 3, default => 0 };
            for ($i = 0; $i < $n; $i++) { $key .= pack('VV', (int)round(($c[1 + 2 * $i] - $x0) * $s * 4) & 0xffffffff, (int)round(($c[2 + 2 * $i] - $y0) * $s * 4) & 0xffffffff); }
        }
        if ($w < 4096 && $h < 4096) { // the atlas keeps a pixel of padding beside each bitmap
            return [$this->pathMask($key, $path, $width, $x0, $y0, $w, $h)];
        }
        $t = self::PATH_TILE; $quads = [];
        for ($ty = 0; $ty < $h; $ty += $t) {
            for ($tx = 0; $tx < $w; $tx += $t) {
                [$tw, $th] = [min($t, $w - $tx), min($t, $h - $ty)];
                $qx = $x0 + $tx / $s; $qy = $y0 + $ty / $s;
                if ($visible !== null && !$visible($qx, $qy, $tw / $s, $th / $s)) { continue; }
                $q = $this->pathMask($key . "/$tx,$ty", $path, $width, $qx, $qy, $tw, $th);
                if ($q !== null) { $quads[] = $q; }
            }
        }
        return $quads;
    }

    /** The quad of the mask $key of $path, $w × $h pixels from ($x0, $y0) in points, made and placed in the atlas once; null for a tile the path leaves empty. @return ?array{0: float, 1: float, 2: float, 3: float, 4: int, 5: int} */
    private function pathMask(string $key, Path $path, float $width, float $x0, float $y0, int $w, int $h): ?array
    {
        $s = $this->ct->scale;
        if (isset($this->emptyTiles[$key])) { return null; }
        $slot = $this->atlas->get($this->sharedTiles[$key] ?? $key);
        if ($slot === null) {
            $mask = Svg\Mask::of($path, $width, $s, $x0, $y0, $w, $h);
            if (strspn($mask, "\0") === strlen($mask)) { $this->emptyTiles[$key] = true; return null; }
            if ($w === self::PATH_TILE && $h === self::PATH_TILE && strspn($mask, "\xff") === strlen($mask)) { $key = $this->sharedTiles[$key] = 'full-tile'; } // covered whole: one mask for all such
            $slot = $this->atlas->get($key);
            if ($slot === null) {
                $rows = Mem::alloc($w * $h);
                \FFI::memcpy($rows, $mask, $w * $h);
                $slot = $this->place($key, $w, $h, Mem::addr($rows));
                Mem::free($rows);
            }
        } else {
            $key = $this->sharedTiles[$key] ?? $key;
        }
        $this->drawn[] = ['a', $key, $slot['u'], $slot['v']];
        return [$x0, $y0, $slot['w'] / $s, $slot['h'] / $s, $slot['u'], $slot['v']];
    }

    /**
     * A glyph's bitmap: its mask's atlas slot, or, for a color glyph (an
     * emoji's), its picture, which the image atlas holds as mygo's color
     * atlas; with its offsets and size in pixels; null without ink.
     * @return ?array{0: ?array{u: int, v: int, w: int, h: int}, 1: ?Image, 2: int, 3: int, 4: int, 5: int, 6?: string} 6: the mask's key
     */
    private function glyph(int $font, int $id, int $shade, float $stroke = 0.0, float $blur = 0.0, bool $cutOut = false): ?array
    {
        $base = "$font:$id";
        if ($stroke > 0 || $blur > 0) { // its outline (a mask of the glyph grown by it, the glyph cut out of it or not), or it blurred: unsmoothed, of no shade
            $key = "$base:o$stroke" . ($blur > 0 ? ":b$blur" : '') . ($cutOut ? ':c' : '');
            $slot = $this->atlas->get($key);
            $b = $slot !== null || isset($this->colorIndex[$base]) ? null : $this->ct->rasterize($font, $id, null, -1, $stroke, $blur, $cutOut);
            if ($b !== null) {
                $slot = $this->place($key, $b['w'], $b['h'], $b['addr']);
                Mem::free($b['rows']);
                $this->offsets[$key] = [$b['ox'], $b['oy']];
            }
            if ($slot !== null) {
                [$ox, $oy] = $this->offsets[$key];
                return [$slot, null, $ox, $oy, $slot['w'], $slot['h'], $key];
            }
            if ($stroke > 0 || !$this->ct->isColor($font)) {
                return null; // no ink, or a color glyph's outline: it has none
            }
            // a color glyph blurred: it is its own picture, drawn as it is
        }
        if (isset($this->colorIndex[$base])) {
            $img = $this->colors[$this->colorIndex[$base]];
            [$ox, $oy] = $this->offsets[$base];
            return [null, $img, $ox, $oy, $img->w, $img->h];
        }
        $key = "$base:$shade"; // a mask for each shade of text, as font smoothing draws it
        $slot = $this->atlas->get($key);
        if ($slot === null) {
            $b = $this->ct->rasterize($font, $id, null, $shade);
            if ($b === null) {
                return null;
            }
            if ($b['color']) {
                $img = Image::fromPixels($b['w'], $b['h'], \FFI::string($b['rows'], 4 * $b['w'] * $b['h']));
                Mem::free($b['rows']);
                $this->colorIndex[$base] = count($this->colors);
                $this->colors[] = $img;
                $this->offsets[$base] = [$b['ox'], $b['oy']];
                return [null, $img, $b['ox'], $b['oy'], $b['w'], $b['h']];
            }
            $slot = $this->place($key, $b['w'], $b['h'], $b['addr']);
            Mem::free($b['rows']);
            $this->offsets[$key] = [$b['ox'], $b['oy']];
        }
        [$ox, $oy] = $this->offsets[$key];
        return [$slot, null, $ox, $oy, $slot['w'], $slot['h'], $key];
    }

    /**
     * Puts the w*h bitmap at $src in the atlas as $key: where there is
     * room, else where the bitmaps drawn least recently were (Atlas::evict,
     * none this frame draws from: the cached lines' quads made again from
     * what is left, as they may name a slot gone), else in the atlas
     * started over, as a frame that draws more than it holds must.
     * @return array{u: int, v: int, w: int, h: int}
     */
    private function place(string $key, int $w, int $h, int $src): array
    {
        $slot = $this->atlas->add($key, $w, $h, $src);
        if ($slot === null) {
            foreach ($this->atlas->evict($w, $h) as $k) { // the lines drawn from it made again from what is left
                unset($this->offsets[$k]);
                foreach ($this->users[$k] ?? [] as $line => $_) {
                    if (isset($this->lines[$line])) { $this->lines[$line]['quads'] = []; unset($this->lines[$line]['hot'], $this->lines[$line]['marked'], $this->lines[$line]['made']); }
                }
                unset($this->users[$k]);
            }
            $slot = $this->atlas->add($key, $w, $h, $src);
        }
        if ($slot === null) {
            $this->resetAtlas();
            $slot = $this->atlas->add($key, $w, $h, $src) ?? throw new \RuntimeException("myphp: a bitmap of {$w}x{$h} is larger than the glyph atlas");
        }
        return $slot;
    }

    /** Starts the atlas over: every cached line refers to slots that are gone; the color glyphs, in the image atlas, keep theirs. */
    private function resetAtlas(): void
    {
        $this->atlas->reset(); $this->lines = []; $this->users = []; $this->resets++; $this->repaint = true;
        $this->offsets = array_intersect_key($this->offsets, $this->colorIndex);
    }

    /** A color glyph's picture with its top left at ($x, $y), at $alpha: in its own colors, as mygo's. */
    private function paintColor(DisplayList $list, Image $img, float $x, float $y, float $alpha): void
    {
        $s = $this->ct->scale;
        $slot = $this->imageSlot($img);
        $list->image($x, $y, $img->w / $s, $img->h / $s, $slot['u'], $slot['v'], $img->w, $img->h, $slot['page'], $alpha);
    }

    /** Call once per frame: ages the cache and sweeps stale lines now and then. */
    public function beginFrame(): void
    {
        $this->gen++;
        $this->drawn = [];
        $this->atlas->frame = $this->gen; // what this frame draws from stays
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
            foreach ($this->users as $k => $lines) { // the lines gone let go of the slots they drew from
                $lines = array_intersect_key($lines, $this->lines);
                if ($lines === []) { unset($this->users[$k]); } else { $this->users[$k] = $lines; }
            }
            foreach ($this->pictureUsed as $key => $used) { // the pictures no frame drew for a while are forgotten (mygo's prunePictures)
                if ($used < $limit) { unset($this->pictures[$key], $this->pictureUsed[$key]); }
            }
        }
    }

    public function drawnMark(): int
    {
        return count($this->drawn);
    }

    public function drawnSince(int $mark): array
    {
        $out = [];
        foreach (array_slice($this->drawn, $mark) as $d) {
            if ($d[0] === 'l') { $d[] = $this->lines[$d[1]]['made'][$d[2]] ?? 0; } // the make of its quads drawn
            $out[] = $d;
        }
        return $out;
    }

    public function drawnAgain(array $drawn): bool
    {
        foreach ($drawn as $d) {
            switch ($d[0]) {
                case 'l':
                    if (($this->lines[$d[1]]['made'][$d[2]] ?? -1) !== $d[3]) { return false; } // its quads made again since, or gone with a slot evicted
                    $this->lines[$d[1]]['gen'] = $this->gen;
                    $this->quads($d[1], $d[2]); // its slots kept for this frame, its quads hot
                    break;
                case 'a':
                    $slot = $this->atlas->get($d[1]);
                    if ($slot === null || $slot['u'] !== $d[2] || $slot['v'] !== $d[3]) { return false; }
                    $this->drawn[] = $d;
                    break;
                case 'i':
                    $slot = $this->images->get($d[1]);
                    if ($slot === null || $slot['u'] !== $d[2] || $slot['v'] !== $d[3] || $slot['page'] !== $d[4] || $d[5]->updated) { return false; }
                    $this->drawn[] = $d;
                    break;
                case 'p':
                    if (!isset($this->pictures[$d[1]])) { return false; }
                    $this->pictureUsed[$d[1]] = $this->gen;
                    $this->drawn[] = $d;
                    break;
            }
        }
        return true;
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

    public function minContentWidth(string $text, float $size, int $style = 0): float
    {
        return $this->line($text, $size, $style)['min'];
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
        if ($l['glyphs'] === '') { return; }
        $q = $this->quads($l['key'], self::shadeOf($rgb));
        for ($i = 1, $n = count($q); $i <= $n; $i += 7) {
            if ($q[$i + 4] < 0) { // a color glyph: v is its picture
                $this->paintColor($list, $this->colors[(int)$q[$i + 5]], $x + $q[$i], $baseline + $q[$i + 1], $alpha);
                continue;
            }
            $list->glyph($x + $q[$i], $baseline + $q[$i + 1], $q[$i + 2], $q[$i + 3], $rgb, $alpha, (int)$q[$i + 4], (int)$q[$i + 5]);
        }
    }

    public function paintRuns(DisplayList $list, string $text, float $size, float $x, float $y, int $style, \Closure $colorAt): void
    {
        $l = $this->line($text, $size, $style);
        $baseline = $y + $l['ascent'];
        if ($l['glyphs'] === '') { return; }
        $bytes = null; // the byte each UTF-16 index starts at, for text beyond ASCII
        if (preg_match('/[\x80-\xFF]/', $text) === 1) {
            $bytes = []; $b = 0;
            foreach (mb_str_split($text, 1, 'UTF-8') as $r) { $bytes[] = $b; if (strlen($r) === 4) { $bytes[] = $b; } $b += strlen($r); }
            $bytes[] = $b;
        }
        $key = $l['key'];
        $q = $this->quads($key, 0); // the glyphs are alike in every shade's quads: the indexes are read from these
        $qs = [];
        $lastU = -1; $rgb = 0; $alpha = 1.0; $shown = $q;
        for ($i = 1, $n = count($q); $i <= $n; $i += 7) {
            $u = (int)$q[$i + 6];
            if ($u !== $lastU) {
                [$rgb, $alpha] = $colorAt($bytes === null ? $u : $bytes[$u]); $lastU = $u;
                $shade = self::shadeOf($rgb);
                $shown = $qs[$shade] ??= $shade === 0 ? $q : $this->quads($key, $shade);
            }
            if ($shown[$i + 4] < 0) {
                $this->paintColor($list, $this->colors[(int)$shown[$i + 5]], $x + $shown[$i], $baseline + $shown[$i + 1], $alpha);
                continue;
            }
            $list->glyph($x + $shown[$i], $baseline + $shown[$i + 1], $shown[$i + 2], $shown[$i + 3], $rgb, $alpha, (int)$shown[$i + 4], (int)$shown[$i + 5]);
        }
    }

    /**
     * The quads of line $key for text of $shade, unpacked (1-based): x, y,
     * w, h, u, v and the UTF-16 index of each glyph, in its bitmap for the
     * shade; packed with the line, unpacked while the line is in view.
     * @return array<int, float>
     */
    private function quads(string $key, int $shade): array
    {
        $this->drawn[] = ['l', $key, $shade];
        if (($this->lines[$key]['marked'][$shade] ?? -1) !== $this->gen && isset($this->lines[$key]['slots'][$shade])) { // its glyphs drawn from this frame: kept in the atlas (Atlas::evict)
            foreach ($this->lines[$key]['slots'][$shade] as $k) { $this->atlas->mark($k); }
            $this->lines[$key]['marked'][$shade] = $this->gen;
        }
        if (isset($this->lines[$key]['hot'][$shade])) { return $this->lines[$key]['hot'][$shade]; }
        $packed = $this->lines[$key]['quads'][$shade] ?? null;
        if ($packed === null) {
            $s = $this->ct->scale;
            $packed = ''; $slots = [];
            foreach (str_split($this->lines[$key]['glyphs'], self::GLYPH_BYTES) as $g) {
                ['x' => $px, 'y' => $py, 'font' => $font, 'id' => $id, 'index' => $index] = unpack('ex/ey/qfont/Vid/Vindex', $g);
                $glyph = $this->glyph($font, $id, $shade);
                if ($glyph === null) { continue; } // no ink, as a space, in every shade
                [$slot, $color, $ox, $oy, $w, $h] = $glyph;
                if ($color === null) { $slots["$font:$id:$shade"] = true; }
                [$u, $v] = $color === null ? [$slot['u'], $slot['v']] : [-1, $this->colorIndex["$font:$id"]]; // a color glyph's u is -1, its v the index of its picture
                $packed .= pack('g7', $px + $ox / $s, $py - ($oy + $h) / $s, $w / $s, $h / $s, $u, $v, $index);
            }
            if (!isset($this->lines[$key])) { // the atlas started over meanwhile: the frame paints again
                return unpack('g*', $packed) ?: [];
            }
            $this->lines[$key]['quads'][$shade] = $packed;
            $this->lines[$key]['made'][$shade] = ++$this->makes;
            $this->lines[$key]['slots'][$shade] = array_keys($slots);
            foreach ($slots as $k => $_) { $this->users[$k][$key] = true; }
            $this->lines[$key]['marked'][$shade] = $this->gen;
        }
        return $this->lines[$key]['hot'][$shade] = $packed === '' ? [] : unpack('g*', $packed);
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
        $glyphs = '';
        foreach ($shaped['glyphs'] as $g) {
            // The pen snapped to device pixels, so the bitmap lands on its own grid.
            $glyphs .= pack('eeqVV', round($g['x'] * $s) / $s, round(-$g['y'] * $s) / $s, $g['font'], $g['glyph'], $g['index']);
        }
        return [
            'key' => "$size:$style\0$text", 'w' => $shaped['width'], 'min' => self::minOf($text, $shaped['carets']), 'h' => $shaped['ascent'] + $shaped['descent'],
            'ascent' => $shaped['ascent'], 'glyphs' => $glyphs, 'quads' => [], 'carets' => $carets ? $shaped['carets'] : null, 'gen' => $this->gen,
        ];
    }

    /** The min-content width of a shaped line, by its carets (UTF-16 indexes to x): the pen before each rune, a rune inside a cluster at the cluster's. @param array<int, float> $carets */
    private static function minOf(string $text, array $carets): float
    {
        $runes = \MyPHP\Text\System::runes($text);
        $x = []; $u = 0; $last = 0.0;
        foreach ($runes as $i => $r) {
            $last = $carets[$u] ?? $last;
            $x[$i] = $last;
            $u += $r >= 0x10000 ? 2 : 1;
        }
        $x[count($runes)] = $carets[$u] ?? $last;
        return \MyPHP\Text\LineBreaks::widest($runes, $x);
    }

    /**
     * The shade of text of $rgb (mygo's ShadeOf): its relative luminance
     * rounded to a quarter, at most 3/4, by which Core Text's font
     * smoothing emboldens it, the lighter the more.
     */
    public static function shadeOf(int $rgb): int
    {
        static $linear = null;
        $linear ??= array_map(static function (int $i): float { $v = $i / 255; return $v <= 0.04045 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4; }, range(0, 255));
        $y = 0.2126 * $linear[$rgb >> 16 & 255] + 0.7152 * $linear[$rgb >> 8 & 255] + 0.0722 * $linear[$rgb & 255];
        return (int)min($y * 4 + 0.5, 3);
    }
}
