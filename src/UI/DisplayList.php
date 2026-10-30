<?php
declare(strict_types=1);

namespace MyPHP\UI;

use FFI\CData;
use RuntimeException;

/**
 * A frame's drawing commands, written straight into a GPU buffer's memory.
 * One quad is 16 floats: x y w h, r g b a, radius kind u v, clip x y w h.
 * kind 0 is a filled (rounded) rect; kind 1 samples the glyph atlas at u, v;
 * kind 2 samples the image atlas, with the slot's pixel size in r, g;
 * kind 3 is a soft shadow (u = blur), kind 4 a ring (u = thickness), kind 5
 * Liquid Glass (u = style, color = tint), kind 6 a vertical alpha gradient
 * (color.a at the top, u at the bottom), kind 7 a level of a backdrop
 * blur (shown by clamp(u + v t), t = clamp(b + r x + g y) of the quad's
 * own points, a its opacity) and 8 the hard scroll edge's (mixed with the
 * color by its alpha), kind 9 a line, kinds 10 and 11 a gradient between two
 * colors across (10) or down (11) the quad: the color, and u the other's
 * rgb packed as a float, v its alpha. Kind 14 is a box (box(): its paint,
 * corners, borders; or, flagged MASK, a path's mask from the glyph atlas,
 * painted: pathGradient()) and kind 15 a box shadow (boxShadow()), each
 * taking the slot after it too for what does not fit in its own, that
 * slot's kind -1; a box painted with a gradient of stops takes a slot
 * more for each stop, of kind -1 too: its color (straight sRGB), its
 * place, and its color in Oklab.
 * Kinds 5, 7 and 8 read what was drawn before
 * them, blurred: the renderer splits the frame's draw at each of them.
 * The clip rect is the innermost pushClip() in effect; fragments outside
 * it are discarded.
 *
 * What a quad is drawn through, the map from its coordinates to the
 * window (setWorld, mygo's Painter transform), is an entry of a second
 * buffer, $xforms, of FLOATS_PER_TRANSFORM floats each: a b c d, x y g h
 * of an Affine, entry 0 the identity; then the mask what is drawn is cut
 * by (setMask), x y w h of where it lies in the window (w below 0 for
 * one inverted, what it covers cut away) and u v w h of its image's slot
 * in pixels, u plus 4096 times the slot's page (a slot w of 0: the rect
 * itself is the mask); w 0 for none. The quad's
 * kind carries the entry,
 * as kind + KIND_STRIDE * entry, so that a quad drawn as it is laid out
 * costs no more floats. The opacity of what is drawn (setOpacity, mygo's
 * Painter opacity) multiplies each quad's alpha.
 */
final class DisplayList
{
    public const FLOATS_PER_RECT = 16, FLOATS_PER_TRANSFORM = 16;
    public const KIND_STRIDE = 32.0;
    /** The kinds of what a shader draws (Shader\Shader): its rectangle (u: its draw among $shaders), and a glyph's mask it fills (color: its draw, and the corner of the box it fills, x y), which Metal draws with the shader's pipeline. */
    public const KIND_SHADER = 16, KIND_SHADER_GLYPH = 17;
    /**
     * What box() fills with (mygo's scene.Paint): a color, a linear
     * gradient of two colors mixed in sRGB or in Oklab, stripes, or a
     * gradient of stops, linear, conic or radial, each mixed in sRGB or in
     * Oklab.
     */
    public const PAINT_COLOR = 0, PAINT_LINEAR = 1, PAINT_OKLAB = 2, PAINT_STRIPES = 3, PAINT_LINEAR_STOPS = 4, PAINT_LINEAR_STOPS_OKLAB = 5,
        PAINT_CONIC = 6, PAINT_CONIC_OKLAB = 7, PAINT_RADIAL = 8, PAINT_RADIAL_OKLAB = 9;
    /** What a box's slot flags with its paint: its border dashed, a path's mask, its border painted with the gradient (the inside with plain color). */
    public const DASHED = 16, MASK = 32, BORDER_PAINT = 64;
    /** How many stops a gradient takes at most (mygo's scene.MaxStops). */
    public const MAX_STOPS = 32;
    private const NO_CLIP = [-1e6, -1e6, 2e6, 2e6];
    public int $n = 0;
    /** The zoom of the content it draws (Context::setZoom, mygo's scene Zoom): its points are this many of the view's, drawn at its scale times it. Set by the window. */
    public float $zoom = 1.0;
    /** @internal painting skips the nodes whose ink (Node::$ink) is clipped out: set by the window around its frame's painting, where the nodes are where Runtime::record found them, in the window's points */
    public bool $cull = false;
    /** @var list<array{0: int, 1: float}> the glass quads: index, blur sigma in pixels */
    public array $glass = [];
    /** @var list<array{0: int, 1: int}> the holes web views show through: the first slot of each, and how many it takes */
    public array $holes = [];
    /** @var list<array{0: \MyPHP\UI\Shader\Code, 1: string, 2: ?string, 3: list<?Image>}> the shaders drawn this frame: the code, its uniforms' bytes, those a wide gamut reads in their place (null for the same), the images it reads */
    public array $shaders = [];
    /** @var list<array{0: int, 1: int, 2: int}> the runs of quads a shader draws, in order: the first, how many, and the draw among $shaders */
    public array $shaderRuns = [];
    /** @var list<array{0: int, 1: float, 2: float, 3: float, 4: float}> the opaque fills large enough to hide what is under them (cull()): their quad and the part of the window they certainly cover whole, x0 y0 x1 y1, within their clip */
    private array $occluders = [];
    /** How many quads the last cull() left out (mygo's Builder.Culled). */
    public int $culled = 0;
    /** The least area of an opaque fill that cull() looks under, in points, a panel's, a sheet's or a popover's: what a smaller one hides saves the GPU less than the look costs PHP (measured on the gallery, 0.3 ms a frame for 14 quads under its cards). */
    private const OCCLUDER_AREA = 40000.0;
    /** How many occluders cull() keeps as it goes back, the largest (mygo's cullOccluders). */
    private const OCCLUDERS = 8;
    /** @var list<array{0: int, 1: float, 2: float, 3: float, 4: float, 5: float, 6: float}> the quads drawn in a wide color (WideColor): their first float, their sRGB components and their wide ones (mygo's scene Wide) */
    public array $wide = [];
    private array $clip = self::NO_CLIP;
    private array $clips = [];

