<?php
declare(strict_types=1);

namespace MyPHP\UI;

use FFI\CData;
use MyPHP\Native\Mem;
use RuntimeException;

/**
 * Packs bitmaps into one texture, shelf by shelf: 8-bit glyph coverage
 * ($bpp 1) or RGBA images ($bpp 4). The renderer uploads the rows that
 * changed whenever $dirty is set.
 *
 * Each bitmap can go again on its own (remove, evict): a shelf keeps the
 * gaps its bitmaps left, a bitmap going into a gap as wide in a shelf as
 * high (a little higher at most); a shelf left empty takes bitmaps of any
 * height, split to theirs, and joins the empty shelves beside it. Each
 * bitmap remembers the frame it was last asked for ($frame, set by its
 * owner each frame: get, mark), and evict() frees the ones asked for
 * least recently, never one of this frame, whose quads may be in the
 * frame's display list already.
 */
final class Atlas
{
    /** The image, w*h*bpp bytes, row-major from the top. */
    public CData $pix;
    /** Whether something changed since the last upload, and the rows it spans: [first, past the last], so the renderer uploads those rows only. */
    public bool $dirty = false;
    public int $dirtyFrom = 0, $dirtyTo = 0;
    /** The frame now, set by the atlas's owner as each begins: what get() and mark() find is of it, evict() frees it not. */
    public int $frame = 0;
    /** How many bitmaps evict() freed in all: a diagnostic. */
    public int $evicted = 0;
    private int $base;
    /** @var list<array{y: int, h: int, gaps: list<array{0: int, 1: int}>, n: int}> the shelves from the top: their room, the gaps of it ([x, w]) and how many bitmaps they hold */
    private array $shelves = [];
    /** Where the room below the shelves begins. */
    private int $top = 0;
    /** @var array<string, array{u: int, v: int, w: int, h: int}> */
    private array $slots = [];
    /** @var array<string, int> the frame each slot was last asked for */
    private array $used = [];

    public function __construct(public int $w = 2048, public int $h = 2048, public int $bpp = 1)
    {
        $this->pix = Mem::alloc($w * $h * $bpp);
        $this->base = Mem::addr($this->pix);
    }

    /** The slot of $key, now asked for; null when the atlas has it not. */
    public function get(string $key): ?array
    {
        $s = $this->slots[$key] ?? null;
        if ($s !== null) { $this->used[$key] = $this->frame; }
        return $s;
    }

    /** Notes that slot $key is drawn from this frame, by what keeps its place (a line's quads). */
    public function mark(string $key): void
    {
        if (isset($this->slots[$key])) { $this->used[$key] = $this->frame; }
    }

    /** Forgets every bitmap; what used them must be added again. */
    public function reset(): void
    {
        $this->slots = []; $this->used = [];
        $this->shelves = []; $this->top = 0;
        $this->touch(0, $this->h);
    }

    /** Rows $from to $to changed: widen what the next upload covers. */
    private function touch(int $from, int $to): void
    {
        if (!$this->dirty) {
            $this->dirtyFrom = $from; $this->dirtyTo = $to;
        } else {
            $this->dirtyFrom = min($this->dirtyFrom, $from); $this->dirtyTo = max($this->dirtyTo, $to);
        }
        $this->dirty = true;
    }

    /**
     * Copies a w*h bitmap of bpp bytes per pixel (top row first) at integer
     * address $src into a free slot, of this frame; null when there is no
     * room for it (evict() makes some). A pixel apart from the others.
     * @return ?array{u: int, v: int, w: int, h: int}
     */
    public function add(string $key, int $w, int $h, int $src): ?array
    {
        if (isset($this->slots[$key])) { $this->remove($key); }
        $at = $this->room($w + 1, $h + 1, true);
        if ($at === null) {
            return null;
        }
        [$u, $v] = $at;
        $bpp = $this->bpp;
        for ($r = 0; $r < $h; $r++) {
            Mem::copy($this->base + (($v + $r) * $this->w + $u) * $bpp, $src + $r * $w * $bpp, $w * $bpp);
        }
        $this->touch($v, $v + $h);
        $this->used[$key] = $this->frame;
        return $this->slots[$key] = ['u' => $u, 'v' => $v, 'w' => $w, 'h' => $h];
    }

    /** Frees slot $key: its pixels cleared (a neighbor's edge samples them), its room a gap of its shelf. */
    public function remove(string $key): void
    {
        $s = $this->slots[$key] ?? null;
        if ($s === null) {
            return;
        }
        unset($this->slots[$key], $this->used[$key]);
        $bpp = $this->bpp;
        $zero = str_repeat("\0", $s['w'] * $bpp);
        for ($r = 0; $r < $s['h']; $r++) { Mem::write($this->base + (($s['v'] + $r) * $this->w + $s['u']) * $bpp, $zero); }
        $this->touch($s['v'], $s['v'] + $s['h']);
        foreach ($this->shelves as $i => $shelf) {
            if ($shelf['y'] !== $s['v']) { continue; }
            $gaps = $shelf['gaps'];
            $gaps[] = [$s['u'], $s['w'] + 1];
            usort($gaps, fn(array $a, array $b) => $a[0] <=> $b[0]);
            $merged = [];
            foreach ($gaps as $g) { // gaps side by side are one
                $last = count($merged) - 1;
                if ($last >= 0 && $merged[$last][0] + $merged[$last][1] >= $g[0]) { $merged[$last][1] = max($merged[$last][1], $g[0] + $g[1] - $merged[$last][0]); } else { $merged[] = $g; }
            }
            $this->shelves[$i]['gaps'] = $merged;
            if (--$this->shelves[$i]['n'] === 0) { $this->emptied($i); }
            return;
        }
        throw new RuntimeException("atlas: no shelf at {$s['v']} for $key");
    }

