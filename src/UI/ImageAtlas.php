<?php
declare(strict_types=1);

namespace MyPHP\UI;

use RuntimeException;

/**
 * Images packed into pages, each an RGBA Atlas the size of the glyph
 * atlas and a slice of one texture array: an image goes on the first page
 * with room, a new page opens while there are fewer than MAX (an image up
 * to a page's size has one to itself), and past that the images drawn
 * least recently make room, page by page from the one drawn from least
 * recently (Atlas::evict, none this frame draws); only when even that
 * does not do is the page used least recently emptied — so thumbnails
 * and a large image stop evicting each other, as they did in one atlas.
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
        foreach ($this->pages as $page) { $page->frame = $this->frame; }
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

    /** Copies the bitmap at $src over the slot of $key, where a page has it (Image::update). */
    public function replace(string $key, int $src): void
    {
        foreach ($this->pages as $page) {
            if ($page->replace($key, $src)) { return; }
        }
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
        $slot = null; $i = null;
        foreach ($this->pages as $p => $page) {
            if (($slot = $page->add($key, $w, $h, $src)) !== null) { $i = $p; break; }
        }
        if ($slot === null && count($this->pages) < self::MAX) {
            $i = count($this->pages);
            $this->pages[$i] = new Atlas($this->w, $this->h, 4);
            $this->pages[$i]->frame = $this->frame;
            $slot = $this->pages[$i]->add($key, $w, $h, $src);
        }
        if ($slot === null) { // every page full: the images drawn least recently make room, on the page drawn from least recently first
            $order = array_keys($this->pages);
            usort($order, fn(int $a, int $b) => ($this->used[$a] ?? -1) <=> ($this->used[$b] ?? -1));
            foreach ($order as $p) {
                $this->pages[$p]->evict($w, $h);
                if (($slot = $this->pages[$p]->add($key, $w, $h, $src)) !== null) { $i = $p; break; }
            }
        }
        if ($slot === null) { // this frame draws from all of it: the page drawn from least recently emptied, what drew from it painted again
            $i = array_key_first($this->pages);
            foreach ($this->pages as $p => $page) {
                if (($this->used[$p] ?? -1) < ($this->used[$i] ?? -1)) {
                    $i = $p;
                }
            }
            $this->pages[$i]->reset();
            $emptied = true;
            $slot = $this->pages[$i]->add($key, $w, $h, $src) ?? throw new RuntimeException("image {$w}x{$h} does not fit an empty page");
        }
        $this->used[$i] = $this->frame;
        $slot['page'] = $i;
        return [$slot, $emptied];
    }
}