    /** Text::pathQuad, for path(): the glyph quads of a path from its mask (or its tiles) in the atlas, given which show. Set by the window; null leaves paths undrawn (a headless list). */
    public ?\Closure $pathQuad = null;

    /** The entries of $xforms written this frame, the identity's included. */
    public int $transforms = 1;
    /** What is drawn now is drawn through: the map, its entry as kind + KIND_STRIDE * entry, and the entries of the maps written this frame by spl_object_id, with the maps: held until reset, as an id is reused by a new object once its own is freed, and a map drawn through and gone would give its entry to another. */
    private Affine $world;
    /** The map setWorld was given, which world() returns: $world is it after the base (setBase). */
    private Affine $given;
    /** @internal Painter::paintBlurred: the map from the window to what is drawn into this list, after every map setWorld is given; null for none. */
    private ?Affine $base = null;
    /** @var array<int, array{0: Affine, 1: Affine}> the maps setWorld was given, by spl_object_id, with the base after them, held until reset */
    private array $based = [];
    private float $xk = 0.0;
    /** @var array<string, array{0: int, 1: Affine}> by the map's spl_object_id and the mask's key */
    private array $entries = [];
    /** The mask what is drawn now is cut by (setMask), and its key among the entries; null and '' for none. @var ?array{0: float, 1: float, 2: float, 3: float, 4: int, 5: int, 6: int, 7: int, 8: int, 9: bool} */
    private ?array $mask = null;
    private string $maskKey = '';
    /** The opacity what is drawn now is drawn at. */
    private float $opacity = 1.0;
    /** @internal Context::memo: how many times what is drawn went through a map or a mask, which a quad then names the entry of. */
    public int $throughs = 0;

    public function __construct(public CData $buf, public int $capacity, public CData $xforms, public int $xformCapacity)
    {
        $this->world = $this->given = Affine::identity();
        $this->writeEntry(0, $this->world);
    }

    /** Draws what follows through $m, the map from its coordinates to the window (mygo's Painter transform); clips pushed after it are its bounds. */
    public function setWorld(Affine $m): void
    {
        if ($m === $this->given) {
            return;
        }
        $this->given = $m;
        $this->world = $this->base === null ? $m : ($this->based[spl_object_id($m)] ??= [$m, $this->base->mul($m)])[1];
        $this->through();
    }

    /** @internal Painter::paintBlurred: draws what follows, and all drawn through setWorld after, through $base after its map: a subtree laid out in the window drawn into a canvas's pixels, from $world on (the map now by default). */
    public function setBase(Affine $base, ?Affine $world = null): void
    {
        $this->base = $base;
        $this->based = [];
        $m = $world ?? $this->given;
        $this->given = Affine::singular(); // set again, through the base
        $this->setWorld($m);
    }

    /**
     * @internal Painter (Node::clipMask): cuts what follows by $mask, the
     * coverage of an image's slot lying at x y w h of the window
     * (points), or of that rect itself for a slot of no width: [x, y, w,
     * h, u, v, sw, sh, page, inverted]; inverted, what
     * it covers is cut away; null for none. What is drawn through a
     * transform is cut where it shows.
     * @param ?array{0: float, 1: float, 2: float, 3: float, 4: int, 5: int, 6: int, 7: int, 8: int, 9: bool} $mask
     */
    public function setMask(?array $mask): void
    {
        $this->mask = $mask;
        $this->maskKey = $mask === null ? '' : implode(',', array_map('strval', $mask));
        $this->through();
    }

    /** @internal Painter: the mask what is drawn now is cut by. @return ?array{0: float, 1: float, 2: float, 3: float, 4: int, 5: int, 6: int, 7: int, 8: int, 9: bool} */
    public function mask(): ?array
    {
        return $this->mask;
    }

    /** The entry of the map and the mask drawn through now, written the first time this frame. */
    private function through(): void
    {
        $m = $this->world;
        if (!$m->set && $this->mask === null) {
            $this->xk = 0.0;
            return;
        }
        $this->throughs++;
        $key = spl_object_id($m) . ':' . $this->maskKey;
        $entry = $this->entries[$key][0] ?? null;
        if ($entry === null) {
            if ($this->transforms === $this->xformCapacity) {
                throw new RuntimeException("display list full ({$this->xformCapacity} transforms)");
            }
            $entry = $this->transforms++;
            $this->entries[$key] = [$entry, $m];
            $this->writeEntry($entry, $m, $this->mask);
        }
        $this->xk = self::KIND_STRIDE * $entry;
    }

    /** The map what is drawn now is drawn through. */
    public function world(): Affine
    {
        return $this->given;
    }

    /** Draws what follows at $opacity, from 0 to 1, as its alpha times it. */
    public function setOpacity(float $opacity): void
    {
        $this->opacity = $opacity;
    }

    public function opacity(): float
    {
        return $this->opacity;
    }

    /**
     * Draws what $fn draws through $t, around ($ox, $oy), after what it is
     * drawn through, as mygo's Painter.Transform.
     */
    public function transform(Transform $t, float $ox, float $oy, \Closure $fn): void
    {
        $saved = $this->given;
        $m = $t->flat();
        if ($m->set) {
            $this->setWorld($saved->mul(Affine::translation($ox, $oy))->mul($m)->mul(Affine::translation(-$ox, -$oy)));
        }
        try {
            $fn();
        } finally {
            $this->setWorld($saved);
        }
    }

