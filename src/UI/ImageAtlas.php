<?php
declare(strict_types=1);

namespace MyPHP\UI;

use RuntimeException;

/**
 * Images packed into pages, each an RGBA Atlas the size of the glyph
 * atlas and a slice of one texture array: an image goes on the first page
 * with room, a new page opens while there are fewer than MAX (an image up
 * to a page's size has one to itself), and past that the page used least
 * recently is emptied — so thumbnails and a large image stop evicting
 * each other, as they did in one atlas.
 */
final class ImageAtlas
{
    public const MAX = 4;
    /** @var list<Atlas> */
    public array $pages = [];
    /** @var array<int, int> page => the frame it was last drawn from */
    private array $used = [];
    private int $frame = 0;

    public function __construct(public int $w = 2048, public int $h = 2048) {}

    /** Call once per frame: what get() finds this frame marks its page as in use. */
    public function beginFrame(): void
    {
        $this->frame++;
    }

    /** @return ?array{u: int, v: int, w: int, h: int, page: int} */
    public function get(string $key): ?array
    {
        foreach ($this->pages as $i => $page) {
            $slot = $page->get($key);
            if ($slot !== null) {
                $this->used[$i] = $this->frame;
                $slot['page'] = $i;
                return $slot;
            }
        }
        return null;
    }

    /**
     * Adds a w*h RGBA bitmap at address $src; reports the slot and whether a
     * page was emptied for it (what was drawn from that page must be
     * painted again).
     * @return array{array{u: int, v: int, w: int, h: int, page: int}, bool}
     */
    public function add(string $key, int $w, int $h, int $src): array
    {
        if ($w > $this->w || $h > $this->h) {
            throw new RuntimeException("image {$w}x{$h} is larger than a page ({$this->w}x{$this->h})");
        }
        $emptied = false;
        $i = null;
        foreach ($this->pages as $p => $page) {
            if ($page->fits($w, $h)) {
                $i = $p;
                break;
            }
        }
        if ($i === null && count($this->pages) < self::MAX) {
            $i = count($this->pages);
            $this->pages[$i] = new Atlas($this->w, $this->h, 4);
        }
        if ($i === null) { // every page full: empty the one drawn from least recently, not one this frame draws from if it can be helped
            $i = array_key_first($this->pages);
            foreach ($this->pages as $p => $page) {
                if (($this->used[$p] ?? -1) < ($this->used[$i] ?? -1)) {
                    $i = $p;
                }
            }
            $this->pages[$i]->reset();
            $emptied = true;
        }
        $slot = $this->pages[$i]->add($key, $w, $h, $src);
        $this->used[$i] = $this->frame;
        $slot['page'] = $i;
        return [$slot, $emptied];
    }
}
