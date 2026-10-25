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
 * corners, borders; or, flagged 8, a path's mask from the glyph atlas,
 * painted: pathGradient()) and kind 15 a box shadow (boxShadow()), each
 * taking the slot after it too for what does not fit in its own, that
 * slot's kind -1.
 * Kinds 5, 7 and 8 read what was drawn before
 * them, blurred: the renderer splits the frame's draw at each of them.
 * The clip rect is the innermost pushClip() in effect; fragments outside
 * it are discarded.
 *
 * What a quad is drawn through, the map from its coordinates to the
 * window (setWorld, mygo's Painter transform), is an entry of a second
 * buffer, $xforms, of FLOATS_PER_TRANSFORM floats each: a b c d, x y g h
 * of an Affine, entry 0 the identity. The quad's kind carries the entry,
 * as kind + KIND_STRIDE * entry, so that a quad drawn as it is laid out
 * costs no more floats. The opacity of what is drawn (setOpacity, mygo's
 * Painter opacity) multiplies each quad's alpha.
 */
final class DisplayList
{
    public const FLOATS_PER_RECT = 16, FLOATS_PER_TRANSFORM = 8;
    public const KIND_STRIDE = 16.0;
    /** What box() fills with (mygo's scene.Paint): a color, a gradient mixed in sRGB or in Oklab, or stripes. */
    public const PAINT_COLOR = 0, PAINT_LINEAR = 1, PAINT_OKLAB = 2, PAINT_STRIPES = 3;
    private const NO_CLIP = [-1e6, -1e6, 2e6, 2e6];
    public int $n = 0;
    /** @var list<array{0: int, 1: float}> the glass quads: index, blur sigma in pixels */
    public array $glass = [];
    /** @var list<array{0: int, 1: int}> the holes web views show through: the first slot of each, and how many it takes */
    public array $holes = [];
    private array $clip = self::NO_CLIP;
    private array $clips = [];

    /** Text::pathQuad, for path(): the glyph quad of a path from its mask in the atlas. Set by the window; null leaves paths undrawn (a headless list). */
    public ?\Closure $pathQuad = null;

    /** The entries of $xforms written this frame, the identity's included. */
    public int $transforms = 1;
    /** What is drawn now is drawn through: the map, its entry as kind + KIND_STRIDE * entry, and the entries of the maps written this frame by spl_object_id, with the maps: held until reset, as an id is reused by a new object once its own is freed, and a map drawn through and gone would give its entry to another. */
    private Affine $world;
    private float $xk = 0.0;
    /** @var array<int, array{0: int, 1: Affine}> */
    private array $entries = [];
    /** The opacity what is drawn now is drawn at. */
    private float $opacity = 1.0;

    public function __construct(public CData $buf, public int $capacity, public CData $xforms, public int $xformCapacity)
    {
        $this->world = Affine::identity();
        $this->writeEntry(0, $this->world);
    }

    /** Draws what follows through $m, the map from its coordinates to the window (mygo's Painter transform); clips pushed after it are its bounds. */
    public function setWorld(Affine $m): void
    {
        if ($m === $this->world) {
            return;
        }
        $this->world = $m;
        if (!$m->set) {
            $this->xk = 0.0;
            return;
        }
        $entry = $this->entries[spl_object_id($m)][0] ?? null;
        if ($entry === null) {
            if ($this->transforms === $this->xformCapacity) {
                throw new RuntimeException("display list full ({$this->xformCapacity} transforms)");
            }
            $entry = $this->transforms++;
            $this->entries[spl_object_id($m)] = [$entry, $m];
            $this->writeEntry($entry, $m);
        }
        $this->xk = self::KIND_STRIDE * $entry;
    }

    /** The map what is drawn now is drawn through. */
    public function world(): Affine
    {
        return $this->world;
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
        $saved = $this->world;
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

    private function writeEntry(int $entry, Affine $m): void
    {
        $x = $this->xforms; $k = $entry * self::FLOATS_PER_TRANSFORM;
        $x[$k] = $m->a; $x[$k + 1] = $m->b; $x[$k + 2] = $m->c; $x[$k + 3] = $m->d;
        $x[$k + 4] = $m->x; $x[$k + 5] = $m->y; $x[$k + 6] = $m->g; $x[$k + 7] = $m->h;
    }

    /** Fills $path in $rgb (non-zero winding), or with $width > 0 strokes it that wide with round joins and caps, as mygo's FillPath and StrokePath: drawn as a glyph from a cached mask. */
    public function path(Path $path, int $rgb, float $alpha = 1.0, float $width = 0.0): void
    {
        $q = $this->pathQuad === null ? null : ($this->pathQuad)($path, $width);
        if ($q !== null && !$this->clipped($q[0], $q[1], $q[2], $q[3])) {
            $this->glyph($q[0], $q[1], $q[2], $q[3], $rgb, $alpha, $q[4], $q[5]);
        }
    }

    public function reset(): void
    {
        $this->n = 0;
        $this->glass = [];
        $this->holes = [];
        $this->clip = self::NO_CLIP;
        $this->clips = [];
        $this->transforms = 1; $this->entries = [];
        $this->world = Affine::identity(); $this->xk = 0.0;
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
        $b[$k + 8] = $radius; $b[$k + 9] = $this->xk;
        $b[$k + 12] = $c[0]; $b[$k + 13] = $c[1]; $b[$k + 14] = $c[2]; $b[$k + 15] = $c[3];
        $this->n++;
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

    /** A pane of Liquid Glass over what was drawn before it: $style 0 regular, 1 clear; tinted by $tint at $tintAlpha; blurred by $sigma pixels. */
    public function glass(float $x, float $y, float $w, float $h, float $radius, int $style, int $tint, float $tintAlpha, float $sigma): void
    {
        $this->glass[] = [$this->n, $sigma];
        $this->quad($x, $y, $w, $h, $tint, $tintAlpha, $radius, 5.0, (float)$style);
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
     * across them, $g[2] wide every $g[3] points; inside its edges a border
     * of $borders (top, right, bottom, left) in $borderRgb at $borderAlpha,
     * $dashed or solid, its inner corners each the outer less the wider of
     * its sides.
     * @param array{0: float, 1: float, 2: float, 3: float} $radii
     * @param array{0: float, 1: float, 2: float, 3: float} $borders
     * @param array{0: float, 1: float, 2: float, 3: float} $g
     */
    public function box(float $x, float $y, float $w, float $h, array $radii, int $rgb, float $alpha, array $borders = [0.0, 0.0, 0.0, 0.0], int $borderRgb = 0, float $borderAlpha = 0.0, bool $dashed = false,
                        int $paint = self::PAINT_COLOR, int $rgb2 = 0, float $alpha2 = 0.0, array $g = [0.0, 0.0, 0.0, 0.0]): void
    {
        $this->quad($x, $y, $w, $h, $rgb, $alpha, 0.0, 14.0, (float)($rgb2 & 0xffffff), $alpha2 * $this->opacity, 2);
        $r = self::fitRadii($w, $h, $radii);
        $this->slot($r, $borders, (float)($borderRgb & 0xffffff), $borderAlpha * $this->opacity, $paint + ($dashed ? 4.0 : 0.0), $g);
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

    /** Fills $path, or with $width > 0 strokes it that wide, with the gradient from $rgb at $alpha to $rgb2 at $alpha2 along the line $line(w, h) gives, in points from the top left of the path's bounds (mygo's FillPathGradient, StrokePathGradient): its mask from the atlas, as path() draws it, painted as a box (flag 8). @param \Closure(float, float): array{0: float, 1: float, 2: float, 3: float} $line */
    public function pathGradient(Path $path, float $width, int $rgb, float $alpha, int $rgb2, float $alpha2, bool $oklab, \Closure $line): void
    {
        $q = $this->pathQuad === null ? null : ($this->pathQuad)($path, $width);
        if ($q === null || $this->clipped($q[0], $q[1], $q[2], $q[3])) {
            return;
        }
        $this->quad($q[0], $q[1], $q[2], $q[3], $rgb, $alpha, 0.0, 14.0, (float)($rgb2 & 0xffffff), $alpha2 * $this->opacity, 2);
        $this->slot([(float)$q[4], (float)$q[5], 0.0, 0.0], [0.0, 0.0, 0.0, 0.0], 0.0, 0.0, ($oklab ? self::PAINT_OKLAB : self::PAINT_LINEAR) + 8.0, $line($q[2], $q[3]));
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
    public function glyph(float $x, float $y, float $w, float $h, int $rgb, float $alpha, int $u, int $v): void
    {
        if ($this->n === $this->capacity) {
            throw new RuntimeException("display list full ({$this->capacity} quads)");
        }
        $b = $this->buf; $k = $this->n * 16; $c = $this->clip;
        $b[$k] = $x; $b[$k + 1] = $y; $b[$k + 2] = $w; $b[$k + 3] = $h;
        $b[$k + 4] = (($rgb >> 16) & 255) / 255; $b[$k + 5] = (($rgb >> 8) & 255) / 255; $b[$k + 6] = ($rgb & 255) / 255; $b[$k + 7] = $alpha * $this->opacity;
        $b[$k + 8] = 0.0; $b[$k + 9] = 1.0 + $this->xk; $b[$k + 10] = $u; $b[$k + 11] = $v;
        $b[$k + 12] = $c[0]; $b[$k + 13] = $c[1]; $b[$k + 14] = $c[2]; $b[$k + 15] = $c[3];
        $this->n++;
    }
}