    /** @param ?array{0: float, 1: float, 2: float, 3: float, 4: int, 5: int, 6: int, 7: int, 8: int, 9: bool} $mask */
    private function writeEntry(int $entry, Affine $m, ?array $mask = null): void
    {
        $x = $this->xforms; $k = $entry * self::FLOATS_PER_TRANSFORM;
        $x[$k] = $m->a; $x[$k + 1] = $m->b; $x[$k + 2] = $m->c; $x[$k + 3] = $m->d;
        $x[$k + 4] = $m->x; $x[$k + 5] = $m->y; $x[$k + 6] = $m->g; $x[$k + 7] = $m->h;
        [$mx, $my, $mw, $mh, $u, $v, $sw, $sh, $page, $inverted] = $mask ?? [0.0, 0.0, 0.0, 0.0, 0, 0, 0, 0, 0, false];
        $x[$k + 8] = $mx; $x[$k + 9] = $my; $x[$k + 10] = $inverted ? -$mw : $mw; $x[$k + 11] = $mh;
        $x[$k + 12] = $u + 4096 * $page; $x[$k + 13] = $v; $x[$k + 14] = $sw; $x[$k + 15] = $sh;
    }

    /** Fills $path in $rgb (non-zero winding), or with $width > 0 strokes it that wide with round joins and caps, as mygo's FillPath and StrokePath: drawn as a glyph from a cached mask. */
    public function path(Path $path, int $rgb, float $alpha = 1.0, float $width = 0.0): void
    {
        foreach ($this->pathQuad === null ? [] : ($this->pathQuad)($path, $width, fn(float $x, float $y, float $w, float $h): bool => !$this->clipped($x, $y, $w, $h)) as $q) {
            if (!$this->clipped($q[0], $q[1], $q[2], $q[3])) {
                $this->glyph($q[0], $q[1], $q[2], $q[3], $rgb, $alpha, $q[4], $q[5]);
            }
        }
    }

    /** @internal Context::memo: the bytes of quads $from to $to (not included), to be drawn again as they are (copyIn). */
    public function copyOut(int $from, int $to): string
    {
        return $to > $from ? \FFI::string(\FFI::addr($this->buf[$from * 16]), ($to - $from) * 16 * 4) : '';
    }

    /** @internal Metal: the bytes of the transforms written this frame, to compare a frame with the last. */
    public function transformBytes(): string
    {
        return \FFI::string(\FFI::addr($this->xforms[0]), $this->transforms * self::FLOATS_PER_TRANSFORM * 4);
    }

    /** @internal Context::memo: quads copyOut() gave, drawn again after those drawn now. */
    public function copyIn(string $quads): void
    {
        $n = intdiv(strlen($quads), 64);
        if ($this->n + $n > $this->capacity) {
            throw new RuntimeException("display list full ({$this->capacity} quads)");
        }
        if ($n > 0) { \FFI::memcpy(\FFI::addr($this->buf[$this->n * 16]), $quads, strlen($quads)); }
        $this->n += $n;
    }

    /**
     * @internal Painter::repaintParts: what drawing goes through now, the
     * map, the mask, the opacity and the clips, which a drawing painted
     * again in parts starts from as it did @return array<string, mixed>
     */
    public function state(): array
    {
        return ['world' => $this->world, 'given' => $this->given, 'base' => $this->base, 'based' => $this->based, 'xk' => $this->xk, 'mask' => $this->mask, 'maskKey' => $this->maskKey,
            'opacity' => $this->opacity, 'clip' => $this->clip, 'clips' => $this->clips];
    }

    /** @internal Painter::repaintParts: draws through what state() gave, the transforms written since kept @param array<string, mixed> $s */
    public function setState(array $s): void
    {
        ['world' => $this->world, 'given' => $this->given, 'base' => $this->base, 'based' => $this->based, 'xk' => $this->xk, 'mask' => $this->mask, 'maskKey' => $this->maskKey,
            'opacity' => $this->opacity, 'clip' => $this->clip, 'clips' => $this->clips] = $s;
    }

    /** @internal Painter::repaintParts: the list as painted, its quads and the transforms they go through, or null for one with what the renderer reads apart (glass, holes, shaders, wide colors), which a frame painting in parts paints whole @return ?array{0: string, 1: string, 2: int, 3: array} */
    public function snapshot(): ?array
    {
        if ($this->glass !== [] || $this->holes !== [] || $this->shaders !== [] || $this->shaderRuns !== [] || $this->wide !== []) {
            return null;
        }
        return [$this->copyOut(0, $this->n), $this->transformBytes(), $this->transforms, $this->entries];
    }

    /** @internal Painter::repaintParts: the transforms of a snapshot, its quads left out (n 0), to paint parts of it again @param array{0: string, 1: string, 2: int, 3: array} $s */
    public function loadTransforms(array $s): void
    {
        $this->reset();
        if ($s[2] > $this->xformCapacity) { throw new RuntimeException("display list full ({$this->xformCapacity} transforms)"); }
        if ($s[1] !== '') { \FFI::memcpy(\FFI::addr($this->xforms[0]), $s[1], strlen($s[1])); }
        [$this->transforms, $this->entries] = [$s[2], $s[3]];
    }

    public function reset(): void
    {
        $this->n = 0;
        $this->glass = [];
        $this->holes = [];
        $this->shaders = []; $this->shaderRuns = [];
        $this->occluders = [];
        $this->wide = [];
        $this->clip = self::NO_CLIP;
        $this->clips = [];
        $this->transforms = 1; $this->entries = [];
        $this->world = $this->given = Affine::identity(); $this->xk = 0.0; $this->mask = null; $this->maskKey = '';
        $this->base = null; $this->based = [];
        $this->opacity = 1.0;
    }

    /** Clips what follows to the intersection of the current clip and this rect. */
    public function pushClip(float $x, float $y, float $w, float $h): void
    {
        $this->clips[] = $c = $this->clip;
        if ($this->world->set) {
            [$x, $y, $w, $h] = $this->world->bounds($x, $y, $w, $h); // as mygo's, the bounds of the clip as it shows
        }
        $x0 = max($c[0], $x); $y0 = max($c[1], $y);
        $x1 = min($c[0] + $c[2], $x + $w); $y1 = min($c[1] + $c[3], $y + $h);
        $this->clip = [$x0, $y0, max(0, $x1 - $x0), max(0, $y1 - $y0)];
    }

    public function popClip(): void
    {
        $this->clip = array_pop($this->clips);
    }

    /** The current clip, in the coordinates of what is drawn now: x, y, w, h (mygo's Painter.Visible); the bounds of it through a transform, nothing through a singular one. @return array{0: float, 1: float, 2: float, 3: float} */
    public function visible(): array
    {
        if (!$this->world->set) {
            return $this->clip;
        }
        return $this->world->inverse()?->bounds(...$this->clip) ?? [0.0, 0.0, 0.0, 0.0];
    }