    /**
     * Frees the bitmaps asked for least recently, none of this frame, until
     * a w*h one fits; the keys freed (none when it fits without, or when
     * even freeing all those would not do).
     * @return list<string>
     */
    public function evict(int $w, int $h): array
    {
        if ($this->room($w + 1, $h + 1, false) !== null) {
            return [];
        }
        $old = array_filter($this->used, fn(int $f) => $f < $this->frame);
        asort($old);
        $gone = [];
        foreach ($old as $key => $f) {
            $this->remove((string)$key);
            $gone[] = (string)$key;
            if ($this->room($w + 1, $h + 1, false) !== null) {
                $this->evicted += count($gone);
                return $gone;
            }
        }
        $this->evicted += count($gone);
        return $gone;
    }

    /** How many bitmaps it holds. */
    public function count(): int
    {
        return count($this->slots);
    }

    /**
     * Room for a w*h bitmap (its pixel apart included): where it goes,
     * taken when $take; null for none. A gap of a shelf as high, or not
     * much higher; else a new shelf below them; else an empty shelf, split
     * to the bitmap's height.
     * @return ?array{0: int, 1: int}
     */
    private function room(int $w, int $h, bool $take): ?array
    {
        if ($w > $this->w + 1 || $h > $this->h + 1) {
            return null;
        }
        $best = null;
        foreach ($this->shelves as $i => $shelf) { // the lowest shelf of room, not much higher than the bitmap
            if ($shelf['n'] === 0 || $shelf['h'] < $h || $shelf['h'] > (int)ceil($h * 1.25) + 4 || ($best !== null && $shelf['h'] >= $this->shelves[$best[0]]['h'])) {
                continue;
            }
            foreach ($shelf['gaps'] as $g => [$gx, $gw]) {
                if ($gw >= $w) { $best = [$i, $g]; break; } // the gaps reach a pixel past the edge, where the last bitmap's pixel apart is
            }
        }
        if ($best === null) {
            $sh = min(($h + 3) & ~3, $this->h + 1 - $this->top); // a new shelf, its height in steps of four (its last row of pixels apart may lie past the edge)
            if ($sh >= $h) {
                if (!$take) { return [0, $this->top]; }
                $this->shelves[] = ['y' => $this->top, 'h' => $sh, 'gaps' => [[0, $this->w + 1]], 'n' => 0];
                $this->top += $sh;
                $best = [count($this->shelves) - 1, 0];
            } else {
                foreach ($this->shelves as $i => $shelf) { // an empty shelf of the room, the lowest
                    if ($shelf['n'] === 0 && $shelf['h'] >= $h && ($best === null || $shelf['h'] < $this->shelves[$best[0]]['h'])) { $best = [$i, 0]; }
                }
                if ($best === null) {
                    return null;
                }
                if (!$take) { return [0, $this->shelves[$best[0]]['y']]; }
                $shelf = $this->shelves[$best[0]];
                $sh = min(($h + 3) & ~3, $shelf['h']);
                if ($shelf['h'] - $sh >= 4) { // split: the rest an empty shelf of its own, below
                    array_splice($this->shelves, $best[0] + 1, 0, [['y' => $shelf['y'] + $sh, 'h' => $shelf['h'] - $sh, 'gaps' => [[0, $this->w + 1]], 'n' => 0]]);
                    $this->shelves[$best[0]]['h'] = $sh;
                }
            }
        }
        [$i, $g] = $best;
        [$gx, $gw] = $this->shelves[$i]['gaps'][$g];
        if (!$take) { return [$gx, $this->shelves[$i]['y']]; }
        if ($gw > $w) { $this->shelves[$i]['gaps'][$g] = [$gx + $w, $gw - $w]; } else { array_splice($this->shelves[$i]['gaps'], $g, 1); }
        $this->shelves[$i]['n']++;
        return [$gx, $this->shelves[$i]['y']];
    }

    /** Shelf $i holds nothing now: all its width a gap, one with the empty shelves beside it, the room below the shelves again when it is the last. */
    private function emptied(int $i): void
    {
        $this->shelves[$i]['gaps'] = [[0, $this->w + 1]];
        if ($i + 1 < count($this->shelves) && $this->shelves[$i + 1]['n'] === 0) { // the empty shelf below joins it
            $this->shelves[$i]['h'] += $this->shelves[$i + 1]['h'];
            array_splice($this->shelves, $i + 1, 1);
        }
        if ($i > 0 && $this->shelves[$i - 1]['n'] === 0) { // and it the empty shelf above
            $this->shelves[$i - 1]['h'] += $this->shelves[$i]['h'];
            array_splice($this->shelves, $i, 1);
            $i--;
        }
        if ($i === count($this->shelves) - 1) { // the last: the room below the shelves
            $this->top = $this->shelves[$i]['y'];
            array_pop($this->shelves);
        }
    }

    /** Copies the bitmap at $src, of the size it was added at, over the slot of $key, for the next upload; whether there was one. */
    public function replace(string $key, int $src): bool
    {
        $s = $this->slots[$key] ?? null;
        if ($s === null) {
            return false;
        }
        $bpp = $this->bpp;
        for ($r = 0; $r < $s['h']; $r++) {
            Mem::copy($this->base + (($s['v'] + $r) * $this->w + $s['u']) * $bpp, $src + $r * $s['w'] * $bpp, $s['w'] * $bpp);
        }
        $this->touch($s['v'], $s['v'] + $s['h']);
        return true;
    }
}
