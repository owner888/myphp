<?php
declare(strict_types=1);

namespace MyPHP\UI;

use FFI\CData;
use MyPHP\Native\Mem;
use RuntimeException;

/**
 * Packs glyph coverage bitmaps into one 8-bit texture, shelf by shelf.
 * The renderer uploads the whole image whenever $dirty is set.
 */
final class GlyphAtlas
{
    /** The image, w*h bytes, row-major from the top. */
    public CData $pix;
    public bool $dirty = false;
    private int $base;
    private int $shelfX = 0, $shelfY = 0, $shelfH = 0;
    /** @var array<string, array{u: int, v: int, w: int, h: int}> */
    private array $slots = [];

    public function __construct(public int $w = 2048, public int $h = 2048)
    {
        $this->pix = Mem::alloc($w * $h);
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

    /** Forgets every glyph; the lines that used them must be shaped again. */
    public function reset(): void
    {
        $this->slots = [];
        $this->shelfX = $this->shelfY = $this->shelfH = 0;
        $this->dirty = true;
    }

    /** Copies a w*h bitmap (top row first) at integer address $src into a free slot. */
    public function add(string $key, int $w, int $h, int $src): array
    {
        if ($this->shelfX + $w > $this->w) {
            $this->shelfY += $this->shelfH;
            $this->shelfX = 0;
            $this->shelfH = 0;
        }
        if ($this->shelfY + $h > $this->h) {
            throw new RuntimeException('glyph atlas full');
        }
        $u = $this->shelfX; $v = $this->shelfY;
        for ($r = 0; $r < $h; $r++) {
            Mem::copy($this->base + ($v + $r) * $this->w + $u, $src + $r * $w, $w);
        }
        $this->shelfX += $w + 1;
        $this->shelfH = max($this->shelfH, $h + 1);
        $this->dirty = true;
        return $this->slots[$key] = ['u' => $u, 'v' => $v, 'w' => $w, 'h' => $h];
    }
}