    /** The current clip in the window's coordinates, whatever is drawn through: x, y, w, h. @return array{0: float, 1: float, 2: float, 3: float} */
    public function clip(): array
    {
        return $this->clip;
    }

    /** Whether a rect lies entirely outside the current clip (nothing to draw). */
    /** @internal whether $r, [x0, y0, x1, y1] in the window's points, lies outside the clip, as the window's frame paints */
    public function outOfClip(array $r): bool
    {
        $c = $this->clip;
        return $r[0] >= $c[0] + $c[2] || $r[1] >= $c[1] + $c[3] || $r[2] <= $c[0] || $r[3] <= $c[1];
    }

    public function clipped(float $x, float $y, float $w, float $h): bool
    {
        if ($this->world->set) {
            [$x, $y, $w, $h] = $this->world->bounds($x, $y, $w, $h);
        }
        $c = $this->clip;
        return $x >= $c[0] + $c[2] || $y >= $c[1] + $c[3] || $x + $w <= $c[0] || $y + $h <= $c[1];
    }

    public function rect(float $x, float $y, float $w, float $h, int $rgb, float $alpha = 1.0, float $radius = 0.0): void
    {
        if ($this->n === $this->capacity) {
            throw new RuntimeException("display list full ({$this->capacity} rects)");
        }
        $b = $this->buf; $k = $this->n * 16; $c = $this->clip;
        $b[$k] = $x; $b[$k + 1] = $y; $b[$k + 2] = $w; $b[$k + 3] = $h;
        $b[$k + 4] = (($rgb >> 16) & 255) / 255; $b[$k + 5] = (($rgb >> 8) & 255) / 255; $b[$k + 6] = ($rgb & 255) / 255; $b[$k + 7] = $alpha * $this->opacity;
        if ($rgb > 0xffffff && $b[$k + 7] > 0) { $this->noteWide($k, $rgb); } // a wide color that shows
        $b[$k + 8] = $radius; $b[$k + 9] = $this->xk;
        $b[$k + 12] = $c[0]; $b[$k + 13] = $c[1]; $b[$k + 14] = $c[2]; $b[$k + 15] = $c[3];
        if ($w * $h >= self::OCCLUDER_AREA && $this->xk == 0.0 && $alpha * $this->opacity >= 1.0) { $this->occluder($this->n, $x, $y, $w, $h, $radius, 0.0); }
        $this->n++;
    }

    /**
     * Notes quad $i, an opaque fill of $x $y $w $h through
     * no map or mask, rounded by $radius at most, inside a border $border
     * wide, as an occluder: the part of it whose every pixel it covers
     * whole with its color, as the shader computes coverage (one where the
     * edge is half a point away or more), the curve of its corners and its
     * border left out, within its clip.
     */
    private function occluder(int $i, float $x, float $y, float $w, float $h, float $radius, float $border): void
    {
        $m = 0.3 * $radius + 0.5 + $border; // a rounded corner's curve stays outside the rect in by 0.29 of its radius
        $c = $this->clip;
        $x0 = max($x + $m, $c[0]); $y0 = max($y + $m, $c[1]);
        $x1 = min($x + $w - $m, $c[0] + $c[2]); $y1 = min($y + $h - $m, $c[1] + $c[3]);
        if ($x1 > $x0 && $y1 > $y0) { $this->occluders[] = [$i, $x0, $y0, $x1, $y1]; }
    }

    /**
     * Leaves out the quads that a later opaque fill covers whole (mygo's
     * occlusion culling, planCull): going back from the last fill noted
     * (occluder()), keeping the largest few seen, a quad drawn through no
     * map whose box, a point more each way for its smoothed edge, within its
     * clip, lies inside one of them is given the kind -1, which draws
     * nothing, as its slots after it. What reads what was drawn before it
     * (glass, a backdrop blur, the hard scroll edge) stops it: what is drawn
     * after it cannot hide what came before. The pixels are those of
     * drawing everything. Run after the frame's damage was taken from the
     * list, before it is drawn; returns how many it left out.
     */
    public function cull(): int
    {
        $this->culled = 0;
        if ($this->occluders === []) {
            return 0;
        }
        $b = $this->buf; $occ = $this->occluders; $next = count($occ) - 1;
        $active = [];
        for ($i = $occ[$next][0]; $i >= 0; $i--) {
            $k = $i * 16;
            $kind = $b[$k + 9];
            if ($kind >= 0.0 && $kind < self::KIND_STRIDE) {
                if ($kind > 4.5 && $kind < 8.5 && $kind != 6.0 || $kind > 15.5) { // glass (5), a backdrop blur's level (7), the hard scroll edge (8), a shader's, which may read its backdrop: a barrier
                    $active = [];
                } elseif ($active !== []) {
                    $x0 = $b[$k] - 1; $y0 = $b[$k + 1] - 1; $x1 = $x0 + $b[$k + 2] + 2; $y1 = $y0 + $b[$k + 3] + 2;
                    $cx = $b[$k + 12]; $cy = $b[$k + 13];
                    if ($x0 < $cx) { $x0 = $cx; }
                    if ($y0 < $cy) { $y0 = $cy; }
                    if ($x1 > ($cx += $b[$k + 14])) { $x1 = $cx; }
                    if ($y1 > ($cy += $b[$k + 15])) { $y1 = $cy; }
                    foreach ($active as $o) {
                        if ($x0 >= $o[1] && $y0 >= $o[2] && $x1 <= $o[3] && $y1 <= $o[4]) {
                            $b[$k + 9] = -1.0;
                            $this->culled++;
                            break;
                        }
                    }
                }
            }
            while ($next >= 0 && $occ[$next][0] === $i) { // it hides what came before it from here on
                $o = $occ[$next--];
                $o[0] = ($o[3] - $o[1]) * ($o[4] - $o[2]);
                if (count($active) < self::OCCLUDERS) {
                    $active[] = $o;
                } else {
                    $small = 0;
                    foreach ($active as $j => $a) { if ($a[0] < $active[$small][0]) { $small = $j; } }
                    if ($o[0] > $active[$small][0]) { $active[$small] = $o; }
                }
            }
            if ($active === []) {
                if ($next < 0) { break; }
                $i = min($i, $occ[$next][0] + 1); // nothing to hide under until the next one
            }
        }
        return $this->culled;
    }

