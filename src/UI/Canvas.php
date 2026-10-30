<?php
declare(strict_types=1);

namespace MyPHP\UI;

use Closure;
use InvalidArgumentException;

/**
 * A bitmap that a painter draws, on the GPU (mygo's Canvas): render to
 * texture, as SkSurface, LÖVE's Canvas and raylib's RenderTexture2D. A
 * view draws what seldom changes into one once, as a chart's grid or a
 * map's ground, and shows it every frame for the price of an image; fades
 * or tints a whole layer at once; or draws at a resolution of its own and
 * shows it scaled, as pixel art scales up crisp (Game::integerFit).
 *
 * It keeps what was last drawn into it until draw() draws it anew, and
 * shows anywhere a bitmap does, through bitmap().
 */
final class Canvas
{
    /** The largest side of a canvas, in pixels: what every GPU's textures allow (mygo's maxEffectLayer); one larger than a page of the canvases' atlas has a texture of its own (loose()). */
    public const MAX = 8192;
    /** How many quads one drawing of a canvas holds. */
    public const CAPACITY = 16384;
    public readonly int $w, $h;
    private readonly Image $bitmap;
    /** @internal what it was last drawn with, which renders it again when its place in the atlas moves; null before its first draw */
    public ?DisplayList $list = null;
    /** @internal where it is in the canvases' atlas: [u, v, page], null when it has no place */
    public ?array $slot = null;
    /** @internal the number of its last draw, among all canvases' (0: never drawn), which renders canvases drawn inside others first */
    public int $drawn = 0;
    /** @internal the standard deviation, in its pixels, of the Gaussian blur it renders through, transparent beyond its pixels (Node::blur); 0 for none */
    public float $blur = 0.0;
    private static int $draws = 0;

    /** A canvas of $w × $h pixels, transparent until drawn (mygo's NewCanvas). */
    public function __construct(int $w, int $h)
    {
        $this->w = max($w, 1);
        $this->h = max($h, 1);
        if ($this->w > self::MAX || $this->h > self::MAX) {
            throw new InvalidArgumentException("myphp: a canvas of {$this->w}x{$this->h} is larger than " . self::MAX . ' pixels on a side');
        }
        $this->bitmap = Image::ofCanvas($this);
    }

    /** The bitmap the canvas shows as, for Context::image, Painter::image and imageRegion; make it pixelated() to scale pixel art up crisp. */
    public function bitmap(): Image
    {
        return $this->bitmap;
    }

    /** The canvas's size in pixels, the units it is drawn in. @return array{0: int, 1: int} */
    public function size(): array
    {
        return [$this->w, $this->h];
    }

    /**
     * Draws the canvas anew, cleared to transparent, with what $fn paints
     * with the painter it gets, in the canvas's pixels, during the frame
     * $p paints (a draw callback's painter). What $fn paints is what any
     * painter paints: shapes, paths, text, images, other canvases, under
     * transforms and clips. Drawn, the canvas shows it from this frame on.
     *
     * @param Closure(Painter): void $fn
     */
    public function draw(Painter $p, Closure $fn): void
    {
        if ($this->list === null) {
            $ffi = \MyPHP\Native\ObjC::$c ?? \FFI::cdef(); // the bridge's, whose pointers the renderer copies from; a test's without it
            $this->list = new DisplayList($ffi->new('float[' . (self::CAPACITY * DisplayList::FLOATS_PER_RECT) . ']'), self::CAPACITY,
                $ffi->new('float[' . (1024 * DisplayList::FLOATS_PER_TRANSFORM) . ']'), 1024);
        }
        $this->list->reset();
        $this->list->pushClip(0, 0, $this->w, $this->h);
        $fn($p->forCanvas($this->list));
        $this->drawn = ++self::$draws;
        CanvasAtlas::drawn($this);
    }

    /**
     * @internal whether it is larger than a page of the canvases' atlas:
     * it renders into a texture of its own, which shaders read, and is
     * drawn as an image by a shader of the painter's (Painter::looseImage)
     */
    public function loose(): bool
    {
        return $this->w > CanvasAtlas::$w || $this->h > CanvasAtlas::$h;
    }

    /** @internal its place in the canvases' atlas, made on first use; a canvas placed again is rendered again @return array{u: int, v: int, w: int, h: int, page: int} */
    public function slot(): array
    {
        if ($this->loose()) {
            throw new \LogicException('myphp: a canvas larger than a page of their atlas has no place in it');
        }
        if ($this->slot === null) {
            CanvasAtlas::place($this);
        }
        [$u, $v, $page] = $this->slot;
        return ['u' => $u, 'v' => $v, 'w' => $this->w, 'h' => $this->h, 'page' => $page];
    }
}
