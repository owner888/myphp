<?php
declare(strict_types=1);

namespace MyPHP\UI;

use FFI\CData;
use MyPHP\Native\Mem;
use RuntimeException;

/**
 * Packs bitmaps into one texture, shelf by shelf: 8-bit glyph coverage
 * ($bpp 1) or RGBA images ($bpp 4). The renderer uploads the whole image
 * whenever $dirty is set.
 */
final class Atlas
{
    /** The image, w*h*bpp bytes, row-major from the top. */
    public CData $pix;
    /** Whether something changed since the last upload, and the rows it spans: [first, past the last], so the renderer uploads those rows only. */
    public bool $dirty = false;
    public int $dirtyFrom = 0, $dirtyTo = 0;
    private int $base;
    private int $shelfX = 0, $shelfY = 0, $shelfH = 0;
    /** @var array<string, array{u: int, v: int, w: int, h: int}> */
    private array $slots = [];

    public function __construct(public int $w = 2048, public int $h = 2048, public int $bpp = 1)
    {
        $this->pix = Mem::alloc($w * $h * $bpp);
        $this->base = Mem::addr($this->pix);
    }

    public function get(string $key): ?array
    {
        return $this->slots[$key] ?? null;
    }

    /** Whether a w*h bitmap still fits. */
    public function fits(int $w, int $h): bool
    {
        $y = $this->shelfX + $w > $this->w ? $this->shelfY + $this->shelfH : $this->shelfY;
        return $y + $h <= $this->h;
    }

    /** Forgets every bitmap; what used them must be added again. */
    public function reset(): void
    {
        $this->slots = [];
        $this->shelfX = $this->shelfY = $this->shelfH = 0;
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

    /** Copies a w*h bitmap of bpp bytes per pixel (top row first) at integer address $src into a free slot. */
    public function add(string $key, int $w, int $h, int $src): array
    {
        if ($this->shelfX + $w > $this->w) {
            $this->shelfY += $this->shelfH;
            $this->shelfX = 0;
            $this->shelfH = 0;
        }
        if ($this->shelfY + $h > $this->h) {
            throw new RuntimeException('atlas full');
        }
        $u = $this->shelfX; $v = $this->shelfY;
        $bpp = $this->bpp;
        for ($r = 0; $r < $h; $r++) {
            Mem::copy($this->base + (($v + $r) * $this->w + $u) * $bpp, $src + $r * $w * $bpp, $w * $bpp);
        }
        $this->shelfX += $w + 1;
        $this->shelfH = max($this->shelfH, $h + 1);
        $this->touch($v, $v + $h);
        return $this->slots[$key] = ['u' => $u, 'v' => $v, 'w' => $w, 'h' => $h];
    }
}