    /**
     * A hole through what was drawn before it, rounded by $radii, where a
     * web view under the content shows (mygo's web view hole): what is
     * drawn after it shows over the page. Metal draws it clearing what is
     * there, by its coverage, so that the opacity fades the page in.
     * @param array{0: float, 1: float, 2: float, 3: float} $radii
     */
    public function hole(float $x, float $y, float $w, float $h, array $radii): void
    {
        $at = $this->n;
        $this->box($x, $y, $w, $h, $radii, 0, 1.0);
        $this->holes[] = [$at, $this->n - $at];
    }

    /** The glass's material as macOS measures it: the bezel's width in points, the rim's light, what black maps to in light mode, black and white in dark mode (Plugins\Glass\Glass::paneMaterial at clarity 0). */
    public const GLASS_TONE = [36.0, 0.35, 0.541, 0.15, 0.51];

    /**
     * A pane of Liquid Glass over what was drawn before it: $style 0
     * regular, 1 clear; tinted by $tint at $tintAlpha; blurred by $sigma
     * pixels; its material $tone (GLASS_TONE's), in the slot after it,
     * which the shader reads: clear glass takes its bezel and rim alone.
     * @param array{0: float, 1: float, 2: float, 3: float, 4: float} $tone
     */
    public function glass(float $x, float $y, float $w, float $h, float $radius, int $style, int $tint, float $tintAlpha, float $sigma, array $tone = self::GLASS_TONE): void
    {
        $this->glass[] = [$this->n, $sigma];
        $this->quad($x, $y, $w, $h, $tint, $tintAlpha, $radius, 5.0, (float)$style, 0.0, 2);
        $this->slot([$tone[0], $tone[1], $tone[2], 0.0], [$tone[3], $tone[4], 0.0, 0.0], 0.0, 0.0, 0.0, [0.0, 0.0, 0.0, 0.0]);
    }

    /** A vertical gradient of $rgb from $alphaTop to $alphaBottom. */
    public function gradient(float $x, float $y, float $w, float $h, int $rgb, float $alphaTop, float $alphaBottom, float $radius = 0.0): void
    {
        $this->quad($x, $y, $w, $h, $rgb, $alphaTop, $radius, 6.0, $alphaBottom);
    }

    /** A gradient from $from at $fromAlpha to $to at $toAlpha, across the quad, or down it when $vertical. */
    public function gradient2(float $x, float $y, float $w, float $h, int $from, float $fromAlpha, int $to, float $toAlpha, bool $vertical = false, float $radius = 0.0): void
    {
        $this->quad($x, $y, $w, $h, $from, $fromAlpha, $radius, $vertical ? 11.0 : 10.0, (float)($to & 0xffffff), $toAlpha);
    }

    /**
     * A level of a backdrop blur (mygo's glass blurLevel): what was drawn
     * before, blurred by $sigma pixels, shown by w = clamp($a + $b t) where
     * t = clamp($t0 + $tx x + $ty y) of the quad's points (x, y) from its
     * top left: how far along the blur's mask, and how far that is into
     * the level's band.
     */
    public function blurLevel(float $x, float $y, float $w, float $h, float $radius, float $sigma, float $a, float $b, float $t0, float $tx, float $ty): void
    {
        if ($this->n === $this->capacity) {
            throw new RuntimeException("display list full ({$this->capacity} quads)");
        }
        $this->glass[] = [$this->n, $sigma];
        $buf = $this->buf; $k = $this->n * 16; $c = $this->clip;
        $buf[$k] = $x; $buf[$k + 1] = $y; $buf[$k + 2] = $w; $buf[$k + 3] = $h;
        $buf[$k + 4] = $tx; $buf[$k + 5] = $ty; $buf[$k + 6] = $t0; $buf[$k + 7] = $this->opacity;
        $buf[$k + 8] = $radius; $buf[$k + 9] = 7.0 + $this->xk; $buf[$k + 10] = $a; $buf[$k + 11] = $b;
        $buf[$k + 12] = $c[0]; $buf[$k + 13] = $c[1]; $buf[$k + 14] = $c[2]; $buf[$k + 15] = $c[3];
        $this->n++;
    }

    /** What was drawn before, blurred by $sigma pixels, toned as macOS's hard scroll edge (saturated by 1.25, lightened by 0.03), under $rgb by $mix. */
    public function hardBlur(float $x, float $y, float $w, float $h, float $radius, float $sigma, int $rgb, float $mix): void
    {
        $this->glass[] = [$this->n, $sigma];
        $this->quad($x, $y, $w, $h, $rgb, $mix, $radius, 8.0, 0.0);
    }

    /** A soft shadow of a rounded rect, offset by (dx, dy), its edge feathered over $blur points each way. */
    public function shadow(float $x, float $y, float $w, float $h, int $rgb, float $alpha, float $radius, float $blur, float $dx = 0, float $dy = 0): void
    {
        $this->quad($x + $dx - $blur, $y + $dy - $blur, $w + 2 * $blur, $h + 2 * $blur, $rgb, $alpha, $radius, 3.0, $blur);
    }

    /** A ring: the rounded rect's outline, $thickness points wide inside its edge. */
    public function ring(float $x, float $y, float $w, float $h, int $rgb, float $alpha, float $radius, float $thickness): void
    {
        $this->quad($x, $y, $w, $h, $rgb, $alpha, $radius, 4.0, $thickness);
    }

    /** A line from (x0, y0) to (x1, y1), $thickness points wide with round ends: a capsule across the corners of its bounding rect. */
    public function line(float $x0, float $y0, float $x1, float $y1, int $rgb, float $alpha, float $thickness): void
    {
        $r = $thickness / 2;
        $this->quad(min($x0, $x1) - $r, min($y0, $y1) - $r, abs($x1 - $x0) + $thickness, abs($y1 - $y0) + $thickness, $rgb, $alpha, $r, 9.0, $x1 >= $x0 ? 1.0 : -1.0, $y1 >= $y0 ? 1.0 : -1.0);
    }

    private function quad(float $x, float $y, float $w, float $h, int $rgb, float $alpha, float $radius, float $kind, float $u, float $v = 0.0, int $slots = 1): void
    {
        if ($this->n + $slots > $this->capacity) {
            throw new RuntimeException("display list full ({$this->capacity} quads)");
        }
        $b = $this->buf; $k = $this->n * 16; $c = $this->clip;
        $b[$k] = $x; $b[$k + 1] = $y; $b[$k + 2] = $w; $b[$k + 3] = $h;
        $b[$k + 4] = (($rgb >> 16) & 255) / 255; $b[$k + 5] = (($rgb >> 8) & 255) / 255; $b[$k + 6] = ($rgb & 255) / 255; $b[$k + 7] = $alpha * $this->opacity;
        if ($rgb > 0xffffff && $b[$k + 7] > 0) { $this->noteWide($k, $rgb); } // a wide color that shows
        $b[$k + 8] = $radius; $b[$k + 9] = $kind + $this->xk; $b[$k + 10] = $u; $b[$k + 11] = $v;
        $b[$k + 12] = $c[0]; $b[$k + 13] = $c[1]; $b[$k + 14] = $c[2]; $b[$k + 15] = $c[3];
        $this->n++;
    }

    /**
     * A box (mygo's fill op): $radii its corners' (top left, top right,
     * bottom right, bottom left), fitted to it as CSS does; filled with
     * $rgb at $alpha, or, by $paint, a gradient from $rgb to $rgb2 at
     * $alpha2 from ($g[0], $g[1]) to ($g[2], $g[3]), in points from its top
     * left, or stripes of $rgb over $rgb2, ($g[0], $g[1]) the unit vector
     * across them, $g[2] wide every $g[3] points, or a gradient of $stops
     * ([rgb, alpha, place] each, in order): linear from ($g[0], $g[1]) to
     * ($g[2], $g[3]), conic around ($g[0], $g[1]) from $g[2] radians
     * clockwise from upwards, or radial out from ($g[0], $g[1]) to a circle
     * $g[2] points around it; inside its edges a border of $borders (top,
     * right, bottom, left) in $borderRgb at $borderAlpha, $dashed or solid,
     * its inner corners each the outer less the wider of its sides. With
     * $borderPaint the gradient paints the border, times its color, and
     * $rgb at $alpha the inside.
     * @param array{0: float, 1: float, 2: float, 3: float} $radii
     * @param array{0: float, 1: float, 2: float, 3: float} $borders
     * @param array{0: float, 1: float, 2: float, 3: float} $g
     * @param list<array{0: int, 1: float, 2: float}> $stops
     */
    public function box(float $x, float $y, float $w, float $h, array $radii, int $rgb, float $alpha, array $borders = [0.0, 0.0, 0.0, 0.0], int $borderRgb = 0, float $borderAlpha = 0.0, bool $dashed = false,
                        int $paint = self::PAINT_COLOR, int $rgb2 = 0, float $alpha2 = 0.0, array $g = [0.0, 0.0, 0.0, 0.0], array $stops = [], bool $borderPaint = false): void
    {
        $n = $paint >= self::PAINT_LINEAR_STOPS ? min(count($stops), self::MAX_STOPS) : 0;
        if ($n > 0) { // u: how many stops follow the slot
            $this->quad($x, $y, $w, $h, $rgb, $alpha, 0.0, 14.0, (float)$n, 0.0, 2 + $n);
        } else {
            $this->quad($x, $y, $w, $h, $rgb, $alpha, 0.0, 14.0, (float)($rgb2 & 0xffffff), $alpha2 * $this->opacity, 2); // a gradient's or stripes' second color and the border's are packed: they draw their sRGB twins
        }
        $r = self::fitRadii($w, $h, $radii);
        if ($paint === self::PAINT_COLOR && $w * $h >= self::OCCLUDER_AREA && $this->xk == 0.0 && $alpha * $this->opacity >= 1.0) {
            $this->occluder($this->n - 1, $x, $y, $w, $h, max($r), $borderAlpha > 0 ? max($borders) : 0.0);
        }
        $this->slot($r, $borders, (float)($borderRgb & 0xffffff), $borderAlpha * $this->opacity, $paint + ($dashed ? self::DASHED : 0) + ($borderPaint ? self::BORDER_PAINT : 0), $g);
        $this->stops($stops, $n, $borderPaint ? 1.0 : $this->opacity); // a border's color, at the opacity, times the gradient
    }

    /** The first $n of $stops, each a slot of kind -1 after a box's: its color, straight, at $opacity, its place, at the one before's or after, and its color in Oklab. @param list<array{0: int, 1: float, 2: float}> $stops */
    private function stops(array $stops, int $n, float $opacity): void
    {
        $last = -INF;
        for ($i = 0; $i < $n; $i++) {
            [$rgb, $alpha, $at] = $stops[$i];
            $last = $at = max($at, $last); // in order, as CSS puts them
            [$l, $a, $b] = self::oklab($rgb);
            $c = $rgb & 0xffffff;
            $this->slot([(($c >> 16) & 255) / 255, (($c >> 8) & 255) / 255, ($c & 255) / 255, $alpha * $opacity], [$at, $l, $a, $b], 0.0, 0.0, 0.0, [0.0, 0.0, 0.0, 0.0]);
        }
    }

    /** sRGB $rgb in the Oklab color space (mygo's scene.Oklab), as the shader mixes gradients in it. @return array{0: float, 1: float, 2: float} */
    public static function oklab(int $rgb): array
    {
        $lin = static function (int $c): float {
            $c /= 255;
            return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };
        [$r, $g, $b] = [$lin(($rgb >> 16) & 255), $lin(($rgb >> 8) & 255), $lin($rgb & 255)];
        $l = (0.4122214708 * $r + 0.5363325363 * $g + 0.0514459929 * $b) ** (1 / 3);
        $m = (0.2119034982 * $r + 0.6806995451 * $g + 0.1073969566 * $b) ** (1 / 3);
        $s = (0.0883024619 * $r + 0.2817188376 * $g + 0.6299787005 * $b) ** (1 / 3);
        return [0.2104542553 * $l + 0.7936177850 * $m - 0.0040720468 * $s, 1.9779984951 * $l - 2.4285922050 * $m + 0.4505937099 * $s,
            0.0259040371 * $l + 0.7827717662 * $m - 0.8086757660 * $s];
    }

    /**
     * The box shadow of a rounded box (mygo's shadow op), in $rgb at
     * $alpha, blurred by a Gaussian of $sigma points: outside, the box
     * ($x, $y, $w, $h) with $radii, shown only outside $cut (and its
     * radii), the box casting it; $inset, inside the box, what the hole
     * $cut, blurred, leaves of it. A $cut of no area cuts nothing out, or
     * leaves no hole.
     * @param array{0: float, 1: float, 2: float, 3: float} $radii
     * @param array{0: float, 1: float, 2: float, 3: float} $cut
     * @param array{0: float, 1: float, 2: float, 3: float} $cutRadii
     */
    public function boxShadow(float $x, float $y, float $w, float $h, array $radii, int $rgb, float $alpha, float $sigma, bool $inset, array $cut, array $cutRadii): void
    {
        $e = $inset ? 0.0 : 3 * $sigma; // the quad takes in the blur
        $this->quad($x - $e, $y - $e, $w + 2 * $e, $h + 2 * $e, $rgb, $alpha, 0.0, 15.0, $sigma, $inset ? 1.0 : 0.0, 2);
        $c = $cut[2] > 0 && $cut[3] > 0 ? self::fitRadii($cut[2], $cut[3], $cutRadii) : [0.0, 0.0, 0.0, 0.0];
        $this->slot(self::fitRadii($w, $h, $radii), $cut, $c[0], $c[1], $c[2], [$c[3], 0.0, 0.0, 0.0]);
    }

    /**
     * Fills $path, or with $width > 0 strokes it that wide, with a gradient
     * as box() paints one by $paint: from $rgb at $alpha to $rgb2 at
     * $alpha2, or of $stops, at $g(w, h), in points from the top left of
     * the path's bounds (mygo's FillPathGradient, StrokePathGradient,
     * FillPathConicGradient): its mask from the atlas, as path() draws it,
     * painted as a box (flag MASK).
     * @param \Closure(float, float): array{0: float, 1: float, 2: float, 3: float} $line
     * @param list<array{0: int, 1: float, 2: float}> $stops
     */
    public function pathGradient(Path $path, float $width, int $paint, int $rgb, float $alpha, int $rgb2, float $alpha2, \Closure $line, array $stops = []): void
    {
        $quads = $this->pathQuad === null ? [] : ($this->pathQuad)($path, $width, fn(float $x, float $y, float $w, float $h): bool => !$this->clipped($x, $y, $w, $h));
        if ($quads === []) {
            return;
        }
        // The gradient spans the path's bounds, which its tiles cut: its line from the first's corner, moved to each tile's.
        [$bx, $by] = [min(array_column($quads, 0)), min(array_column($quads, 1))];
        $bounds = $this->pathQuad !== null && count($quads) > 1 ? $path->bounds() : null;
        $l = $bounds !== null ? $line($bounds[2] - $bounds[0] + $width, $bounds[3] - $bounds[1] + $width) : $line($quads[0][2], $quads[0][3]);
        $ox = $bounds !== null ? $bounds[0] - $width / 2 : $bx; $oy = $bounds !== null ? $bounds[1] - $width / 2 : $by;
        $n = $paint >= self::PAINT_LINEAR_STOPS ? min(count($stops), self::MAX_STOPS) : 0;
        $around = $paint >= self::PAINT_CONIC; // conic and radial: a center, which alone moves
        foreach ($quads as $q) {
            if ($this->clipped($q[0], $q[1], $q[2], $q[3])) { continue; }
            if ($n > 0) {
                $this->quad($q[0], $q[1], $q[2], $q[3], $rgb, $alpha, 0.0, 14.0, (float)$n, 0.0, 2 + $n);
            } else {
                $this->quad($q[0], $q[1], $q[2], $q[3], $rgb, $alpha, 0.0, 14.0, (float)($rgb2 & 0xffffff), $alpha2 * $this->opacity, 2);
            }
            [$dx, $dy] = [$ox - $q[0], $oy - $q[1]];
            $this->slot([(float)$q[4], (float)$q[5], 0.0, 0.0], [0.0, 0.0, 0.0, 0.0], 0.0, 0.0, $paint + self::MASK, [$l[0] + $dx, $l[1] + $dy, $around ? $l[2] : $l[2] + $dx, $around ? $l[3] : $l[3] + $dy]);
            $this->stops($stops, $n, $this->opacity);
        }
    }

    /** The radii of a box's corners, scaled down together where two of a side's would overlap, as CSS's. @param array{0: float, 1: float, 2: float, 3: float} $r @return array{0: float, 1: float, 2: float, 3: float} */
    public static function fitRadii(float $w, float $h, array $r): array
    {
        $f = 1.0;
        foreach ([[$r[0] + $r[1], $w], [$r[3] + $r[2], $w], [$r[0] + $r[3], $h], [$r[1] + $r[2], $h]] as [$sum, $side]) {
            if ($sum > $side && $sum > 0) { $f = min($f, $side / $sum); }
        }
        return [max($r[0] * $f, 0.0), max($r[1] * $f, 0.0), max($r[2] * $f, 0.0), max($r[3] * $f, 0.0)];
    }

    /** The slot after a box or a box shadow: 16 floats, the 10th the kind -1 that has it draw nothing. @param array{0: float, 1: float, 2: float, 3: float} $a @param array{0: float, 1: float, 2: float, 3: float} $b @param array{0: float, 1: float, 2: float, 3: float} $d */
    private function slot(array $a, array $b, float $c0, float $c2, float $c3, array $d): void
    {
        $buf = $this->buf; $k = $this->n * 16;
        $buf[$k] = $a[0]; $buf[$k + 1] = $a[1]; $buf[$k + 2] = $a[2]; $buf[$k + 3] = $a[3];
        $buf[$k + 4] = $b[0]; $buf[$k + 5] = $b[1]; $buf[$k + 6] = $b[2]; $buf[$k + 7] = $b[3];
        $buf[$k + 8] = $c0; $buf[$k + 9] = -1.0; $buf[$k + 10] = $c2; $buf[$k + 11] = $c3;
        $buf[$k + 12] = $d[0]; $buf[$k + 13] = $d[1]; $buf[$k + 14] = $d[2]; $buf[$k + 15] = $d[3];
        $this->n++;
    }

    /** The part of an image atlas slot at (u, v), sw × sh pixels on $page (negative to mirror it), drawn into the rect, rounded by $radius at $alpha, or tinted: its colors multiplied by $tint (0xRRGGBB, not rounded); $gray in shades of gray (kind 12 instead of 2), else $pixelated sampled at the nearest pixel (kind 13). */
    public function image(float $x, float $y, float $w, float $h, int $u, int $v, int $sw, int $sh, int $page, float $alpha = 1.0, float $radius = 0.0, bool $gray = false, bool $pixelated = false, ?int $tint = null): void
    {
        if ($tint !== null) {
            $radius = -1.0 - ($tint & 0xffffff); // a tinted image is not rounded: its radius carries the tint, as -1 - rgb, exact in a float32
        }
        if ($this->n === $this->capacity) {
            throw new RuntimeException("display list full ({$this->capacity} quads)");
        }
        $b = $this->buf; $k = $this->n * 16; $c = $this->clip;
        $b[$k] = $x; $b[$k + 1] = $y; $b[$k + 2] = $w; $b[$k + 3] = $h;
        $b[$k + 4] = $sw; $b[$k + 5] = $sh; $b[$k + 6] = $page; $b[$k + 7] = $alpha * $this->opacity;
        $b[$k + 8] = $radius; $b[$k + 9] = ($gray ? 12.0 : ($pixelated ? 13.0 : 2.0)) + $this->xk; $b[$k + 10] = $u; $b[$k + 11] = $v;
        $b[$k + 12] = $c[0]; $b[$k + 13] = $c[1]; $b[$k + 14] = $c[2]; $b[$k + 15] = $c[3];
        $this->n++;
    }

    /** A glyph quad: its atlas bitmap starts at pixel ($u, $v). */
    /** Notes the quad at float $k drawn in wide color $rgb, which shows its sRGB twin until showWide(). */
    private function noteWide(int $k, int $rgb): void
    {
        $w = WideColor::wide($rgb);
        if ($w !== null) {
            $b = $this->buf;
            $this->wide[] = [$k, $b[$k + 4], $b[$k + 5], $b[$k + 6], $w[0], $w[1], $w[2]];
        }
    }

    /** Has the quads drawn in wide colors show them, in extended sRGB, for a target of a wide gamut; or their sRGB twins, for any other. */
    public function showWide(bool $on): void
    {
        $b = $this->buf;
        foreach ($this->wide as [$k, $r, $g, $bl, $wr, $wg, $wb]) {
            [$b[$k + 4], $b[$k + 5], $b[$k + 6]] = $on ? [$wr, $wg, $wb] : [$r, $g, $bl];
        }
    }

    public function glyph(float $x, float $y, float $w, float $h, int $rgb, float $alpha, int $u, int $v): void
    {
        if ($this->n === $this->capacity) {
            throw new RuntimeException("display list full ({$this->capacity} quads)");
        }
        $b = $this->buf; $k = $this->n * 16; $c = $this->clip;
        $b[$k] = $x; $b[$k + 1] = $y; $b[$k + 2] = $w; $b[$k + 3] = $h;
        $b[$k + 4] = (($rgb >> 16) & 255) / 255; $b[$k + 5] = (($rgb >> 8) & 255) / 255; $b[$k + 6] = ($rgb & 255) / 255; $b[$k + 7] = $alpha * $this->opacity;
        if ($rgb > 0xffffff && $b[$k + 7] > 0) { $this->noteWide($k, $rgb); } // a wide color that shows
        $b[$k + 8] = 0.0; $b[$k + 9] = 1.0 + $this->xk; $b[$k + 10] = $u; $b[$k + 11] = $v;
        $b[$k + 12] = $c[0]; $b[$k + 13] = $c[1]; $b[$k + 14] = $c[2]; $b[$k + 15] = $c[3];
        $this->n++;
    }

    /**
     * A rectangle a shader fills, rounded by $radius, its draw $draw (an
     * index of $shaders, which Painter::shaderEffect adds), at opacity
     * $alpha, in a run of its own.
     */
    public function shader(float $x, float $y, float $w, float $h, float $radius, int $draw, float $alpha = 1.0): void
    {
        $this->shaderRuns[] = [$this->n, 1, $draw];
        $this->quad($x, $y, $w, $h, 0xffffff, $alpha, $radius, (float)self::KIND_SHADER, (float)$draw);
    }

    /**
     * Has shader draw $draw fill the glyphs' masks among quads $from to the
     * end, in place of their colors, the box it fills from ($ox, $oy): the
     * masks (glyphs and paths, kind 1) become its, in runs of their own;
     * the rest (images, emoji, rectangles) paints as it would. Returns how
     * many it took.
     */
    public function shaderGlyphs(int $from, int $draw, float $ox, float $oy): int
    {
        $b = $this->buf; $taken = 0; $run = -1;
        for ($i = $from; $i < $this->n; $i++) {
            $k = $i * 16;
            $kind = $b[$k + 9]; $entry = floor($kind / self::KIND_STRIDE) * self::KIND_STRIDE;
            if ($kind - $entry != 1.0) {
                $run = -1;
                continue;
            }
            $b[$k + 9] = self::KIND_SHADER_GLYPH + $entry;
            $b[$k + 4] = (float)$draw; $b[$k + 5] = $ox; $b[$k + 6] = $oy; // its alpha (color.a) stays: the painter's opacity
            if ($run >= 0) {
                $this->shaderRuns[$run][1]++;
            } else {
                $run = count($this->shaderRuns);
                $this->shaderRuns[] = [$i, 1, $draw];
            }
            $taken++;
        }
        return $taken;
    }
}
