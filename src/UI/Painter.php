<?php
declare(strict_types=1);

namespace MyPHP\UI;

use Closure;

/**
 * Turns a laid-out tree into display-list quads: fills, labels, clips
 * (the static methods). An instance draws into a display list as mygo's
 * Painter does, for what draws itself, as a game's frame (Plugins\Game):
 * rects are [x, y, w, h] in points, colors 0xRRGGBB with their opacity.
 */
final class Painter
{
    public function __construct(private readonly DisplayList $list, private readonly TextPainter $text, private readonly Theme $theme, private readonly ?Runtime $rt = null) {}

    /** The time of the frame being painted, in nanoseconds (as Runtime::$now), which drawings that move compute from. */
    public function now(): int
    {
        return $this->rt?->now ?? hrtime(true);
    }

    /**
     * Asks for the next frame as soon as the display can show it, for a
     * drawing that moves with now(): call it in every frame while it moves.
     * While nothing but drawings move, the frame only paints the last
     * frame's nodes again, the view neither built nor laid out (mygo's
     * Painter.AnimationFrame): what a drawing shows comes from the time and
     * what it captured, not from state the view's build changes.
     */
    public function animationFrame(): void
    {
        $this->rt?->animationFrame();
    }

    /**
     * animationFrame() for a drawing that looks as smooth at $fps frames a
     * second as at the display's rate, as a record turning, a slow wave or
     * a level meter (mygo's AnimationFrameRate): the window paints it
     * again as often, rather than at each of the display's refreshes, 120
     * a second on ProMotion, and has the display refresh as often where it
     * can, for less power. The rate rounds up to one the display shows
     * evenly, a whole number of its refreshes a frame: 60, 40 or 30 a
     * second of a 120 Hz display's, 60 or 30 of a 60 Hz one's. The window
     * draws at the fastest anything in it asks for: at the display's while
     * another drawing asks for animationFrame() or anything else moves.
     */
    public function animationFrameRate(float $fps): void
    {
        $this->rt?->animationFrameRate($fps);
    }

    /** Asks for a frame $seconds from now, for a drawing that changes then: one that only paints again, as animationFrame()'s, unless something else changed meanwhile (mygo's Painter.After). */
    public function after(float $seconds): void
    {
        $this->rt?->after($seconds);
    }

    /**
     * Calls $n's draw callback (drawOver's when $over), noting what it
     * painted when it asks to be painted again (mygo's Painter.draw,
     * paintPart): its quads and the list's state as it began. Into another
     * list than the window's (a layer's, a canvas's), or inside another
     * callback, it is called as it is.
     */
    private static function callDraw(Node $n, bool $over, DisplayList $list, Theme $t, TextPainter $text, ?Runtime $rt): void
    {
        $call = static fn() => $over ? ($n->drawOver)($list, $n, $t) : ($n->draw)($list, $n, $t, $text);
        if ($rt === null || $rt->inPart || $list !== $rt->partsList) {
            $call();
            return;
        }
        [$start, $state, $asks] = [$list->n, $list->state(), $rt->paintAsks];
        $rt->inPart = true;
        try {
            $call();
        } finally {
            $rt->inPart = false;
        }
        if ($rt->paintAsks !== $asks) {
            $rt->parts[] = ['node' => $n, 'over' => $over, 'theme' => $t, 'start' => $start, 'end' => $list->n, 'state' => $state];
        }
    }

    /** @internal Window, Headless: the window's paint begins into $list, whose draw callbacks that ask to be painted again are noted (callDraw) */
    public static function beginParts(Runtime $rt, DisplayList $list): void
    {
        [$rt->parts, $rt->partsList, $rt->untracked, $rt->partsBase] = [[], $list, false, null];
    }

    /** @internal Window, Headless: the window's paint into $list ended: the list as painted, for frames that paint again only the parts that asked */
    public static function endParts(Runtime $rt, DisplayList $list): void
    {
        $rt->partsList = null;
        $rt->partsBase = $rt->parts === [] || $rt->untracked ? null : $list->snapshot();
    }

    /**
     * Paints again, into $list, the last frame as it was painted with only
     * the draw callbacks that asked to be painted again called again, each
     * from the list's state as it began, their quads in the place of those
     * they painted (mygo's repaintParts), and tells whether it could: not
     * when something else asked (untracked), nothing was noted, a drag
     * goes on or the last frame had what the renderer reads apart. A part
     * that no longer asks stays, as what it painted last.
     */
    public static function repaintParts(Runtime $rt, DisplayList $list, TextPainter $text): bool
    {
        $base = $rt->partsBase;
        if ($base === null || $rt->untracked || $rt->parts === [] || !$rt->mayPaintParts()) {
            return false;
        }
        $rt->partPaints++;
        $list->loadTransforms($base);
        $fresh = [];
        $rt->inPart = true;
        try {
            foreach ($rt->parts as $i => $p) {
                $list->setState($p['state']);
                $from = $list->n;
                $n = $p['node'];
                $p['over'] ? ($n->drawOver)($list, $n, $p['theme']) : ($n->draw)($list, $n, $p['theme'], $text);
                $fresh[$i] = $list->copyOut($from, $list->n);
            }
        } finally {
            $rt->inPart = false;
        }
        $quads = ''; $last = 0; $q = 64;
        foreach ($rt->parts as $i => $p) {
            $quads .= substr($base[0], $last * $q, ($p['start'] - $last) * $q);
            $start = intdiv(strlen($quads), $q);
            $quads .= $fresh[$i];
            [$last, $rt->parts[$i]['start'], $rt->parts[$i]['end']] = [$p['end'], $start, intdiv(strlen($quads), $q)];
        }
        $quads .= substr($base[0], $last * $q);
        $list->n = 0;
        $list->copyIn($quads);
        $rt->partsBase = $list->snapshot(); // the transforms the parts wrote too
        return true;
    }

    /** The atlas slot of a bitmap: a canvas's in the canvases' pages, an image's in the image atlas. */
    private static function slot(TextPainter $text, Image $img): array
    {
        return $img->canvas !== null ? $img->canvas->slot() : $text->imageSlot($img);
    }

    /** The shader drawing a canvas larger than a page of the canvases' atlas as an image (looseImage), compiled on first use. */
    private static ?Shader\Shader $loose = null;

    /**
     * The source of looseImage's shader: the part Src of image0, in its
     * pixels (a negative size flips it), stretched over the rectangle,
     * blended between its pixels as an image's quad samples, or at the
     * nearest, in shades of gray (BT.709 luminance), tinted.
     */
    private const LOOSE = <<<'KAGE'
    package main

    var Src vec4
    var Tint vec3
    var Gray float
    var Nearest float

    func Fragment(dst vec4, pos vec2) vec4 {
    	p := Src.xy + pos/rectSize()*Src.zw
    	var c vec4
    	if Nearest > 0 {
    		c = image0At(p)
    	} else {
    		lo := min(Src.xy, Src.xy+Src.zw) + 0.5
    		hi := max(Src.xy, Src.xy+Src.zw) - 0.5
    		c = image0Sample(clamp(p, lo, hi))
    	}
    	if Gray > 0 {
    		c.rgb = vec3(dot(c.rgb, vec3(0.2126, 0.7152, 0.0722)))
    	}
    	c.rgb *= Tint
    	return c
    }
    KAGE;

    /**
     * Draws the part $src of canvas $cv (in its pixels, a negative size
     * flipping it), larger than a page of the canvases' atlas, in $dst, as
     * DisplayList::image draws a bitmap from the atlas: a canvas so large
     * has a texture of its own (Canvas::loose), which the quads' images do
     * not reach, so a shader reads it.
     * @param array{0: float, 1: float, 2: float, 3: float} $dst
     * @param array{0: float, 1: float, 2: float, 3: float} $src
     */
    private static function looseImage(DisplayList $list, TextPainter $text, Canvas $cv, array $dst, array $src, float $alpha, float $radius, bool $gray, bool $pixelated, ?int $tint = null): void
    {
        if ($dst[2] <= 0 || $dst[3] <= 0 || $list->clipped(...$dst)) {
            return;
        }
        $sh = self::$loose ??= Shader\Shader::compile(self::LOOSE);
        $rgb = $tint ?? 0xffffff;
        $o = new Shader\Options(uniforms: ['Src' => $src, 'Tint' => [(($rgb >> 16) & 255) / 255, (($rgb >> 8) & 255) / 255, ($rgb & 255) / 255],
            'Gray' => $gray ? 1.0 : 0.0, 'Nearest' => $pixelated ? 1.0 : 0.0], images: [$cv->bitmap()]);
        [$bytes, , $images] = $sh->inputAt($text->scale(), $dst, $o);
        $list->shaders[] = [$sh->code, $bytes, null, $images];
        $list->shader($dst[0], $dst[1], $dst[2], $dst[3], $tint === null ? $radius : 0.0, count($list->shaders) - 1, $alpha); // a tinted image is not rounded, as DisplayList::image's
    }

    /** @internal the painter of a canvas drawn during this painter's frame: into $list, a pixel a point */
    public function forCanvas(DisplayList $list): self
    {
        $list->pathQuad = $this->list->pathQuad;
        return new self($list, new CanvasText($this->text), $this->theme, $this->rt);
    }

    /** How many device pixels a point is, for drawing that lines up with the pixels of the display. */
    public function scale(): float
    {
        return $this->text->scale();
    }

    /** What the clip leaves of the window, in the coordinates of what is drawn now. @return array{0: float, 1: float, 2: float, 3: float} */
    public function visible(): array
    {
        return $this->list->visible();
    }

    /** @internal Shader: the map from what is drawn now to the window, in points. */
    public function world(): Affine
    {
        return $this->list->world();
    }

    public function theme(): Theme
    {
        return $this->theme;
    }

    /** Paints a rounded rectangle. @param array{0: float, 1: float, 2: float, 3: float} $r */
    public function fill(array $r, int $rgb, float $radius = 0, float $alpha = 1.0): void
    {
        $this->list->rect($r[0], $r[1], $r[2], $r[3], $rgb, $alpha, $radius);
    }

    /** Paints the outline of a rounded rectangle, $width points wide inside its edge. @param array{0: float, 1: float, 2: float, 3: float} $r */
    public function stroke(array $r, int $rgb, float $radius, float $width, float $alpha = 1.0): void
    {
        $this->list->ring($r[0], $r[1], $r[2], $r[3], $rgb, $alpha, $radius, $width);
    }

    /** Paints the outline of a rounded rectangle in dashes, as a dashed border (mygo's StrokeDashed). @param array{0: float, 1: float, 2: float, 3: float} $r */
    public function strokeDashed(array $r, int $rgb, float $radius, float $width, float $alpha = 1.0): void
    {
        $this->list->box($r[0], $r[1], $r[2], $r[3], [$radius, $radius, $radius, $radius], 0, 0.0, [$width, $width, $width, $width], $rgb, $alpha, true);
    }

    /** Paints the box shadow of a rounded rectangle as Node::shadow does: offset by $x and $y, blurred by $blur and grown by $spread points, only outside the rectangle, so that it does not show through what is drawn there (mygo's Shadow). @param array{0: float, 1: float, 2: float, 3: float} $r */
    public function shadow(array $r, float $radius, float $x, float $y, float $blur, float $spread, int $rgb, float $alpha = 1.0): void
    {
        self::boxShadow($this->list, $r, [$radius, $radius, $radius, $radius], [$x, $y, $blur, $spread, $rgb, $alpha, false]);
    }

    /** Paints a straight horizontal or vertical line between two points, $width points thick. */
    public function line(float $x0, float $y0, float $x1, float $y1, float $width, int $rgb, float $alpha = 1.0): void
    {
        $r = [min($x0, $x1), min($y0, $y1), abs($x1 - $x0), abs($y1 - $y0)];
        if ($r[2] < $r[3]) {
            $r[0] -= $width / 2;
            $r[2] = $width;
        } else {
            $r[1] -= $width / 2;
            $r[3] = $width;
        }
        $this->fill($r, $rgb, 0, $alpha);
    }

    /** Paints $layout with its top left at ($x, $y), $rgb at $alpha the color of what its spans leave uncolored, as rich text paints (mygo's Painter.TextLayout). */
    public function textLayout(TextLayout $layout, float $x, float $y, int $rgb, float $alpha = 1.0): void
    {
        self::paintSpans($this->list, $this->text, $layout->layout, $layout->spans, $x, $y, $rgb, $alpha);
    }

    /** Draws $glyphs (Context::shape) with the pen at ($x, $y) on the baseline, in $rgb at $alpha (mygo's Painter.Glyphs). @param list<\MyPHP\Text\Glyph> $glyphs */
    public function glyphs(array $glyphs, float $x, float $y, int $rgb, float $alpha = 1.0): void
    {
        $l = new \MyPHP\Text\Layout(new \MyPHP\Text\Params(''), []);
        $line = new \MyPHP\Text\Line();
        $line->glyphs = $glyphs;
        $l->lines = [$line];
        $color = [$rgb, $alpha];
        $this->text->paintLayout($this->list, $l, $x, $y, static fn(): array => $color);
    }

    /** Draws a line of text with its top-left corner at ($x, $y). */
    public function text(float $x, float $y, string $s, float $size, int $rgb, float $alpha = 1.0): void
    {
        $this->text->paint($this->list, $s, $size, $x, $y, $rgb, $alpha);
    }

    /** The width and height richText() draws $spans in, wrapped at $width points, unwrapped for 0 (mygo's MeasureText). @return array{0: float, 1: float} */
    public function measureText(float $width, Span ...$spans): array
    {
        return $this->spans($width, $spans)->size();
    }

    /** Draws $spans with their top left at ($x, $y), wrapped at $width points, unwrapped for 0, in the theme's text and size where they set none (mygo's Painter.RichText). */
    public function richText(float $x, float $y, float $width, Span ...$spans): void
    {
        $this->textLayout($this->spans($width, $spans), $x, $y, $this->theme->text);
    }

    /** @param list<Span> $spans */
    private function spans(float $width, array $spans): TextLayout
    {
        return TextLayout::shape($this->text->textSystem(), $spans, new TextStyle(), $this->theme->fontSize, $width);
    }

    /** Draws a bitmap (pixelated if it is), or an SVG in its own colors with the theme's text color for its currentColor, as $fit (Node::CONTAIN…) places it in $r. @param array{0: float, 1: float, 2: float, 3: float} $r */
    public function image(Image|SVG $src, array $r, int $fit): void
    {
        self::picture($this->list, $this->text, $src, $r[0], $r[1], $r[2], $r[3], $fit, $this->theme->text);
    }

    /**
     * Draws the part $src of bitmap $b, in its pixels ([] for all of it),
     * stretched into $dst, as image() draws a bitmap (pixelated if it is),
     * unless $dst is outside what shows. Nothing of the bitmap around $src
     * shows, so that the parts of a bitmap holding many, as the frames of
     * an animation, draw on their own. Under a transform, $dst is where it
     * is, not snapped to pixels, so that a part moving through a zoomed
     * view glides:
     *
     *     $p->imageRegion($sheet, [32, 0, 16, 16], $box);
     *
     * @param array{0: float, 1: float, 2: float, 3: float}|array{} $src
     * @param array{0: float, 1: float, 2: float, 3: float} $dst
     */
    public function imageRegion(Image $b, array $src, array $dst, ImageOptions $o = new ImageOptions): void
    {
        if ($b->w === 0 || $b->h === 0 || $dst[2] <= 0 || $dst[3] <= 0 || $this->list->clipped(...$dst)) {
            return;
        }
        if ($src === [] || $src[2] <= 0 || $src[3] <= 0) {
            $src = [0.0, 0.0, (float)$b->w, (float)$b->h];
        }
        $scale = $this->scale();
        // Far smaller than its pixels, a level of the bitmap, as smooth.
        $shrink = min($src[2] / ($dst[2] * $scale), $src[3] / ($dst[3] * $scale));
        $shown = $b->smaller($shrink);
        $slot = $b->canvas?->loose() ? ['u' => 0, 'v' => 0, 'page' => -1] : self::slot($this->text, $shown); // a loose canvas's own texture: its pixels from 0
        $kx = $shown->w / $b->w; $ky = $shown->h / $b->h;
        $u = $slot['u'] + (int)round($src[0] * $kx); $v = $slot['v'] + (int)round($src[1] * $ky);
        $sw = max(1, (int)round($src[2] * $kx)); $sh = max(1, (int)round($src[3] * $ky));
        if ($o->flipX) {
            [$u, $sw] = [$u + $sw, -$sw];
        }
        if ($o->flipY) {
            [$v, $sh] = [$v + $sh, -$sh];
        }
        // Snapped to the pixels of the screen, unless a transform places it:
        // snapping before a zoom would move a part in steps of several
        // pixels, where pixelated sampling glides.
        $r = $dst;
        if (!$this->list->world()->set) {
            $x0 = round($dst[0] * $scale) / $scale; $y0 = round($dst[1] * $scale) / $scale;
            $r = [$x0, $y0, round(($dst[0] + $dst[2]) * $scale) / $scale - $x0, round(($dst[1] + $dst[3]) * $scale) / $scale - $y0];
        }
        if ($slot['page'] < 0) {
            self::looseImage($this->list, $this->text, $b->canvas, $r, [(float)$u, (float)$v, (float)$sw, (float)$sh], $o->tint === null ? 1.0 : $o->tintAlpha, 0.0, false, $b->pixelated && $shrink <= 1, $o->tint);
            return;
        }
        $this->list->image($r[0], $r[1], $r[2], $r[3], $u, $v, $sw, $sh, $slot['page'], $o->tint === null ? 1.0 : $o->tintAlpha, pixelated: $b->pixelated && $shrink <= 1, tint: $o->tint);
    }

    /** Draws the ring that shows the keyboard focus around $r. @param array{0: float, 1: float, 2: float, 3: float} $r */
    public function focusRing(array $r, float $radius): void
    {
        self::paintFocusRing($this->list, $r[0], $r[1], $r[2], $r[3], $radius, $this->theme);
    }

    /** Restricts what $fn draws to $r. @param array{0: float, 1: float, 2: float, 3: float} $r */
    public function clip(array $r, \Closure $fn): void
    {
        $this->list->pushClip(...$r);
        try {
            $fn();
        } finally {
            $this->list->popClip();
        }
    }

    /** Transforms what $fn draws by $t around ($ox, $oy), after the painter's own transforms: it nests. */
    public function transform(Transform $t, float $ox, float $oy, \Closure $fn): void
    {
        $this->list->transform($t, $ox, $oy, $fn);
    }

    /** Paints a box with a gradient, at any angle, from its start to its end, or of its stops, in sRGB or Oklab (mygo's FillGradient). @param array{0: float, 1: float, 2: float, 3: float} $box */
    public function fillGradient(array $box, LinearGradient $g, float $radius = 0): void
    {
        $this->fillPaint($box, $g, $radius);
    }

    /** Paints a box with a radial gradient, its colors spreading out from a point of it (mygo's FillRadialGradient). @param array{0: float, 1: float, 2: float, 3: float} $box */
    public function fillRadialGradient(array $box, RadialGradient $g, float $radius = 0): void
    {
        $this->fillPaint($box, $g, $radius);
    }

    /** Paints a box with a conic gradient, its colors turning around a point of it (mygo's FillConicGradient). @param array{0: float, 1: float, 2: float, 3: float} $box */
    public function fillConicGradient(array $box, ConicGradient $g, float $radius = 0): void
    {
        $this->fillPaint($box, $g, $radius);
    }

    /** @param array{0: float, 1: float, 2: float, 3: float} $box */
    private function fillPaint(array $box, LinearGradient|RadialGradient|ConicGradient $g, float $radius): void
    {
        [$paint, $c1, $a1, $c2, $a2, $line, $stops] = self::gradientPaint($g, $box[2], $box[3], 1.0);
        $this->list->box($box[0], $box[1], $box[2], $box[3], [$radius, $radius, $radius, $radius], $c1, $a1, paint: $paint, rgb2: $c2, alpha2: $a2, g: $line, stops: $stops);
    }

    /**
     * Paints a pane of Liquid Glass over what was painted before (the
     * renderer's glass effect, which Plugins\Glass draws with): $style 0
     * regular, 1 clear; tinted by $tint at $tintAlpha; what shows through
     * blurred by $sigma pixels; $tone as DisplayList::glass takes it.
     * @param array{0: float, 1: float, 2: float, 3: float} $box
     * @param array{0: float, 1: float, 2: float, 3: float, 4: float} $tone
     */
    public function glass(array $box, float $radius, int $style, int $tint, float $tintAlpha, float $sigma, array $tone = DisplayList::GLASS_TONE): void
    {
        $this->list->glass($box[0], $box[1], $box[2], $box[3], $radius, $style, $tint, $tintAlpha, $sigma, $tone);
    }

    /**
     * Paints a level of a backdrop blur (the renderer's blur effect): what
     * was painted before, blurred by $sigma pixels, where the blur wanted,
     * from $at0 pixels at the start of $line to $at1 at its end (in the
     * window's pixels), is more than $lo, wholly from $hi.
     * @param array{0: float, 1: float, 2: float, 3: float} $box
     * @param array{0: float, 1: float, 2: float, 3: float} $line
     */
    public function blurLevel(array $box, float $radius, float $sigma, array $line, float $at0, float $at1, float $lo, float $hi): void
    {
        // t along the line as a function of the quad's points, the band's weight as one of t.
        $s = $this->scale();
        $dx = $line[2] - $line[0]; $dy = $line[3] - $line[1];
        $l = $dx * $dx + $dy * $dy;
        [$t0, $tx, $ty] = $l > 0 ? [(($box[0] * $s - $line[0]) * $dx + ($box[1] * $s - $line[1]) * $dy) / $l, $s * $dx / $l, $s * $dy / $l] : [0.0, 0.0, 0.0];
        $band = $hi - $lo;
        $this->list->blurLevel($box[0], $box[1], $box[2], $box[3], $radius, $sigma, ($at0 - $lo) / $band, ($at1 - $at0) / $band, $t0, $tx, $ty);
    }

    /** Paints what was painted before blurred by $sigma pixels and toned as macOS's hard scroll edge, under $rgb by $mix. @param array{0: float, 1: float, 2: float, 3: float} $box */
    public function hardBlur(array $box, float $radius, float $sigma, int $rgb, float $mix): void
    {
        $this->list->hardBlur($box[0], $box[1], $box[2], $box[3], $radius, $sigma, $rgb, $mix);
    }

    /** Fills $path (non-zero winding). */
    public function fillPath(Path $path, int $rgb, float $alpha = 1.0): void
    {
        $this->list->path($path, $rgb, $alpha);
    }

    /** Fills $path with $g across its bounds, as the area under a chart's line (mygo's FillPathGradient). */
    public function fillPathGradient(Path $path, LinearGradient $g): void
    {
        $this->pathPaint($path, 0.0, $g);
    }

    /** Strokes $path as strokePath does, $width points wide, in $g across its bounds (mygo's StrokePathGradient). */
    public function strokePathGradient(Path $path, float $width, LinearGradient $g): void
    {
        $this->pathPaint($path, $width, $g);
    }

    /** Fills $path with $g around its bounds (mygo's FillPathConicGradient). */
    public function fillPathConicGradient(Path $path, ConicGradient $g): void
    {
        $this->pathPaint($path, 0.0, $g);
    }

    /** Strokes $path as strokePath does, $width points wide, in $g around its bounds (mygo's StrokePathConicGradient). */
    public function strokePathConicGradient(Path $path, float $width, ConicGradient $g): void
    {
        $this->pathPaint($path, $width, $g);
    }

    private function pathPaint(Path $path, float $width, LinearGradient|ConicGradient $g): void
    {
        [$paint, $c1, $a1, $c2, $a2, , $stops] = self::gradientPaint($g, 1.0, 1.0, 1.0);
        $this->list->pathGradient($path, $width, $paint, $c1, $a1, $c2, $a2, static fn(float $w, float $h): array => self::gradientPaint($g, $w, $h, 1.0)[5], $stops);
    }

    /** Strokes $path $width points wide, with its caps, joins and dashes (round and whole unless it sets them); trimmed shorter than its width (Path::trim), it fades with its length, unless its caps are butt, which show no dot. */
    public function strokePath(Path $path, float $width, int $rgb, float $alpha = 1.0): void
    {
        if ($path->kept !== null && $path->kept < $width && $path->cap !== Path::CAP_BUTT) {
            $alpha *= $width > 0 ? $path->kept / $width : 0.0;
            if ($alpha <= 0) { return; }
        }
        $this->list->path($path, $rgb, $alpha, $width);
    }

    /**
     * Draws a line through $points ([x, y] each) $width points wide, with
     * round joins and caps, as strokePath() draws a path of straight lines
     * (mygo's StrokePolyline): a NaN or infinite point breaks the line
     * there, and a point alone draws nothing. It suits lines that change
     * every frame, as live charts: an opaque line draws each segment as a
     * capsule of its own instead of a mask of the whole line, so that its
     * edges can look a little bolder where segments overlap. A translucent
     * line draws as strokePath() draws it, which keeps overlaps from
     * showing darker.
     *
     * @param list<array{0: float, 1: float}> $points
     */
    public function strokePolyline(array $points, float $width, int $rgb, float $alpha = 1.0): void
    {
        if (!($width > 0) || !is_finite($width) || $alpha <= 0) {
            return;
        }
        $through = $alpha < 1 || $this->list->opacity() < 1;
        $path = $through ? new Path : null;
        $prev = null; // the point before, in the run
        foreach ($points as [$x, $y]) {
            if (!is_finite($x) || !is_finite($y)) {
                $prev = null;
                continue;
            }
            if ($path !== null) {
                $prev === null ? $path->moveTo($x, $y) : $path->lineTo($x, $y);
            } elseif ($prev !== null) {
                $this->list->line($prev[0], $prev[1], $x, $y, $rgb, $alpha, $width);
            }
            $prev = [$x, $y];
        }
        if ($path !== null && $path->cmds !== []) {
            $this->list->path($path, $rgb, $alpha, $width);
        }
    }

    /**
     * @internal Shader: fills $r, rounded by $radius, with the shader of
     * $code reading $bytes (its uniforms; $wide in their place where the
     * window draws a wide gamut) and $images.
     * @param array{0: float, 1: float, 2: float, 3: float} $r
     * @param list<?Image> $images
     */
    public function shaderEffect(Shader\Code $code, array $r, float $radius, string $bytes, ?string $wide, array $images, float $blur = 0.0): void
    {
        if ($r[2] <= 0 || $r[3] <= 0 || $this->list->clipped(...$r)) {
            return;
        }
        if ($code->backdrop) {
            $this->list->glass[] = [$this->list->n, $blur]; // what is drawn before it, copied, and blurred by $blur pixels unless 0: its backdrop
        }
        $this->list->shader($r[0], $r[1], $r[2], $r[3], $radius, $this->shaderDraw($code, $bytes, $wide, $images));
    }

    /**
     * @internal Shader: paints what $fn paints with the shader of $code in
     * place of the colors of its glyphs' and paths' masks, filling $box
     * (mygo's ShaderFill).
     * @param array{0: float, 1: float, 2: float, 3: float} $box
     * @param list<?Image> $images
     */
    public function shaderFill(Shader\Code $code, array $box, Closure $fn, string $bytes, ?string $wide, array $images): void
    {
        $from = $this->list->n;
        $fn();
        if ($this->list->n > $from) {
            $draw = count($this->list->shaders);
            if ($this->list->shaderGlyphs($from, $draw, $box[0], $box[1]) > 0) {
                $this->shaderDraw($code, $bytes, $wide, $images);
            }
        }
    }

    /** A shader's draw among the list's, by its index. @param list<?Image> $images */
    private function shaderDraw(Shader\Code $code, string $bytes, ?string $wide, array $images): int
    {
        foreach ($images as $img) {
            if ($img?->canvas !== null && !$img->canvas->loose()) {
                $img->canvas->slot(); // a canvas the shader reads has its place, where it renders
            }
        }
        $this->list->shaders[] = [$code, $bytes, $wide, $images];
        return count($this->list->shaders) - 1;
    }

    /** Draws the shapes of $svg fitted in $r in $rgb. @param array{0: float, 1: float, 2: float, 3: float} $r */
    public function icon(SVG $svg, array $r, int $rgb, float $alpha = 1.0): void
    {
        self::paintIcon($this->list, $this->text, $svg, $r[0], $r[1], $r[2], $r[3], $rgb, $alpha);
    }

    /**
     * Paints $n and its children through the node's world (its transform
     * after its ancestors', as Runtime::record worked it out) at its
     * opacity times its ancestors', as mygo's Painter.element: a node turned
     * away with backfaceHidden, or transparent, paints nothing.
     */
    public static function paint(Node $n, DisplayList $list, TextPainter $text, Theme $t, ?Runtime $rt = null): void
    {
        if ($n->memo !== 0 && $rt !== null && ($args = $rt->memoPaintArgs($n, $list, $t)) !== null) { // a memo's: drawn again as it was, where it was drawn so (RuntimeMemo)
            if ($n->memo === 2 && $rt->memoRepaint($n, $args, $list, $text)) { return; }
            $mark = $rt->memoPaintBegin($list, $text);
            self::paintNode($n, $list, $text, $t, $rt);
            $rt->memoPaintEnd($n, $args, $mark, $list, $text);
            return;
        }
        self::paintNode($n, $list, $text, $t, $rt);
    }

    private static function paintNode(Node $n, DisplayList $list, TextPainter $text, Theme $t, ?Runtime $rt): void
    {
        if ($n->invisible || $n->backface && $n->transform->facesAway()) {
            return;
        }
        if ($list->cull && !$n->noCull && $list->outOfClip($n->ink)) { // what it and the nodes inside it paint shows nowhere: skipped whole (mygo's paintable)
            if ($rt !== null) { $rt->culled++; }
            return;
        }
        if ($n->belowMaterials && $rt !== null && $rt->underLayer && !$rt->paintingUnder) { // the window paints it under its materials, from where its painting is now
            $rt->underPaints[] = ['node' => $n, 'world' => $list->world(), 'opacity' => $list->opacity(), 'clip' => $list->clip(), 'mask' => $list->mask()];
            return;
        }
        if ($n->effect !== null && !$n->blurring && $rt !== null && self::paintEffected($n, $list, $text, $t, $rt)) {
            return;
        }
        if ($n->blur > 0 && !$n->blurring && $rt !== null && self::paintBlurred($n, $list, $text, $t, $rt)) {
            return;
        }
        if ($n->cache && !$n->blurring && $rt !== null && self::paintCached($n, $list, $text, $t, $rt)) {
            return;
        }
        $world = $list->world(); $opacity = $list->opacity();
        $dim = $n->disabled; // disabled, at half its opacity, and what is inside it with it (mygo's paint)
        if ($n->opacity < 1 || $n->poseFade > 0 || $dim) {
            $o = $opacity * $n->opacity * (1 - $n->poseFade) * ($dim ? 0.5 : 1.0);
            if ($o <= 0.001) {
                return;
            }
            $list->setOpacity($o);
        }
        $list->setWorld($n->world);
        $mask = $list->mask();
        if ($n->clipMask !== null) { // its box where it shows, upright, the image's slot over it
            if ($mask !== null) { throw new \LogicException('a clip mask inside another'); }
            [$x0, $y0] = $n->world->point($n->x, $n->y); [$x1, $y1] = $n->world->point($n->x + $n->w, $n->y + $n->h);
            $slot = $n->clipMask[0] === null ? ['u' => 0, 'v' => 0, 'w' => 0, 'h' => 0, 'page' => 0] : $text->imageSlot($n->clipMask[0]); // none: the box itself
            $list->setMask([min($x0, $x1), min($y0, $y1), abs($x1 - $x0), abs($y1 - $y0), $slot['u'], $slot['v'], $slot['w'], $slot['h'], $slot['page'], $n->clipMask[1]]);
        }
        self::node($n, $list, $text, $n->theme ?? $t, $rt);
        if ($n->clipMask !== null) { $list->setMask($mask); }
        $list->setWorld($world);
        $list->setOpacity($opacity);
    }

    /**
     * Paints $n blurred (Node::blur, mygo's paintEffect), and tells whether
     * it did: its subtree drawn into a canvas of its box and 3 sigma more
     * each way, in the window's pixels, or in fewer for a wide blur (as
     * mygo blurs a large one at a fraction of the size, a power of two up
     * to 8 that leaves it 2 pixels at least), which the renderer blurs,
     * transparent beyond it; the canvas shown where it was drawn from,
     * through $n's map. A canvas too large paints it as it is; out of the
     * clip, nothing.
     */
    private static function paintBlurred(Node $n, DisplayList $list, TextPainter $text, Theme $t, Runtime $rt): bool
    {
        $m = 3 * $n->blur;
        $world = $list->world();
        $list->setWorld($n->world);
        $out = $list->clipped($n->x - $m, $n->y - $m, $n->w + 2 * $m, $n->h + 2 * $m);
        $list->setWorld($world);
        if ($n->w <= 0 || $n->h <= 0 || $out) {
            return true;
        }
        $blur = $n->blur * $text->scale(); // in the window's pixels
        $down = 1;
        while ($down < 8 && $blur / (2 * $down) >= 2) { $down *= 2; }
        $s = $text->scale() / $down;
        [$x0, $y0] = [floor(($n->x - $m) * $s), floor(($n->y - $m) * $s)];
        [$w, $h] = [(int)(ceil(($n->x + $n->w + $m) * $s) - $x0), (int)(ceil(($n->y + $n->h + $m) * $s) - $y0)];
        if ($w > Canvas::MAX || $h > Canvas::MAX) {
            return false;
        }
        $cv = $rt->blurLayers[$n->id] ?? null;
        if ($cv === null || $cv->w !== $w || $cv->h !== $h) {
            $cv = $rt->blurLayers[$n->id] = new Canvas($w, $h);
        }
        $cv->blur = sqrt(max($blur * $blur - $down * $down / 12, 0.0)) / $down; // the average of each square of $down pixels blurs it by its variance
        self::layer($n, $cv, new self($list, $text, $t, $rt), Affine::matrix($s, 0, 0, $s, -$x0, -$y0), $t, $rt);
        $list->setWorld($n->world);
        self::showLayer($list, $text, $cv, $x0 / $s, $y0 / $s, $w / $s, $h / $s);
        $list->setWorld($world);
        return true;
    }

    /**
     * Paints $n's subtree into its layer, canvas $cv, through $base, the
     * node's layout in the canvas's pixels, and through $n's map, as a
     * layer of its content; a node kept (Node::cache) only draws it anew
     * when what it paints differs (keep).
     */
    private static function layer(Node $n, Canvas $cv, Painter $p, Affine $base, Theme $t, Runtime $rt): void
    {
        if ($n->world->set) {
            $base = $base->mul($n->world->inverse() ?? Affine::singular());
        }
        $fn = static function (Painter $cp) use ($n, $base, $t, $rt): void {
            $cp->list->setBase($base, $n->world); // the node's map from the start, which paintNode sets: no map of the window's written, as a kept layer compares them
            $n->blurring = true;
            try {
                self::paintNode($n, $cp->list, $cp->text, $t, $rt);
            } finally {
                $n->blurring = false;
            }
        };
        $n->cache ? self::keep($cv, $p, $fn) : $cv->draw($p, $fn);
    }

    /**
     * Draws kept canvas $cv as Canvas::draw does, into the other of its two
     * lists, unless what $fn paints is what it painted last (mygo's
     * layerCache): the same quads and transforms, the same versions of the
     * same images, no shader, no glass, and no canvas drawn inside it; then
     * it is not rendered again, and the window's frame finds its image the
     * same. Its glyphs and images are in the atlases all the same, as this
     * frame painted them.
     */
    private static function keep(Canvas $cv, Painter $p, Closure $fn): void
    {
        [$cv->list, $cv->spare] = [$cv->spare ?? Canvas::newList(), $cv->list];
        $l = $cv->list;
        $l->reset();
        $l->pushClip(0, 0, $cv->w, $cv->h);
        $cp = $p->forCanvas($l);
        $draws = CanvasAtlas::$draws;
        $fn($cp);
        $kept = $l->glass !== [] || $l->shaders !== [] || $l->holes !== [] || CanvasAtlas::$draws !== $draws ? null
            : $l->copyOut(0, $l->n) . "\0" . $l->transformBytes() . "\0" . json_encode($cp->text->images);
        if ($kept === null || $kept !== $cv->kept || $cv->drawn === 0) {
            $cv->rendered();
        }
        $cv->kept = $kept;
    }

    /** Shows layer $cv over ($x, $y, $w, $h), in points. */
    private static function showLayer(DisplayList $list, TextPainter $text, Canvas $cv, float $x, float $y, float $w, float $h): void
    {
        if ($cv->loose()) {
            self::looseImage($list, $text, $cv, [$x, $y, $w, $h], [0.0, 0.0, (float)$cv->w, (float)$cv->h], 1.0, 0.0, false, false);
        } else {
            $slot = self::slot($text, $cv->bitmap());
            $list->image($x, $y, $w, $h, $slot['u'], $slot['v'], $slot['w'], $slot['h'], $slot['page']);
        }
    }

    /**
     * Paints $n kept in a bitmap (Node::cache, mygo's Cache), and tells
     * whether it did: its subtree drawn into a canvas of its layer's area
     * on the window's pixel grid, again only when what it paints differs
     * (keep), shown where it was drawn from through $n's map. A node too
     * large paints as it is; out of the clip, nothing.
     */
    private static function paintCached(Node $n, DisplayList $list, TextPainter $text, Theme $t, Runtime $rt): bool
    {
        [$ax, $ay, $ax1, $ay1] = self::layerArea($n);
        $world = $list->world();
        $list->setWorld($n->world);
        $out = $list->clipped($ax, $ay, $ax1 - $ax, $ay1 - $ay);
        $list->setWorld($world);
        if ($n->w <= 0 || $n->h <= 0 || $out) {
            return true;
        }
        $s = $text->scale();
        [$x0, $y0] = [floor($ax * $s), floor($ay * $s)];
        [$w, $h] = [(int)(ceil($ax1 * $s) - $x0), (int)(ceil($ay1 * $s) - $y0)];
        if ($w > Canvas::MAX || $h > Canvas::MAX) {
            return false;
        }
        $cv = $rt->blurLayers[$n->id] ?? null;
        if ($cv === null || $cv->w !== $w || $cv->h !== $h || $cv->blur > 0) {
            $cv = $rt->blurLayers[$n->id] = new Canvas($w, $h);
        }
        self::layer($n, $cv, new self($list, $text, $t, $rt), Affine::matrix($s, 0, 0, $s, -$x0, -$y0), $t, $rt);
        $list->setWorld($n->world);
        self::showLayer($list, $text, $cv, $x0 / $s, $y0 / $s, $w / $s, $h / $s);
        $list->setWorld($world);
        return true;
    }

    /**
     * The area of a kept node's layer, in its layout (mygo's keptLayer.area):
     * its box grown by its ink's margin (Node::inkMargin), and, unless it
     * clips, those of the nodes inside it, through their maps, clipped by
     * those that clip. @return array{0: float, 1: float, 2: float, 3: float} [x0, y0, x1, y1]
     */
    private static function layerArea(Node $n): array
    {
        $grown = static fn(Node $k, float $m): array => [$k->x - $m, $k->y - $m, $k->x + $k->w + $m, $k->y + $k->h + $m];
        $area = $grown($n, $n->inkMargin());
        $inv = $n->world->set ? $n->world->inverse() : Affine::identity();
        if ($n->scroll !== null || $n->clip !== 0 || $inv === null) {
            return $area; // all inside within the box
        }
        $in = static function (Node $k, array $r) use ($inv): array { // $r of $k's layout in $n's
            $m = $k->world->set ? $inv->mul($k->world) : $inv;
            [$x, $y, $w, $h] = $m->bounds($r[0], $r[1], $r[2] - $r[0], $r[3] - $r[1]);
            return [$x, $y, $x + $w, $y + $h];
        };
        $meet = static fn(array $a, ?array $b): array => $b === null ? $a : [max($a[0], $b[0]), max($a[1], $b[1]), min($a[2], $b[2]), min($a[3], $b[3])];
        $walk = static function (Node $p, ?array $clip) use (&$walk, &$area, $grown, $in, $meet): void {
            foreach ($p->kids as $k) {
                if ($k->invisible) { continue; }
                $r = $meet($in($k, $grown($k, $k->inkMargin())), $clip);
                if ($r[2] > $r[0] && $r[3] > $r[1]) {
                    $area = [min($area[0], $r[0]), min($area[1], $r[1]), max($area[2], $r[2]), max($area[3], $r[3])];
                }
                if ($k->scroll !== null || $k->clip !== 0) {
                    $c = $in($k, [($k->clip & Node::CLIP_X) !== 0 || $k->scroll !== null ? $k->x : -1e6, ($k->clip & Node::CLIP_Y) !== 0 || $k->scroll !== null ? $k->y : -1e6,
                        ($k->clip & Node::CLIP_X) !== 0 || $k->scroll !== null ? $k->x + $k->w : 1e6, ($k->clip & Node::CLIP_Y) !== 0 || $k->scroll !== null ? $k->y + $k->h : 1e6]);
                    $walk($k, $meet($c, $clip));
                } else {
                    $walk($k, $clip);
                }
            }
        };
        $walk($n, null);
        return $area;
    }

    /**
     * Paints $n through its effect (mygo's paintEffect): its subtree
     * rendered into its layer, a canvas of its box and the effect's margin
     * on the pixel grid, then the effect over the box and the margin,
     * through $n's map. Whether it did: a node too large to render apart
     * paints as it is.
     */
    private static function paintEffected(Node $n, DisplayList $list, TextPainter $text, Theme $t, Runtime $rt): bool
    {
        [$mx, $my] = $n->effect->effectMargin();
        [$mx, $my] = [max($mx, 0.0), max($my, 0.0)];
        $world = $list->world();
        $list->setWorld($n->world);
        $out = $list->clipped($n->x - $mx, $n->y - $my, $n->w + 2 * $mx, $n->h + 2 * $my);
        $list->setWorld($world);
        if ($n->w <= 0 || $n->h <= 0 || $out) {
            return true;
        }
        $s = $text->scale();
        [$x0, $y0] = [floor(($n->x - $mx) * $s), floor(($n->y - $my) * $s)];
        [$w, $h] = [(int)(ceil(($n->x + $n->w + $mx) * $s) - $x0), (int)(ceil(($n->y + $n->h + $my) * $s) - $y0)];
        if ($w > Canvas::MAX || $h > Canvas::MAX) {
            return false;
        }
        $cv = $rt->blurLayers[$n->id] ?? null; // the layers of blurs, of effects and of nodes kept, by node
        if ($cv === null || $cv->w !== $w || $cv->h !== $h || $cv->blur > 0) {
            $cv = $rt->blurLayers[$n->id] = new Canvas($w, $h);
        }
        self::layer($n, $cv, new self($list, $text, $t, $rt), Affine::matrix($s, 0, 0, $s, -$x0, -$y0), $t, $rt); // the node's layout, in the canvas's pixels
        $list->setWorld($n->world);
        $n->effect->paintEffect(new self($list, $text, $t, $rt), [$n->x, $n->y, $n->w, $n->h], $cv->bitmap(), [$n->x * $s - $x0, $n->y * $s - $y0]);
        $list->setWorld($world);
        return true;
    }

    /**
     * The hole a web view's or a video's node paints through the content
     * (mygo's noteWebView): its content box on whole pixels, rounded by
     * its radii less its borders, within the clip; the runtime notes where
     * it shows, for the window to place the web view or the video's layer
     * there. A system material's node opens its box inside its border,
     * padding and all, as the material is its background. They stay
     * upright, covering a transformed box's bounds.
     * @param array{0: float, 1: float, 2: float, 3: float} $radii
     */
    private static function nativeHole(Node $n, DisplayList $list, TextPainter $text, array $radii, Runtime $rt): void
    {
        // Inside the padding and the border, border() as borderWidth(): the page covered border()'s, which the web views' tab showed none of.
        [$pt, $pr, $pb, $pl] = $n->sides();
        [$it, $ir, $ib, $il] = [$n->padT(), $n->padR(), $n->padB(), $n->padL()];
        [$bt, $br, $bb, $bl] = [$it - $pt, $ir - $pr, $ib - $pb, $il - $pl];
        if ($n->systemMaterial !== null) {
            [$it, $ir, $ib, $il] = [$bt, $br, $bb, $bl];
        }
        [$x, $y, $w, $h] = [$n->x + $il, $n->y + $it, $n->w - $il - $ir, $n->h - $it - $ib];
        [$x, $y, $w, $h] = $list->world()->set ? $list->world()->bounds($x, $y, $w, $h) : [$x, $y, $w, $h];
        $s = $text->scale();
        [$x0, $y0, $x1, $y1] = [round($x * $s) / $s, round($y * $s) / $s, round(($x + $w) * $s) / $s, round(($y + $h) * $s) / $s];
        [$x, $y, $w, $h] = [$x0, $y0, $x1 - $x0, $y1 - $y0];
        $c = $list->clip();
        $cx = max($x, $c[0]); $cy = max($y, $c[1]);
        $cw = min($x + $w, $c[0] + $c[2]) - $cx; $ch = min($y + $h, $c[1] + $c[3]) - $cy;
        if ($w <= 0 || $h <= 0 || $cw <= 0 || $ch <= 0) {
            return;
        }
        $inner = [max(0.0, $radii[0] - max($bt, $bl)), max(0.0, $radii[1] - max($bt, $br)), max(0.0, $radii[2] - max($bb, $br)), max(0.0, $radii[3] - max($bb, $bl))];
        $list->hole($x, $y, $w, $h, $inner);
        $place = ['view' => $n->webView ?? $n->movie, 'id' => $n->id, 'frame' => [$x, $y, $w, $h], 'clip' => [$cx, $cy, $cw, $ch]];
        if ($n->webView !== null) {
            $rt->webPlaces[] = $place;
        } elseif ($n->movie !== null) {
            $rt->videoPlaces[] = $place;
        } else {
            $rt->materialPlaces[] = ['id' => $n->id, 'frame' => [$x, $y, $w, $h], 'clip' => [$cx, $cy, $cw, $ch], 'material' => $n->systemMaterial[0], 'dark' => $n->systemMaterial[1], 'radius' => max($inner), 'alpha' => $list->opacity()];
        }
    }

    private static function node(Node $n, DisplayList $list, TextPainter $text, Theme $t, ?Runtime $rt): void
    {
        if ($list->clipped($n->x, $n->y, $n->w, $n->h) && ($n->kids === [] || $n->clip !== 0 || $n->scroll !== null)) {
            return; // out of the clip; a container that clips nothing goes on, its children reaching past its box (absolute, transformed, overflowing)
        }
        if ($n->kind === Node::INPUT) {
            self::paintField($n, $list, $text, $t, $rt);
            return;
        }
        if ($n->kind === Node::TEXTAREA) {
            self::paintArea($n, $list, $text, $t, $rt);
            return;
        }
        if ($n->kind === Node::CHECKBOX || $n->kind === Node::RADIO) {
            self::paintToggle($n, $list, $text, $t, $rt);
            return;
        }
        if ($n->kind === Node::SWITCH) {
            self::paintSwitch($n, $list, $text, $t, $rt);
            return;
        }
        if ($n->kind === Node::SPINNER) {
            self::paintSpinner($n, $list, $t, $rt);
            return;
        }
        if ($n->kind === Node::SLIDER) {
            if ($n->draw !== null) { self::callDraw($n, false, $list, $t, $text, $rt); } else { self::paintSlider($n, $list, $t); } // a draw of its own (a color picker's channel) paints the whole slider
            return;
        }
        if ($n->kind === Node::PROGRESS) { // a bar as thick as the node was made (a column stretches the node), in the accent or the color given (a meter's level)
            $h = $n->fh; $y = $n->y + ($n->h - $h) / 2; $r = $h / 2;
            $list->rect($n->x, $y, $n->w, $h, $t->border, 1.0, $r);
            if ($n->fraction >= 0) {
                $fill = $n->w * min(1.0, $n->fraction);
                $list->rect($n->reverse !== $n->rtl ? $n->x + $n->w - $fill : $n->x, $y, $fill, $h, $n->color ?? $t->accent, 1.0, $r); // reversed, or right to left: from the right
            } else { // indeterminate: a third of the bar sweeping across, once every 1.5 s, painted again every frame (mygo's indicators)
                $rt?->animationFrame();
                $phase = fmod(($rt?->now ?? hrtime(true)) / 1.5e9, 1.0);
                $bar = $n->w / 3;
                $x = $n->reverse !== $n->rtl ? $n->x + $n->w - $phase * ($n->w + $bar) : $n->x - $bar + $phase * ($n->w + $bar);
                $list->pushClip($n->x, $y, $n->w, $h);
                $list->rect($x, $y, $bar, $h, $t->accent, 1.0, $r);
                $list->popClip();
            }
            return;
        }
        if ($n->kind === Node::IMAGE) {
            if ($n->content === null || $rt === null || !self::contentImage($n, $list, $text, $t, $rt)) {
                self::paintImage($n, $list, $text, $t);
            }
            return;
        }
        if ($n->focusVisible && $n->color !== null && $n->radii !== null) { // around corners each their own
            $list->box($n->x - 3, $n->y - 3, $n->w + 6, $n->h + 6, array_map(static fn(float $r): float => $r + 3, $n->radii), 0, 0.0, [2.0, 2.0, 2.0, 2.0], $t->focus, $t->focusAlpha);
        } elseif ($n->focusVisible && $n->color !== null) { // a ring around a button or other faced node
            self::paintFocusRing($list, $n->x, $n->y, $n->w, $n->h, match ($n->kind) { Node::BUTTON => $t->radius, default => $n->radius }, $t);
        }
        $radius = match ($n->kind) { Node::BUTTON => $t->radius, default => $n->radius };
        $box = [$n->x, $n->y, $n->w, $n->h];
        $radii = $n->radii ?? [$radius, $radius, $radius, $radius];
        foreach ($n->shadows as $sh) { // outside the box, under it (mygo's Shadow)
            if (!$sh[6]) { self::boxShadow($list, $box, $radii, $sh); }
        }
        if ($n->material !== null) {
            $n->material->paintMaterial(new self($list, $text, $t, $rt), $box, $n->radius);
        } elseif ($n->bordered && $n->color !== null) { // a bordered face: a plain button, a select, a field's box; its ring over the face, which shows through neither when it is dimmed (a disabled button's border stays a ring, as mygo's)
            $list->rect($n->x, $n->y, $n->w, $n->h, $n->color, 1.0, $radius);
            if ($n->borderGradient === null) { $list->ring($n->x, $n->y, $n->w, $n->h, $n->borderColor ?? $t->border, 1.0, $radius, $n->borderWidth); }
        } elseif ($n->gradient !== null) {
            [$paint, $c1, $a1, $c2, $a2, $line, $stops] = self::gradientPaint($n->gradient, $n->w, $n->h, $n->alpha);
            $list->box($n->x, $n->y, $n->w, $n->h, $radii, $c1, $a1, paint: $paint, rgb2: $c2, alpha2: $a2, g: $line, stops: $stops);
        } elseif ($n->stripes !== null) { // over the color, if any
            [$sc, $sa, $sw, $sg, $angle] = $n->stripes;
            $a = deg2rad($angle);
            $list->box($n->x, $n->y, $n->w, $n->h, $radii, $sc, $sa * $n->alpha, paint: DisplayList::PAINT_STRIPES, rgb2: $n->color ?? 0, alpha2: $n->color === null ? 0.0 : $n->alpha * $n->bgAlpha, g: [cos($a), sin($a), max($sw, 0.25), max($sw, 0.25) + max($sg, 0.0)]);
        } elseif ($n->color !== null) {
            if ($n->radii !== null) {
                $list->box($n->x, $n->y, $n->w, $n->h, $radii, $n->color, $n->alpha * $n->bgAlpha);
            } else {
                $list->rect($n->x, $n->y, $n->w, $n->h, $n->color, $n->alpha * $n->bgAlpha, $radius);
            }
        }
        if ($n->bordered && $n->color === null && $n->material === null && $n->borderGradient === null) { // a border without a face (mygo's Border on a box with no background): its ring, or dashed
            if ($n->borderStyle === BorderStyle::Dashed) {
                $bw = $n->borderWidth;
                $list->box($n->x, $n->y, $n->w, $n->h, $radii, 0, 0.0, [$bw, $bw, $bw, $bw], $n->borderColor ?? $t->border, $n->alpha, true);
            } else {
                $list->ring($n->x, $n->y, $n->w, $n->h, $n->borderColor ?? $t->border, $n->alpha, $radius, $n->borderWidth);
            }
        }
                foreach ($n->shadows as $sh) { // inside the padding edge, over the background (mygo's InsetShadow)
            if ($sh[6]) { self::insetShadow($list, $box, $radii, $n->borders, $sh); }
        }
        [$bt, $br, $bb, $bl] = $n->borders;
        if ($n->borderGradient !== null) { // a gradient paints it, over the background (mygo's border): border()'s, else borderWidth()'s
            $bw = $n->bordered ? [$n->borderWidth, $n->borderWidth, $n->borderWidth, $n->borderWidth] : $n->borders;
            if (max($bw) > 0 && $n->material === null) {
                [$paint, , , , , $line, $stops] = self::gradientPaint($n->borderGradient, $n->w, $n->h, 1.0, true);
                $list->box($n->x, $n->y, $n->w, $n->h, $radii, 0, 0.0, $bw, 0xffffff, $n->alpha, $n->borderStyle === BorderStyle::Dashed, paint: $paint, g: $line, stops: $stops, borderPaint: true);
            }
        } elseif (($bt > 0 || $br > 0 || $bb > 0 || $bl > 0) && ($n->borderStyle === BorderStyle::Dashed || $n->radii !== null)) { // dashed, or around corners each its own
            $list->box($n->x, $n->y, $n->w, $n->h, $radii, 0, 0.0, $n->borders, $n->borderColor ?? $t->border, $n->alpha, $n->borderStyle === BorderStyle::Dashed);
        } elseif ($bt > 0 || $br > 0 || $bb > 0 || $bl > 0) { // borders inside the edges (mygo's BorderWidth): around the corners where they are one width, else a side each
            $bc = $n->borderColor ?? $t->border;
            if ($bt === $br && $br === $bb && $bb === $bl) {
                $list->ring($n->x, $n->y, $n->w, $n->h, $bc, $n->alpha, $n->radius, $bt);
            } else {
                if ($bt > 0) { $list->rect($n->x, $n->y, $n->w, $bt, $bc, $n->alpha); }
                if ($bb > 0) { $list->rect($n->x, $n->y + $n->h - $bb, $n->w, $bb, $bc, $n->alpha); }
                if ($bl > 0) { $list->rect($n->x, $n->y + $bt, $bl, $n->h - $bt - $bb, $bc, $n->alpha); }
                if ($br > 0) { $list->rect($n->x + $n->w - $br, $n->y + $bt, $br, $n->h - $bt - $bb, $bc, $n->alpha); }
            }
        }
        if (($n->webView !== null || $n->movie !== null || $n->systemMaterial !== null) && $rt !== null) {
            self::nativeHole($n, $list, $text, $radii, $rt);
        }
        if ($n->selected) {
            if ($n->radii !== null) { $list->box($n->x, $n->y, $n->w, $n->h, $radii, $t->selection, $t->selectionAlpha); } else { $list->rect($n->x, $n->y, $n->w, $n->h, $t->selection, $t->selectionAlpha, $n->radius); } // corners each its own: rows chosen together, one block
        }
        if ($n->kind === Node::RICH) {
            self::paintRich($n, $list, $text, $t, $rt); // over its box (a background, a border, a radius), the text inside it with it
            return;
        }
        if ($n->text !== null) {
            [$x, $y] = $n->kind === Node::TEXT ? $n->textOrigin() : [$n->x, $n->y]; // a text inside its padding
            $room = $n->kind === Node::TEXT ? $n->w - $n->padL() - $n->padR() : $n->w;
            if ($n->kind === Node::BUTTON) { // the label centered in the padding, a menu button's chevron after it
                [$tw, $th] = $text->measure($n->text, $n->fontSize, $n->style);
                $room = $tw; // its underline and strikethrough under the label
                $cw = $n->chevron ? $t->space(2.5) + $t->space(1.5) : 0;
                $x += ($n->w - $tw - $cw) / 2; $y += ($n->h - $th) / 2;
                if ($n->chevron) {
                    self::chevron($list, $x + $tw + $t->space(1.5), $n->y + ($n->h - $t->space(2.5)) / 2, $t->space(2.5), $t->textMuted);
                }
            }
            $shown = $n->text;
            if ($n->single && $n->kind === Node::TEXT) {
                [$tw] = $text->measure($shown, $n->fontSize, $n->style);
                if ($tw > $room + 0.5) { // cut to the room, ending in an ellipsis
                    [$ew] = $text->measure('…', $n->fontSize, $n->style);
                    $shown = substr($shown, 0, $text->indexForX($shown, $n->fontSize, max(0.0, $room - $ew))) . '…';
                }
            }
            if ($n->kind === Node::TEXT && $n->rtl && !in_array($n->textStyle()->align ?? Node::START, [Node::CENTER, Node::END], true)) {
                [$tw] = $text->measure($shown, $n->fontSize, $n->style);
                $x += max(0.0, $room - $tw); // right to left, a line starts at the right of its box
            }
            if ($n->content === null || $rt === null || $n->kind !== Node::TEXT || !self::contentText($n, $list, $text, $shown, $x, $y, $rt)) {
                $text->paint($list, $shown, $n->fontSize, $x, $y, $n->fg, $n->alpha, $n->style);
            }
            if ($n->underline) {
                $list->rect($x, $y + $text->ascent($n->fontSize, $n->style) + 1, $room, 1.0, $n->fg);
            }
            if ($n->strike) {
                $list->rect($x, $y + $text->ascent($n->fontSize, $n->style) * 0.65, $room, 1.0, $n->fg);
            }
        }
        if ($n->draw !== null) {
            if ($n->content === null || $rt === null || $n->svg === null || !self::contentIcon($n, $list, $text, $t, $rt)) {
                self::callDraw($n, false, $list, $t, $text, $rt); // over the node's own face, under its children, with the text painter for what needs the atlas
            }
        }
        if ($n->scroll !== null) {
            $list->pushClip($n->x, $n->y, $n->w, $n->h);
        } elseif ($n->clip !== 0) { // an axis not clipped reaches as far as the clip around it does
            $list->pushClip(($n->clip & Node::CLIP_X) !== 0 ? $n->x : -1e6, ($n->clip & Node::CLIP_Y) !== 0 ? $n->y : -1e6, ($n->clip & Node::CLIP_X) !== 0 ? $n->w : 2e6, ($n->clip & Node::CLIP_Y) !== 0 ? $n->h : 2e6);
        }
        foreach ($n->ghosts as $g) { // children leaving go under the others
            self::paint($g, $list, $text, $t, $rt);
        }
        foreach ($n->paintOrder() as $k) {
            self::paint($k, $list, $text, $t, $rt);
        }
        if ($n->dividers !== null && $n->gridCols === null) {
            self::dividerLines($n, $list);
        }
        if ($n->drawOver !== null) {
            self::callDraw($n, true, $list, $t, $text, $rt); // over the children, inside a scrolling container's clip (mygo's DrawOver)
        }
        if ($n->clip !== 0 && $n->scroll === null) {
            $list->popClip();
        }
        if ($n->debug) {
            self::debugOutlines($n, $list);
        }
        if ($n->focusVisible && $n->color === null) { // a list or other background-less node: the ring just inside its edge, over its children (outside, a list filling the window would lose it)
            $list->ring($n->x + 1, $n->y + 1, $n->w - 2, $n->h - 2, $t->focus, $t->focusAlpha, max(0.0, $n->radius - 1), 2.0);
        }
        if ($n->scroll !== null) {
            $list->popClip();
            // The thumbs show while the pointer is over the container or drags one.
            if ($rt !== null && (($dragging = $rt->dragging($n->scroll)) || $rt->hovered($n->id))) {
                $r = Scrollbar::track([$n->x, $n->y, $n->w, $n->h], $n->barInsets);
                $grow = $dragging ? 2.0 : 0.0; // the thumb being dragged thickens, as mygo's
                $thumb = $n->scrollY ? Scrollbar::thumb($r, $n->scroll->extent(), $t->scrollbarWidth, false, $n->rtl) : null;
                if ($thumb !== null) {
                    $list->rect($thumb[0] - $grow, $thumb[1], $thumb[2] + $grow, $thumb[3], $t->scrollbar, $t->scrollbarAlpha, ($thumb[2] + $grow) / 2);
                }
                $thumb = $n->scrollX ? Scrollbar::thumb($r, $n->scroll->extentX(), $t->scrollbarWidth, true, $n->rtl) : null;
                if ($thumb !== null) {
                    $list->rect($thumb[0], $thumb[1] - $grow, $thumb[2], $thumb[3] + $grow, $t->scrollbar, $t->scrollbarAlpha, ($thumb[3] + $grow) / 2);
                }
            }
        }
    }

    /**
     * macOS's scroll edge over content under a bar: soft, the background
     * fading from 85% at the bar's edge to nothing; hard, the content
     * blurred by 6.4 points and saturated under 82% of the background
     * (toned as AppKit's), with a hairline along the other edge.
     */
    /**
     * How box() paints $g across a box $w by $h, its colors' alphas times
     * $alpha: [paint, rgb, alpha, rgb2, alpha2, g, stops] (mygo's
     * Painter.gradient, conic and radial). A gradient of stops, a radial's
     * and a conic's, paints through them; $asStops paints a linear one of
     * two colors so too, as a border's gradient does.
     * @return array{0: int, 1: int, 2: float, 3: int, 4: float, 5: array{0: float, 1: float, 2: float, 3: float}, 6: list<array{0: int, 1: float, 2: float}>}
     */
    public static function gradientPaint(LinearGradient|RadialGradient|ConicGradient $g, float $w, float $h, float $alpha, bool $asStops = false): array
    {
        $stops = array_map(static fn(GradientStop $s): array => [$s->color, $s->alpha * $alpha, $s->at], $g->stops);
        if ($g instanceof ConicGradient) {
            return [$g->oklab ? DisplayList::PAINT_CONIC_OKLAB : DisplayList::PAINT_CONIC, 0, 0.0, 0, 0.0, [$w * $g->centerX, $h * $g->centerY, deg2rad($g->angle), 0.0], $stops];
        }
        if ($g instanceof RadialGradient) {
            $stops = $stops !== [] ? $stops : [[$g->from, $g->fromAlpha * $alpha, 0.0], [$g->to, $g->toAlpha * $alpha, 1.0]];
            return [$g->oklab ? DisplayList::PAINT_RADIAL_OKLAB : DisplayList::PAINT_RADIAL, 0, 0.0, 0, 0.0, self::radialCircle($w, $h, $g), $stops];
        }
        if ($stops === [] && !$asStops) {
            return [$g->oklab ? DisplayList::PAINT_OKLAB : DisplayList::PAINT_LINEAR, $g->from, $g->fromAlpha * $alpha, $g->to, $g->toAlpha * $alpha, self::gradientLine($w, $h, $g), []];
        }
        if ($stops === []) { // its two colors as stops, at its start and end
            [$start, $end] = $g->start == 0 && $g->end == 0 ? [0.0, 1.0] : [$g->start, $g->end];
            $stops = [[$g->from, $g->fromAlpha * $alpha, $start], [$g->to, $g->toAlpha * $alpha, $end]];
        }
        // The stops' places along the whole line, through the middle as long as the box's corners reach along it.
        $a = deg2rad($g->angle);
        $dx = sin($a); $dy = -cos($a);
        $half = (abs($w * $dx) + abs($h * $dy)) / 2;
        [$x0, $y0] = [$w / 2 - $dx * $half, $h / 2 - $dy * $half];
        $line = $half < 0.25 ? [$x0, $y0, $x0 + $dx * 0.5, $y0 + $dy * 0.5] : [$x0, $y0, $w / 2 + $dx * $half, $h / 2 + $dy * $half];
        return [$g->oklab ? DisplayList::PAINT_LINEAR_STOPS_OKLAB : DisplayList::PAINT_LINEAR_STOPS, 0, 0.0, 0, 0.0, $line, $stops];
    }

    /** The line a gradient runs along across a box $w by $h, from its top left, as CSS's (mygo's Painter.gradient): through the middle at its angle, as long as the box's corners reach along it, cut at its start and end. @return array{0: float, 1: float, 2: float, 3: float} */
    public static function gradientLine(float $w, float $h, LinearGradient $g): array
    {
        $a = deg2rad($g->angle);
        $dx = sin($a); $dy = -cos($a); // 0° points up, 90° right
        $half = (abs($w * $dx) + abs($h * $dy)) / 2;
        [$x0, $y0, $x1, $y1] = [$w / 2 - $dx * $half, $h / 2 - $dy * $half, $w / 2 + $dx * $half, $h / 2 + $dy * $half];
        [$start, $end] = $g->start == 0 && $g->end == 0 ? [0.0, 1.0] : [$g->start, $g->end];
        $sx = $x0 + ($x1 - $x0) * $start; $sy = $y0 + ($y1 - $y0) * $start;
        if ($end - $start < 0.25 / max(2 * $half, 1)) { // stops at one place: a hard edge
            return [$sx, $sy, $sx + $dx * 0.25, $sy + $dy * 0.25];
        }
        return [$sx, $sy, $x0 + ($x1 - $x0) * $end, $y0 + ($y1 - $y0) * $end];
    }

    /** The circle a radial gradient spreads to in a box $w by $h, from its top left (mygo's Painter.radial): its center and radius, the box's farthest corner without one, a quarter of a point at least (in an empty box, a hard edge). @return array{0: float, 1: float, 2: float, 3: float} */
    public static function radialCircle(float $w, float $h, RadialGradient $g): array
    {
        $cx = $w * $g->centerX; $cy = $h * $g->centerY;
        $r = $g->radius > 0 ? $g->radius : hypot(max(abs($cx), abs($w - $cx)), max(abs($cy), abs($h - $cy)));
        return [$cx, $cy, max($r, 0.25), 0.0];
    }

    /** The box shadow $sh (Node::$shadows) of $box, rounded by $radii, outside it, as CSS's box-shadow (mygo's Painter.shadow). @param array{0: float, 1: float, 2: float, 3: float} $box @param array{0: float, 1: float, 2: float, 3: float} $radii */
    public static function boxShadow(DisplayList $list, array $box, array $radii, array $sh): void
    {
        [$x, $y, $blur, $spread, $rgb, $alpha] = $sh;
        $grown = array_map(static fn(float $r): float => $r > 0 ? max($r + $spread, 0.0) : 0.0, $radii);
        $sigma = $blur / 2 < 0.25 ? 0.0 : $blur / 2;
        $list->boxShadow($box[0] + $x - $spread, $box[1] + $y - $spread, $box[2] + 2 * $spread, $box[3] + 2 * $spread, $grown, $rgb, $alpha, $sigma, false, $box, $radii);
    }

    /** The inner shadow $sh of $box, rounded by $radii, inside its padding edge within the border $bw, as CSS's box-shadow: inset (mygo's Painter.insetShadow). @param array{0: float, 1: float, 2: float, 3: float} $box @param array{0: float, 1: float, 2: float, 3: float} $radii @param array{0: float, 1: float, 2: float, 3: float} $bw */
    public static function insetShadow(DisplayList $list, array $box, array $radii, array $bw, array $sh): void
    {
        [$x, $y, $blur, $spread, $rgb, $alpha] = $sh;
        $radii = DisplayList::fitRadii($box[2], $box[3], $radii);
        $pad = [$box[0] + $bw[3], $box[1] + $bw[0], $box[2] - $bw[1] - $bw[3], $box[3] - $bw[0] - $bw[2]];
        if ($pad[2] <= 0 || $pad[3] <= 0) {
            return;
        }
        $pr = [];
        foreach ([[0, 3], [0, 1], [2, 1], [2, 3]] as $i => [$a, $b]) { $pr[] = max($radii[$i] - max($bw[$a], $bw[$b]), 0.0); }
        $pr = DisplayList::fitRadii($pad[2], $pad[3], $pr);
        $hole = [$pad[0] + $x + $spread, $pad[1] + $y + $spread, max($pad[2] - 2 * $spread, 0.0), max($pad[3] - 2 * $spread, 0.0)];
        $hr = array_map(static fn(float $r): float => $r > 0 ? max($r - $spread, 0.0) : 0.0, $pr);
        $sigma = $blur / 2 < 0.25 ? 0.0 : $blur / 2;
        $list->boxShadow($pad[0], $pad[1], $pad[2], $pad[3], $pr, $rgb, $alpha, $sigma, true, $hole, $hr);
    }

    /** The lines between the children of $n (Node::dividers), in the middle of the room between each two: below each row of a list but its last, else between children next to each other, on the same line (mygo's Painter.dividers). */
    private static function dividerLines(Node $n, DisplayList $list): void
    {
        [$width, $rgb, $alpha] = $n->dividers;
        $ls = $n->scroll instanceof ListState ? $n->scroll : null;
        $row = $n->dir === Node::ROW && $ls === null;
        [$lo, $hi] = $row ? [$n->y + $n->borders[0], $n->y + $n->h - $n->borders[2]] : [$n->x + $n->borders[3], $n->x + $n->w - $n->borders[1]];
        $line = static function (float $at, float $from, float $to) use ($list, $row, $width, $rgb, $alpha): void { // vertical in a row, horizontal in a column
            if ($row) { $list->rect($at - $width / 2, $from, $width, $to - $from, $rgb, $alpha); } else { $list->rect($from, $at - $width / 2, $to - $from, $width, $rgb, $alpha); }
        };
        if ($ls !== null) {
            foreach ($n->rowStarts as $k => $i) {
                $c = $n->kids[$k];
                if ($i < $ls->count - 1) { $line($c->y + $c->h + max($n->gapY ?? $n->gap, 0) / 2, min($lo, $c->x), max($hi, $c->x + $c->w)); }
            }
            return;
        }
        $prev = null;
        foreach ($n->kids as $c) {
            if ($c->absolute) {
                continue;
            }
            if ($prev !== null) {
                [$as, $ae, $cs, $ce] = $row ? [$prev->x, $prev->x + $prev->w, $c->x, $c->x + $c->w] : [$prev->y, $prev->y + $prev->h, $c->y, $c->y + $c->h];
                [$a0, $a1, $c0, $c1] = $row ? [$prev->y, $prev->y + $prev->h, $c->y, $c->y + $c->h] : [$prev->x, $prev->x + $prev->w, $c->x, $c->x + $c->w];
                if (!$n->wraps || $a0 < $c1 && $c0 < $a1) { // on the same line of a row that wraps
                    $line($cs < $as ? ($ce + $as) / 2 : ($ae + $cs) / 2, min($lo, $a0, $c0), max($hi, $a1, $c1)); // reversed: the other way
                }
            }
            $prev = $c;
        }
    }

    /** Outlines $n and the nodes inside it (Node::debug): their margins in orange, padding in green, boxes in blue, at full opacity (mygo's Painter.debug); a node debugging itself outlines its own. */
    private static function debugOutlines(Node $n, DisplayList $list): void
    {
        $saved = $list->opacity();
        $list->setOpacity(1.0);
        $walk = static function (Node $e, bool $top) use (&$walk, $list): void {
            if ($e->invisible || ($e->debug && !$top)) {
                return;
            }
            [$mt, $mr, $mb, $ml] = array_map(static fn(float $m): float => is_finite($m) ? $m : 0.0, $e->margins);
            if ($mt + $mb > 0 || $ml + $mr > 0) {
                $list->ring($e->x - $ml, $e->y - $mt, $e->w + $ml + $mr, $e->h + $mt + $mb, 0xf97316, 0.8, 0.0, 1.0);
            }
            [$pt, $pr, $pb, $pl] = $e->sides();
            if ($pt + $pb > 0 || $pl + $pr > 0) {
                $list->ring($e->x + $e->padL(), $e->y + $e->padT(), $e->w - $e->padL() - $e->padR(), $e->h - $e->padT() - $e->padB(), 0x22c55e, 0.8, 0.0, 1.0);
            }
            $list->ring($e->x, $e->y, $e->w, $e->h, 0x3b82f6, 0.9, 0.0, 1.0);
            foreach ($e->kids as $k) { $walk($k, false); }
        };
        $walk($n, true);
        $list->setOpacity($saved);
    }

    /** Paints a copy of the node dragging its value under the pointer, above the rest, at three quarters' opacity (mygo's paintDrag). */
    public static function dragCopy(DisplayList $list, TextPainter $text, Theme $t, Runtime $rt): void
    {
        $d = $rt->valueDrag;
        if ($d === null || $d['canceled'] || $d['native'] || ($e = $d['elem']) === null) { // the system shows a native drag
            return;
        }
        [$px, $py] = $e->world->point($e->x + $d['offX'], $e->y + $d['offY']);
        $shift = Affine::translation($rt->input->mouseX - $px, $rt->input->mouseY - $py);
        $saved = [];
        $move = function (Node $n) use (&$move, &$saved, $shift) {
            $saved[] = [$n, $n->world];
            $n->world = $shift->mul($n->world);
            foreach ($n->kids as $k) { $move($k); }
        };
        $move($e);
        $opacity = $list->opacity();
        $list->setOpacity(0.75);
        self::paint($e, $list, $text, $t, $rt);
        $list->setOpacity($opacity);
        foreach ($saved as [$n, $w]) { $n->world = $w; }
    }

    /** The tip that shows (Runtime::$tip), if any: a small surface above its node, centered, or below it where there is no room above, kept inside the root. */
    public static function tooltip(Node $root, DisplayList $list, TextPainter $text, Theme $t, Runtime $rt): void
    {
        if ($rt->tip === null || $rt->tip[0] === '') { // a tip of its own (Context::tooltipBase): the view built it
            return;
        }
        [$tip, $ax, $ay, $aw, $ah] = $rt->tip; // the anchor's rect
        $size = $t->fontSize - 1;
        [$tw, $th] = $text->measure($tip, $size);
        $px = $t->space(2); $py = $t->space(1.25); $radius = $t->space(1.25); $apart = $t->space(1.5);
        $w = min($tw + 2 * $px, $t->space(80)); $h = $th + 2 * $py;
        $x = max($root->x, min($ax + ($aw - $w) / 2, $root->x + $root->w - $w)); // centered over the anchor, kept in the window
        $y = $ay - $apart - $h; // above it; below when there is no room
        if ($y < $root->y) {
            $y = $ay + $ah + $apart;
        }
        $list->shadow($x, $y, $w, $h, 0x000000, 0.2, $radius, 8, 0, 2);
        [$fill, $ink] = $t->inverse();
        $list->rect($x, $y, $w, $h, $fill, 1.0, $radius);
        $list->pushClip($x, $y, $w, $h);
        $text->paint($list, $tip, $size, $x + $px, $y + $py, $ink);
        $list->popClip();
    }

    /** Draws the shapes of $svg fitted in the rect (contain, centered) in $rgb, turned by $rotate degrees around its center, as mygo's Painter.Icon: from a mask the text system caches in its atlas, so the GPU draws the icon in any color without drawing its shapes again. */
    public static function paintIcon(DisplayList $list, TextPainter $text, SVG $svg, float $x, float $y, float $w, float $h, int $rgb, float $alpha = 1.0, float $rotate = 0.0): void
    {
        if ($svg->w <= 0 || $svg->h <= 0 || $alpha <= 0) { return; }
        $k = min($w / $svg->w, $h / $svg->h);
        $q = $text->iconQuad($svg, $x + ($w - $svg->w * $k) / 2, $y + ($h - $svg->h * $k) / 2, $svg->w * $k, $svg->h * $k, $rotate);
        if ($q !== null && !$list->clipped($q[0], $q[1], $q[2], $q[3])) { $list->glyph($q[0], $q[1], $q[2], $q[3], $rgb, $alpha, $q[4], $q[5]); }
    }

    /** Draws an image, or an SVG in its own colors, in the node's box as its fit says (mygo's Painter.image: fitIn, drawBitmap, drawSVG), rounded, faded, gray if asked. */
    private static function paintImage(Node $n, DisplayList $list, TextPainter $text, Theme $t): void
    {
        self::picture($list, $text, $n->svg ?? $n->image, $n->x, $n->y, $n->w, $n->h, $n->fit, $n->fg !== 0 ? $n->fg : $t->text, $n->alpha, $n->radius, $n->gray);
    }

    /**
     * Draws a bitmap or an SVG in the box ($x, $y, $w, $h) as $fit says
     * (Node::CONTAIN…), an SVG's current color $rgb, as an image node draws
     * its picture (mygo's Painter.Image).
     *
     * @internal
     */
    public static function picture(DisplayList $list, TextPainter $text, Image|SVG $pic, float $x, float $y, float $w, float $h, int $fit, int $rgb, float $alpha = 1.0, float $radius = 0.0, bool $gray = false): void
    {
        $scale = $text->scale();
        // The picture's own size in points: a bitmap's pixels as the image laid it out, an SVG's size.
        [$iw, $ih] = $pic instanceof SVG ? [$pic->w, $pic->h] : [$pic->w / $scale, $pic->h / $scale];
        if ($iw <= 0 || $ih <= 0) { return; }
        [$dst, $frac, $fw, $fh] = self::fitIn($x, $y, $w, $h, $iw, $ih, $fit);
        if ($dst[2] <= 0 || $dst[3] <= 0) { return; }
        $pixelated = false;
        if ($pic instanceof SVG) {
            $s = $text->svgSlot($pic, max(1, (int)round($fw * $scale)), max(1, (int)round($fh * $scale)), $rgb);
        } else { // far smaller than its pixels, a level of it, as smooth (mygo's drawBitmap)
            $shrink = min($frac[2] * $pic->w / ($dst[2] * $scale), $frac[3] * $pic->h / ($dst[3] * $scale));
            $pixelated = $pic->pixelated && $shrink <= 1;
            if ($pic->canvas?->loose()) {
                self::looseImage($list, $text, $pic->canvas, $dst, [$frac[0] * $pic->w, $frac[1] * $pic->h, $frac[2] * $pic->w, $frac[3] * $pic->h], $alpha, $radius, $gray, $pixelated);
                return;
            }
            $s = self::slot($text, $pic->smaller($shrink));
        }
        $sw = $s['w']; $sh = $s['h']; // the part of the slot that shows: the fractions of the picture within the box
        $u = $s['u'] + (int)round($frac[0] * $sw); $v = $s['v'] + (int)round($frac[1] * $sh);
        $cw = max(1, (int)round($frac[2] * $sw)); $ch = max(1, (int)round($frac[3] * $sh));
        $list->image($dst[0], $dst[1], $dst[2], $dst[3], $u, $v, $cw, $ch, $s['page'], $alpha, $radius, $gray, $pixelated);
    }

    /**
     * Where a picture $w × $h points goes in the box as $fit says, and which
     * part of it shows there, as fractions of its size: [the rect shown,
     * [x, y, w, h] fractions, the picture's full width and height], mygo's fitIn.
     */
    public static function fitIn(float $bx, float $by, float $bw, float $bh, float $w, float $h, int $fit): array
    {
        if ($fit === Node::FILL_BOX) { return [[$bx, $by, $bw, $bh], [0.0, 0.0, 1.0, 1.0], $bw, $bh]; }
        $k = match ($fit) { Node::CONTAIN => min($bw / $w, $bh / $h), Node::COVER => max($bw / $w, $bh / $h), Node::SCALE_DOWN => min($bw / $w, $bh / $h, 1.0), default => 1.0 };
        $dw = $w * $k; $dh = $h * $k;
        $dx = $bx + ($bw - $dw) / 2; $dy = $by + ($bh - $dh) / 2;
        // Only what falls in the box shows.
        $x0 = max($dx, $bx); $y0 = max($dy, $by); $x1 = min($dx + $dw, $bx + $bw); $y1 = min($dy + $dh, $by + $bh);
        if ($x1 <= $x0 || $y1 <= $y0) { return [[$x0, $y0, 0.0, 0.0], [0.0, 0.0, 0.0, 0.0], $dw, $dh]; }
        return [[$x0, $y0, $x1 - $x0, $y1 - $y0], [($x0 - $dx) / $dw, ($y0 - $dy) / $dh, ($x1 - $x0) / $dw, ($y1 - $y0) / $dh], $dw, $dh];
    }

    /** mygo's inputBorder: the accent while focused, the border a quarter toward the text under the pointer, the border otherwise. */
    public static function inputBorder(Node $n, Theme $t, ?Runtime $rt): int
    {
        return $n->focused ? $t->accent : (($rt?->hovered($n->id) ?? false) ? Theme::mix($t->border, $t->text, 0.25) : $t->border);
    }

    /** mygo's chevron: a down-pointing angle in a $d-square box at (x, y), stroked 1.5. */
    public static function chevron(DisplayList $list, float $x, float $y, float $d, int $color): void
    {
        $list->line($x + $d * 0.1, $y + $d * 0.3, $x + $d * 0.5, $y + $d * 0.7, $color, 1.0, 1.5);
        $list->line($x + $d * 0.5, $y + $d * 0.7, $x + $d * 0.9, $y + $d * 0.3, $color, 1.0, 1.5);
    }

    /** mygo's Painter.FocusRing: a 2-point ring of the focus color a point outside the rect. */
    public static function paintFocusRing(DisplayList $list, float $x, float $y, float $w, float $h, float $radius, Theme $t): void
    {
        $list->ring($x - 3, $y - 3, $w + 6, $h + 6, $t->focus, $t->focusAlpha, $radius + 3, 2.0);
    }

    /**
     * A checkbox or radio with its label, as mygo's: a Space(4) box
     * (radius Space(1)) or disc, the background with a border a quarter
     * toward the text — the accent under the pointer — or the accent
     * with a check stroked Space(0.5) in the accent's text (a dot Space(1.5)
     * for a radio); the label Space(2) after.
     */
    private static function paintToggle(Node $n, DisplayList $list, TextPainter $text, Theme $t, ?Runtime $rt): void
    {
        $box = $t->space(4);
        $x = $n->reverse !== $n->rtl ? $n->x + $n->w - $box : $n->x; $y = $n->y + ($n->h - $box) / 2; // reversed, or right to left: the box at the right
        $radius = $n->kind === Node::RADIO ? $t->space(2) : $t->space(1);
        $hovered = $rt?->hovered($n->id) ?? false;
        if ($n->on || $n->mixed) {
            $list->rect($x, $y, $box, $box, $t->accent, 1.0, $radius);
            if ($n->mixed) { // a bar: some of a group checked
                $list->rect($x + $box * 0.25, $y + $box / 2 - 1, $box * 0.5, 2, $t->accentText, 1.0, 1.0);
            } elseif ($n->kind === Node::RADIO) {
                $d = $t->space(1.5);
                $list->rect($x + ($box - $d) / 2, $y + ($box - $d) / 2, $d, $d, $t->accentText, 1.0, $d / 2);
            } else { // mygo's checkPath
                $w = $t->space(0.5);
                $list->line($x + $box * 0.22, $y + $box * 0.52, $x + $box * 0.42, $y + $box * 0.71, $t->accentText, 1.0, $w);
                $list->line($x + $box * 0.42, $y + $box * 0.71, $x + $box * 0.78, $y + $box * 0.31, $t->accentText, 1.0, $w);
            }
        } else {
            $list->rect($x, $y, $box, $box, $hovered ? $t->accent : Theme::mix($t->border, $t->text, 0.25), 1.0, $radius);
            $list->rect($x + 1, $y + 1, $box - 2, $box - 2, $t->background, 1.0, $radius - 1);
        }
        if ($n->focusVisible) {
            self::paintFocusRing($list, $x, $y, $box, $box, $radius, $t);
        }
        [$lw, $lh] = $text->measure($n->text, $n->fontSize);
        $text->paint($list, $n->text, $n->fontSize, $n->reverse !== $n->rtl ? $x - $t->space(2) - $lw : $x + $box + $t->space(2), $n->y + ($n->h - $lh) / 2, $n->fg);
    }

    /**
     * A spinner, as mygo's: twelve spokes, a twelfth of a turn on every
     * twelfth of 0.9 s, fading behind the one leading. A spoke is a run
     * of overlapping dots, the list having no rotated quads.
     */
    private static function paintSpinner(Node $n, DisplayList $list, Theme $t, ?Runtime $rt): void
    {
        $spokes = 12; $turn = 0.9;
        $phase = fmod(($rt?->now ?? hrtime(true)) / 1e9, $turn) / $turn * $spokes;
        $lead = (int)$phase;
        $rt?->after(($lead + 1 - $phase) * $turn / $spokes); // painted again for its next spoke, not every frame (mygo's spinner)
        $cx = $n->x + $n->w / 2; $cy = $n->y + $n->h / 2;
        $in = $n->w * 0.22; $out = $n->w * 0.46; $w = max(1.5, $n->w * 0.09);
        $dots = 4;
        $color = $n->ts?->color ?? $t->textMuted; $own = $n->ts?->color !== null ? ($n->ts->alpha ?? 1.0) : 1.0; // the muted text color, or its own text color (mygo's TextColor), as on a chosen row
        for ($i = 0; $i < $spokes; $i++) {
            $a = $i / $spokes * 2 * M_PI - M_PI / 2;
            $age = ($lead - $i + $spokes) % $spokes;
            $alpha = 1 - $age / $spokes * 0.85;
            for ($d = 0; $d < $dots; $d++) {
                $r = $in + ($out - $in) * $d / ($dots - 1);
                $list->rect($cx + cos($a) * $r - $w / 2, $cy + sin($a) * $r - $w / 2, $w, $w, $color, $alpha * $own, $w / 2);
            }
        }
    }

    /**
     * A switch, as mygo's: Space(9) by Space(5), radius Space(2.5), the
     * border a sixth toward the text when off and the accent when on,
     * mixed as the knob moves ($fraction, from 0 off to 1 on); the knob
     * white, inset Space(0.5), on a shadow (0, 1, 3, 0.25); the label
     * Space(2) after.
     */
    private static function paintSwitch(Node $n, DisplayList $list, TextPainter $text, Theme $t, ?Runtime $rt): void
    {
        $tw = $t->space(9); $th = $t->space(5); $in = $t->space(0.5); $d = $th - 2 * $in;
        $y = $n->y + ($n->h - $th) / 2;
        $sx = $n->rtl ? $n->x + $n->w - $tw : $n->x; // right to left: the switch at the right, on toward the left
        $list->rect($sx, $y, $tw, $th, Theme::mix(Theme::mix($t->border, $t->text, 0.15), $t->accent, $n->fraction), 1.0, $th / 2);
        $kx = $n->rtl ? $sx + $tw - $in - $d - $n->fraction * ($tw - $th) : $sx + $in + $n->fraction * ($tw - $th);
        $list->shadow($kx, $y + $in, $d, $d, 0x000000, 0.25, $d / 2, 3, 0, 1);
        $list->rect($kx, $y + $in, $d, $d, 0xffffff, 1.0, $d / 2);
        if ($n->focusVisible) {
            self::paintFocusRing($list, $sx, $y, $tw, $th, $th / 2, $t);
        }
        [$lw, $lh] = $text->measure($n->text, $n->fontSize);
        $text->paint($list, $n->text, $n->fontSize, $n->rtl ? $sx - $t->space(2) - $lw : $n->x + $tw + $t->space(2), $n->y + ($n->h - $lh) / 2, $n->fg);
    }

    /**
     * A slider: the track, the part up to the value in the accent, and the
     * knob, a capsule that sits on a soft shadow and, while held, grows
     * into a lens the track shows through, rimmed and lit inside, as
     * AppKit's does (mygo's paintKnob).
     */
    private static function paintSlider(Node $n, DisplayList $list, Theme $t): void
    {
        $kw = $t->space(5); $kh = $t->space(4);
        $trackH = $t->space(1.5); // as thick as Progress
        $cy = $n->y + $t->space(2.5);
        $x0 = $n->x + $kw / 2; $x1 = $n->x + $n->w - $kw / 2;
        $kx = $n->rtl ? $x1 - ($x1 - $x0) * $n->fraction : $x0 + ($x1 - $x0) * $n->fraction; // right to left, the value grows toward the left
        if ($n->held > 0) { // the lens lightens what is behind it, under the track
            [$x, $y, $w, $h] = self::knobRect($t, $kx, $cy, $n->held);
            $list->rect($x, $y, $w, $h, 0xffffff, 0.1 * $n->held, $h / 2);
        }
        for ($i = 0; $i < $n->ticks; $i++) { // a step slider's tick marks, under the track (mygo's paintTicks)
            $x = $x0 + ($x1 - $x0) * $i / max($n->ticks - 1, 1);
            $list->rect($x - 0.5, $cy + $kh / 2 + $t->space(1), 1.0, $t->space(1.5), $t->textMuted, 0.6);
        }
        // The track spans the slider, the knob's center its content box, as mygo's.
        $list->rect($n->x, $cy - $trackH / 2, $n->w, $trackH, $t->border, 1.0, $trackH / 2);
        $list->rect($n->rtl ? $kx : $n->x, $cy - $trackH / 2, $n->rtl ? $n->x + $n->w - $kx : $kx - $n->x, $trackH, $t->accent, 1.0, $trackH / 2);
        self::knob($list, $t, $kx, $cy, $n->held, $n->focusVisible);
    }

    /**
     * Paints the text of $n changing, $shown at ($x, $y), and reports whether
     * it does: no transition otherwise (mygo's contentText). The old fades
     * out as the new fades in; numeric, on one line and with motion, the
     * characters the old and the new share at their start and end stay,
     * the end sliding to where it goes, and those between roll.
     */
    private static function contentText(Node $n, DisplayList $list, TextPainter $text, string $shown, float $x, float $y, Runtime $rt): bool
    {
        [$r, $k, $changing] = $rt->contentStep($n, $x, $y, $n->fg);
        if (!$changing || $r->oldText === null) {
            return false;
        }
        $saved = $list->opacity();
        $old = $r->oldText; $size = $n->fontSize; $style = $n->style;
        if (!$n->content->numeric || $r->still || str_contains($old, "\n") || str_contains($shown, "\n")) {
            $list->setOpacity($saved * (1 - $k));
            $text->paint($list, $old, $size, $r->oldX, $r->oldY, $r->oldColor, $n->alpha, $style);
            $list->setOpacity($saved * $k);
            $text->paint($list, $shown, $size, $x, $y, $n->fg, $n->alpha, $style);
            $list->setOpacity($saved);
            return true;
        }
        // The characters the old and the new text share at their start and at their end stay; those between roll.
        $a = $old; $b = $shown;
        $starts = fn(string $s, int $i) => $i >= strlen($s) || (ord($s[$i]) & 0xc0) !== 0x80;
        $pre = 0;
        while ($pre < strlen($a) && $pre < strlen($b) && $a[$pre] === $b[$pre]) { $pre++; }
        while ($pre > 0 && $pre < strlen($b) && !$starts($b, $pre)) { $pre--; }
        $suf = 0;
        while ($suf < strlen($a) - $pre && $suf < strlen($b) - $pre && $a[strlen($a) - 1 - $suf] === $b[strlen($b) - 1 - $suf]) { $suf++; }
        while ($suf > 0 && !$starts($b, strlen($b) - $suf)) { $suf--; }
        $caret = fn(string $s, int $i) => $text->xForIndex($s, $size, $i, $style);
        $oldPre = $caret($a, $pre); $oldSuf = $caret($a, strlen($a) - $suf);
        $curPre = $caret($b, $pre); $curSuf = $caret($b, strlen($b) - $suf);
        [, $h] = $text->measure($b, $size, $style);
        $dir = $n->content->down ? -1.0 : 1.0;
        $far = 1e5;
        $band = function (float $x0, float $x1, \Closure $fn) use ($list, $y, $far): void {
            if ($x1 <= $x0) { return; }
            $list->pushClip($x0, $y - $far, $x1 - $x0, 2 * $far);
            $fn();
            $list->popClip();
        };
        // What stays: the start, and the end, sliding to where it goes.
        if ($pre > 0) {
            $band($x - $far, $x + $curPre, fn() => $text->paint($list, $b, $size, $x, $y, $n->fg, $n->alpha, $style));
        }
        $dx = ($oldSuf - $curSuf) * (1 - $k);
        if ($suf > 0) {
            $band($x + $curSuf + $dx, $x + $far, fn() => $text->paint($list, $b, $size, $x + $dx, $y, $n->fg, $n->alpha, $style));
        }
        // What changes: the old going, the new coming.
        $list->setOpacity($saved * (1 - $k));
        $band($x + $oldPre, $x + $oldSuf, fn() => $text->paint($list, $a, $size, $x, $y - $dir * $h * 0.6 * $k, $r->oldColor, $n->alpha, $style));
        $list->setOpacity($saved * $k);
        $band($x + $curPre, $x + $curSuf + $dx, fn() => $text->paint($list, $b, $size, $x, $y + $dir * $h * 0.6 * (1 - $k), $n->fg, $n->alpha, $style));
        $list->setOpacity($saved);
        return true;
    }

    /** Paints the icon of $n changing, and reports whether it does: the old shrinking away, the new growing in (mygo's contentIcon); without motion they only fade. */
    private static function contentIcon(Node $n, DisplayList $list, TextPainter $text, Theme $t, Runtime $rt): bool
    {
        [$r, $k, $changing] = $rt->contentStep($n, $n->x, $n->y, $n->fg);
        if (!$changing) {
            return false;
        }
        $saved = $list->opacity();
        $cx = $n->x + $n->w / 2; $cy = $n->y + $n->h / 2;
        $scaled = function (float $s, \Closure $fn) use ($list, $r, $cx, $cy): void {
            if (!$r->still && $s != 1) { $list->transform(Transform::scaling($s, $s), $cx, $cy, $fn); } else { $fn(); }
        };
        if ($r->oldDraw !== null) {
            $list->setOpacity($saved * (1 - $k));
            $scaled(1 - 0.5 * $k, fn() => ($r->oldDraw)($list, $n, $t, $text)); // the old draw closure, in the node's box
        }
        $list->setOpacity($saved * $k);
        $scaled(0.5 + 0.5 * $k, fn() => ($n->draw)($list, $n, $t, $text));
        $list->setOpacity($saved);
        return true;
    }

    /** Paints the picture of $n changing, and reports whether it does: the old fading out as the new fades in (mygo's contentImage). */
    private static function contentImage(Node $n, DisplayList $list, TextPainter $text, Theme $t, Runtime $rt): bool
    {
        [$r, $k, $changing] = $rt->contentStep($n, $n->x, $n->y, $n->fg);
        if (!$changing) {
            return false;
        }
        $saved = $list->opacity();
        if ($r->oldSvg !== null || $r->oldImage !== null) {
            [$svg, $image] = [$n->svg, $n->image];
            $n->svg = $r->oldSvg; $n->image = $r->oldImage;
            $list->setOpacity($saved * (1 - $k));
            self::paintImage($n, $list, $text, $t);
            $n->svg = $svg; $n->image = $image;
        }
        $list->setOpacity($saved * $k);
        self::paintImage($n, $list, $text, $t);
        $list->setOpacity($saved);
        return true;
    }

    /** A slider's knob centered at (x, cy), held from 0 to 1, mygo's knobRect: Space(5) by Space(4), growing by 0.35 as it is held. @return array{0: float, 1: float, 2: float, 3: float} */
    public static function knobRect(Theme $t, float $kx, float $cy, float $held): array
    {
        $g = 1 + 0.35 * $held;
        $w = $t->space(5) * $g; $h = $t->space(4) * $g;
        return [$kx - $w / 2, $cy - $h / 2, $w, $h];
    }

    /** A slider's knob at (x, cy), held from 0 to 1, mygo's paintKnob: a capsule of white, which grows into a lens of glass as it is held, as AppKit's does. */
    public static function knob(DisplayList $list, Theme $t, float $kx, float $cy, float $held, bool $focus): void
    {
        [$x, $y, $w, $h] = self::knobRect($t, $kx, $cy, $held); $r = $h / 2;
        [$face, $rim, $drop] = $t->dark ? [0xe0e1e1, 0.7, 0.3] : [0xffffff, 0.22, 0.14];
        if ($held > 0) {
            $list->shadow($x, $y, $w, $h, 0x000000, $drop * $held, $r, 14, 0, 8);
        }
        if ($held < 1) {
            $list->shadow($x, $y, $w, $h, 0x000000, 0.08 * (1 - $held), $r, 1, 0, 0.5);   // a tight shadow edges the knob,
            $list->shadow($x, $y, $w, $h, 0x000000, 0.10 * (1 - $held), $r, 7, 0, 1.5);   // a soft one lifts it
            $list->rect($x, $y, $w, $h, $face, 1 - $held, $r);
        }
        if ($held > 0) {
            $list->ring($x, $y, $w, $h, 0x000000, $rim * $held, $r, 1.0);                  // the lens's rim,
            $list->ring($x + 1, $y + 1, $w - 2, $h - 2, 0xffffff, 0.25 * $held, $r - 1, 1.0); // lit on the inside
        }
        if ($focus) {
            self::paintFocusRing($list, $x, $y, $w, $h, $r, $t);
        }
    }

    /** The color and opacity of a selection in text of style $ts: its own (selectionColor()), else the theme's (mygo's selectionColor). @return array{int, float} */
    private static function selectionColor(TextStyle $ts, Theme $t): array
    {
        return $ts->selection !== null ? [$ts->selection, $ts->selectionAlpha ?? 1.0] : [$t->selection, $t->selectionAlpha];
    }

    /**
     * Rich text: its layout from its top left, with the background,
     * underline or strikethrough of its text style, and the colors,
     * backgrounds and lines of its spans (mygo's textLayout with a
     * spanPaint); then the focus rings of the text inside it, around their
     * words, whose text it draws (paintInline).
     */
    private static function paintRich(Node $n, DisplayList $list, TextPainter $text, Theme $t, ?Runtime $rt): void
    {
        $l = $n->textLayout();
        $ts = $n->textStyle();
        [$x, $y] = $n->textOrigin();
        $under = null;
        if (($sel = $rt?->textSelectionOf($n->id)) !== null) { // over the backgrounds, under the glyphs and lines
            $under = function () use ($list, $l, $sel, $x, $y, $ts, $t): void {
                foreach ($l->selection($sel[0], $sel[1]) as [$sx, $sy, $sw, $sh]) {
                    $list->rect($x + $sx, $y + $sy, $sw, $sh, ...self::selectionColor($ts, $t));
                }
            };
        }
        $glyphs = null;
        if ($n->content !== null && $rt !== null && count($n->spans) === 1 && $n->kids === [] && count($l->lines) === 1) { // a text made rich (a background, a selectable container's) changes as a text does
            $line = $l->lines[0];
            $glyphs = fn(): bool => self::contentText($n, $list, $text, $n->spans[0]->text, $x + $line->x, $y + $line->y, $rt);
        }
        self::paintSpans($list, $text, $l, $n->spans, $x, $y, $ts->color ?? $n->fg, ($ts->alpha ?? 1.0) * $n->alpha, $n->alpha, $ts, $under, $glyphs);
        if ($n->drawObjects !== null) { // its inline objects, after the glyphs (mygo's textObjects)
            $p = null;
            foreach (TextLayout::objectBoxes($l) as [$at, $r]) {
                $r = [$x + $r[0], $y + $r[1], $r[2], $r[3]];
                if (!$list->clipped(...$r)) { ($n->drawObjects)($p ??= new self($list, $text, $t, $rt), $at, $r); }
            }
        }
        self::paintInline($n, $list, $t);
    }

    /**
     * Paints layout $l of $spans at ($x, $y), as rich text shows: the text's
     * background and the spans', what $under paints over them, the glyphs in
     * their spans' colors or $color, and the underlines and strikethroughs
     * of the spans and of $ts (mygo's Painter.TextLayout). $nodeAlpha dims
     * the spans' own colors, as a node's opacity; $glyphs, when it returns
     * true, painted the glyphs instead (a content transition's).
     *
     * @internal
     * @param list<Span> $spans
     */
    public static function paintSpans(DisplayList $list, TextPainter $text, \MyPHP\Text\Layout $l, array $spans, float $x, float $y, int $color, float $alpha, float $nodeAlpha = 1.0, ?TextStyle $ts = null, ?\Closure $under = null, ?\Closure $glyphs = null): void
    {
        $ends = []; $end = 0;
        foreach ($spans as $sp) { $end += mb_strlen($sp->text, 'UTF-8'); $ends[] = $end; }
        $at = static function (int $r) use ($ends): int { // the span holding rune $r, or -1
            $lo = 0; $hi = count($ends);
            while ($lo < $hi) {
                $m = intdiv($lo + $hi, 2);
                if ($ends[$m] <= $r) { $lo = $m + 1; } else { $hi = $m; }
            }
            return $lo === count($ends) ? -1 : $lo;
        };
        // The runs of the glyphs of a line in one span: the span's index, the glyphs [i, j), and their left and right.
        $runs = static function (\MyPHP\Text\Line $line) use ($at): array {
            $out = []; $gs = $line->glyphs; $c = count($gs);
            for ($i = 0; $i < $c;) {
                $k = $at($gs[$i]->cluster); $x0 = INF; $x1 = -INF;
                for ($j = $i; $j < $c && $at($gs[$j]->cluster) === $k; $j++) { $x0 = min($x0, $gs[$j]->x); $x1 = max($x1, $gs[$j]->x + $gs[$j]->advance); }
                $out[] = [$k, $i, $j, $x0, $x1];
                $i = $j;
            }
            return $out;
        };
        foreach ($l->lines as $line) { // the backgrounds, under the glyphs
            if ($ts?->background !== null && $line->width > 0) {
                $list->rect($x + $line->x, $y + $line->y, $line->width, $line->height, $ts->background, $alpha);
            }
            foreach ($runs($line) as [$k, $i, , $x0, $x1]) {
                if ($k < 0 || $spans[$k]->background === null || $spans[$k]->ghost) {
                    continue;
                }
                $sp = $spans[$k]; $ba = ($sp->backgroundAlpha ?? $sp->alpha ?? 1.0) * $nodeAlpha;
                if ($sp->radius > 0) { // hugging the glyphs, a little wider
                    $size = $line->glyphs[$i]->size;
                    $px = min($size * 0.22, $sp->radius);
                    $list->rect($x + $x0 - $px, $y + $line->baseline - $sp->baselineShift - $size * 0.98, $x1 - $x0 + 2 * $px, $size * 1.32, $sp->background, $ba, $sp->radius);
                } else {
                    $list->rect($x + $x0, $y + $line->y, $x1 - $x0, $line->height, $sp->background, $ba);
                }
            }
        }
        if ($under !== null) { $under(); }
        // Its shadow (its outline's, outlined) and its outline under the glyphs: the glyphs grown by the outline (Node::textOutline, textShadow).
        // Blurred (textBlur), as libass blurs: the outline where there is one, else the glyphs; the shadow what was blurred.
        // Cut out (textCutOut): the outline, or the shadow, of the glyphs grown with the glyphs themselves taken out, as libass's ass_fix_outline.
        $stroke = $ts?->outline[0] ?? 0.0;
        $blur = $ts?->blur ?? 0.0;
        [$cutOutline, $cutShadow] = $ts?->cutOut ?? [false, false];
        $ghost = static fn(int $r): bool => ($k = $at($r)) >= 0 && $spans[$k]->ghost;
        if ($ts?->textShadow !== null) {
            [$dx, $dy, $src, $sa] = $ts->textShadow;
            $text->paintLayout($list, $l, $x + $dx, $y + $dy, static fn(int $r): array => [$src, $ghost($r) ? 0.0 : $sa * $nodeAlpha], $stroke, $blur, $cutShadow && $stroke > 0);
        }
        if ($ts?->outline !== null) {
            [, $orgb, $oa] = $ts->outline;
            $text->paintLayout($list, $l, $x, $y, static fn(int $r): array => [$orgb, $ghost($r) ? 0.0 : $oa * $nodeAlpha], $stroke, $blur, $cutOutline);
        }
        if ($glyphs === null || !$glyphs()) $text->paintLayout($list, $l, $x, $y, static function (int $r) use ($at, $spans, $color, $alpha, $nodeAlpha): array {
            $k = $at($r);
            if ($k >= 0 && $spans[$k]->ghost) {
                return [$color, 0.0];
            }
            if ($k < 0 || $spans[$k]->color === null && $spans[$k]->alpha === null) {
                return [$color, $alpha];
            }
            return [$spans[$k]->color ?? $color, ($spans[$k]->alpha ?? 1.0) * $nodeAlpha];
        }, 0.0, $ts?->outline === null ? $blur : 0.0);
        $sys = $text->textSystem(); $scale = $text->scale();
        $base = [$ts?->underline ?? false, $ts?->wavy ?? false, $ts?->strike ?? false, $ts?->decoColor, $ts?->decoThick ?? 0.0];
        foreach ($l->lines as $li => $line) {
            foreach ($runs($line) as [$k, $i, $j]) {
                if ($k < 0 || $spans[$k]->ghost) {
                    continue;
                }
                $sp = $spans[$k];
                $d = [$sp->underline || $sp->wavyUnderline, $sp->wavyUnderline, $sp->strikethrough, $sp->decorationColor ?? $base[3], $sp->decorationThickness ?? $base[4]];
                if ($d[0] || $d[2]) {
                    self::decorationRun($list, $sys, $l, $li, $i, $j, $x, $y, $scale, $d, $sp->color ?? $color, ($sp->alpha ?? 1.0) * $nodeAlpha);
                }
            }
            if ($base[0] || $base[2]) {
                self::decorationRun($list, $sys, $l, $li, 0, count($line->glyphs), $x, $y, $scale, $base, $color, $alpha);
            }
        }
    }

    /**
     * The underline and strikethrough of $d (underline, wavy, strike,
     * color, thickness) along glyphs [i, j) of line $li of $l from (x, y),
     * where the fonts place them, in $d's color or $rgb (mygo's
     * decorationRun); a wavy underline follows the underline, at least a
     * pixel thick.
     * @param array{0: bool, 1: bool, 2: bool, 3: ?int, 4: float} $d
     */
    private static function decorationRun(DisplayList $list, \MyPHP\Text\System $sys, \MyPHP\Text\Layout $l, int $li, int $i, int $j, float $x, float $y, float $scale, array $d, int $rgb, float $alpha): void
    {
        $rgb = $d[3] ?? $rgb;
        foreach ([\MyPHP\Text\System::UNDERLINE, \MyPHP\Text\System::STRIKETHROUGH] as $kind) {
            if ($kind === \MyPHP\Text\System::UNDERLINE ? !$d[0] : !$d[2]) {
                continue;
            }
            foreach ($sys->decorate($l, $li, $i, $j, $x, $y, $scale, $kind, $d[4]) as [$x0, $x1, $top, $bottom]) {
                if ($x1 <= $x0 || $bottom <= $top) {
                    continue;
                }
                if ($kind === \MyPHP\Text\System::UNDERLINE && $d[1]) {
                    self::wave($list, $x0, $x1, ($top + $bottom) / 2, max($bottom - $top, 1 / $scale), $rgb, $alpha);
                    continue;
                }
                $list->rect($x0, $top, $x1 - $x0, $bottom - $top, $rgb, $alpha);
            }
        }
    }

    /** A wave from x0 to x1 around y, $thick points wide, one and a half times as high, as spell checkers underline words (mygo's wave). */
    private static function wave(DisplayList $list, float $x0, float $x1, float $y, float $thick, int $rgb, float $alpha): void
    {
        $amp = $thick * 1.5 / 2;
        $length = $thick * 6;
        $y += $amp;
        $n = max((int)ceil(($x1 - $x0) / ($length / 8)), 1); // eight points a wave, the last at x1
        $path = (new Path())->moveTo($x0, $y);
        for ($i = 1; $i <= $n; $i++) {
            $px = $x0 + ($x1 - $x0) * $i / $n;
            $path->lineTo($px, $y - $amp * sin(($px - $x0) / $length * 2 * M_PI));
        }
        $list->path($path, $rgb, $alpha, $thick);
    }

    /** The focus rings of the text inside a paragraph, around their words, and of the text inside that (mygo's paintInline). */
    private static function paintInline(Node $n, DisplayList $list, Theme $t): void
    {
        foreach ($n->kids as $k) {
            if ($k->focusVisible) {
                foreach ($k->frags as [$fx, $fy, $fw, $fh]) {
                    self::paintFocusRing($list, $fx, $fy, $fw, $fh, 2.0, $t);
                }
            }
            self::paintInline($k, $list, $t);
        }
    }

    /** The color of each byte of an input's display() from $from (Node::textRanges): the last range holding it, else the input's; the composition its own. @return \Closure(int): array{0: int, 1: float} */
    private static function rangeColors(Node $n, TextState $s, int $from): \Closure
    {
        $byte = static fn(int $r): int => strlen(mb_substr($s->text, 0, max(0, $r)));
        $runs = array_map(static fn(TextRange $r): array => [$byte($r->start), $byte($r->end), $r->color, $r->alpha], array_reverse($n->ranges));
        $mark = $s->marked === null ? null : [$s->cursor, strlen($s->marked)];
        return static function (int $i) use ($runs, $mark, $from, $n): array {
            $b = $from + $i;
            if ($mark !== null && $b >= $mark[0]) {
                if ($b < $mark[0] + $mark[1]) { return [$n->fg, 1.0]; } // the composition, unstyled
                $b -= $mark[1];
            }
            foreach ($runs as [$a, $z, $rgb, $alpha]) {
                if ($b >= $a && $b < $z) { return [$rgb, $alpha]; }
            }
            return [$n->fg, 1.0];
        };
    }

    /** A text field: border, selection, text or placeholder, composition underline, caret. */
    private static function paintField(Node $n, DisplayList $list, TextPainter $text, Theme $t, ?Runtime $rt): void
    {
        $s = $n->edit;
        // Its padding, radius, background and border as the app set them (mygo's on a TextInput), else the theme's field.
        [$insetL, $insetR] = $n->pads !== null ? [$n->padL(), $n->padR()] : [Runtime::FIELD_INSET, Runtime::FIELD_INSET];
        $inset = $insetL + $n->insetL;
        if ($n->bordered) { // its own box; a combobox's input sits in a field box drawn around it
            $radius = $n->radius > 0 ? $n->radius : $t->radius;
            foreach ($n->shadows as $sh) { // outside the box, under it
                if (!$sh[6]) { self::boxShadow($list, [$n->x, $n->y, $n->w, $n->h], [$radius, $radius, $radius, $radius], $sh); }
            }
            $bw = $n->borderWidth;
            if ($bw > 0) {
                $list->rect($n->x, $n->y, $n->w, $n->h, $n->borderColor ?? self::inputBorder($n, $t, $rt), 1.0, $radius);
            }
            $list->rect($n->x + $bw, $n->y + $bw, $n->w - 2 * $bw, $n->h - 2 * $bw, $n->color ?? $t->surface, $n->bgAlpha, max($radius - $bw, 0.0));
        }
        $display = $s->shown(); // bullets for a password
        [$tw, $lh] = $text->measure($display === '' ? 'X' : $display, $n->fontSize, $s->style);
        $ty = $n->y + ($n->h - $lh) / 2;
        $inner = $n->w - $inset - $insetR - $n->insetR;
        // Keep the caret in view when the text is wider than the field; without the focus, its start shows (mygo's InputShowsItsStartUnfocused).
        $caretX = $text->xForIndex($display, $n->fontSize, $s->shownIndex($s->displayCaret()), $s->style);
        $shift = $n->focused ? max(0.0, $caretX - $inner) : 0.0;
        $tx = $n->x + $inset - $shift;
        $align = $n->ts?->align ?? Node::START;
        if ($n->rtl && $align !== Node::CENTER) { $align = $align === Node::END ? Node::START : Node::END; } // right to left, the text starts at the right, whatever it reads (mygo's RTLTextInput)
        $at = fn(float $w): float => $w <= $inner && $align !== Node::START ? ($inner - $w) * ($align === Node::CENTER ? 0.5 : 1.0) : 0.0; // a text that fits goes where textAlign() puts it (mygo's TextAlign)
        $tx += $at($display === '' ? 0.0 : $tw); // an empty field's caret where its text would start
        $s->offsetX = $tx - ($n->x + $inset); // where the text starts in the field, which a click and the input method's caret go by
        $b = $n->bordered ? $n->borderWidth : 0.0; // clipped inside its border, as mygo's paintInput: a field without padding shows its text's first pixels
        $list->pushClip($n->x + $b + $n->insetL, $n->y + $b, $n->w - 2 * $b - $n->insetL - $n->insetR, $n->h - 2 * $b);
        if ($display === '') {
            if ($n->text !== null && $n->text !== '') {
                $text->paint($list, $n->text, $n->fontSize, $tx - $at(0.0) + $at($text->measure($n->text, $n->fontSize, $s->style)[0]), $ty, $t->textMuted, 1.0, $s->style);
            }
        } else {
            $ts = $n->textStyle();
            if ($ts->background !== null) { // the text's background, under the selection (mygo's textBackgrounds)
                $list->rect($tx, $ty, $tw, $lh, $ts->background);
            }
            if ($n->focused && $s->hasSelection() && $s->marked === null) {
                [$a, $b] = $s->selection();
                $xa = $text->xForIndex($display, $n->fontSize, $s->shownIndex($a), $s->style);
                $xb = $text->xForIndex($display, $n->fontSize, $s->shownIndex($b), $s->style);
                $list->rect($tx + $xa, $ty, $xb - $xa, $lh, ...self::selectionColor($ts, $t));
            }
            if ($n->ranges !== [] && !$s->password) {
                $text->paintRuns($list, $display, $n->fontSize, $tx, $ty, $s->style, self::rangeColors($n, $s, 0));
            } else {
                $text->paint($list, $display, $n->fontSize, $tx, $ty, $ts->color ?? $n->fg, 1.0, $s->style);
            }
            if ($s->marked !== null) {
                $xa = $text->xForIndex($display, $n->fontSize, $s->shownIndex($s->cursor), $s->style);
                $xb = $text->xForIndex($display, $n->fontSize, $s->shownIndex($s->cursor + strlen($s->marked)), $s->style);
                $list->rect($tx + $xa, $ty + $lh - 2, $xb - $xa, 1.0, $ts->color ?? $n->fg); // the composition's underline, over the glyphs (mygo's)
            }
        }
        if ($n->focused && !$s->readOnly && ($rt === null || $rt->caretVisible())) { // read-only: selected and copied, without a caret
            $list->rect($tx + $caretX, $ty, 1.0, $lh, $t->accent); // in the accent, over all (mygo's caret)
        }
        $list->popClip();
    }

    /** A text area: border, wrapped lines scrolled to the caret, selection across lines, composition underline, caret. */
    private static function paintArea(Node $n, DisplayList $list, TextPainter $text, Theme $t, ?Runtime $rt): void
    {
        $s = $n->edit;
        [$inset, $insetY] = $n->bare ? [0.0, 0.0] : [Runtime::FIELD_INSET, Runtime::FIELD_INSET_Y]; // an area without a look: no padding, no face
        if (!$n->bare) {
            $list->rect($n->x, $n->y, $n->w, $n->h, self::inputBorder($n, $t, $rt), 1.0, $t->radius);
            $list->rect($n->x + 1, $n->y + 1, $n->w - 2, $n->h - 2, $n->color ?? $t->surface, 1.0, $t->radius - 1);
        }
        $display = $s->display();
        $innerW = $n->w - 2 * $inset; $innerH = $n->h - 2 * $insetY;
        $layout = $s->layout($text, $n->fontSize, $innerW);
        $lh = $layout->lineHeight;
        // Never scroll past the text; bring the caret into view when it moved (not while the wheel scrolls away from it).
        $range = max(0.0, $layout->height() - $innerH);
        $s->scrollY = max(0.0, min($s->scrollY, $range));
        $caret = $s->displayCaret(); // the caret alone: a text the app changes around it leaves the view (mygo's KeepsViewOnAppText)
        if ($n->focused && $caret !== $s->caretSeen) {
            $s->caretSeen = $caret;
            [, $cy] = $layout->caret($text, $s->displayCaret());
            if ($cy < $s->scrollY) { $s->scrollY = $cy; }
            if ($cy + $lh > $s->scrollY + $innerH) {
                // The paragraphs above the caret's that the view will show are wrapped first, so that the caret ends at its bottom whatever lines they make (mygo's reveal).
                $layout->measureAbove($caret, (int)ceil($innerH / $lh));
                [, $cy] = $layout->caret($text, $caret);
                $s->scrollY = $cy + $lh - $innerH;
            }
        }
        $s->scrollFraction = $range > 0 ? $s->scrollY / $range : 0.0;
        $tx = $n->x + $inset; $ty = $n->y + $insetY - $s->scrollY;
        $in = $n->bare ? 0.0 : 2.0; // inside the border
        $list->pushClip($n->x + $in, $n->y + $in, $n->w - 2 * $in, $n->h - 2 * $in);
        if ($display === '' && $n->text !== null && $n->text !== '') { // the placeholder wraps as the text would (mygo's)
            $ph = FieldLayout::wrap($text, $n->text, $n->fontSize, $innerW, null, $s->style);
            for ($i = 0; $i < $ph->count(); $i++) { $text->paint($list, $ph->lineText($i), $n->fontSize, $tx, $ty + $i * $ph->lineHeight, $t->textMuted, 1.0, $s->style); }
        }
        [$selA, $selB] = $n->focused && $s->hasSelection() && $s->marked === null ? $s->selection() : [0, 0];
        $ts = $n->textStyle(); $selColor = self::selectionColor($ts, $t);
        $first = max(0, (int)floor($s->scrollY / $lh));
        for ($i = $first; $i < $layout->count() && $i <= (int)floor(($s->scrollY + $innerH) / $lh); $i++) { // the paragraphs in view are wrapped as they are reached
            [$a, $b] = $layout->line($i);
            $line = $layout->lineText($i);
            $y = $ty + $i * $lh;
            if ($ts->background !== null && $line !== '') { // the line's text background, under the selection
                $list->rect($tx, $y, $text->xForIndex($line, $n->fontSize, strlen($line), $s->style), $lh, $ts->background);
            }
            if ($selB > $selA && $selB > $a && $selA < $b) {
                $xa = $selA > $a ? $text->xForIndex($line, $n->fontSize, $selA - $a, $s->style) : 0.0;
                $xb = $selB < $b ? $text->xForIndex($line, $n->fontSize, min($selB - $a, strlen($line)), $s->style) : $text->xForIndex($line, $n->fontSize, strlen($line), $s->style) + ($selB > $b ? $n->fontSize * 0.3 : 0);
                $list->rect($tx + $xa, $y, $xb - $xa, $lh, ...$selColor);
            }
            if ($line !== '' && $n->ranges !== []) {
                $text->paintRuns($list, $line, $n->fontSize, $tx, $y, $s->style, self::rangeColors($n, $s, $a));
            } elseif ($line !== '') {
                $text->paint($list, $line, $n->fontSize, $tx, $y, $ts->color ?? $n->fg, 1.0, $s->style);
            }
        }
        if ($s->marked !== null) {
            [$xa, $ya] = $layout->caret($text, $s->cursor);
            [$xb, $yb] = $layout->caret($text, $s->cursor + strlen($s->marked));
            if ($yb !== $ya) { $xb = $innerW; }
            $list->rect($tx + $xa, $ty + $ya + $lh - 2, $xb - $xa, 1.0, $ts->color ?? $n->fg);
        }
        if ($n->focused && !$s->readOnly && ($rt === null || $rt->caretVisible())) {
            [$cx, $cy] = $layout->caret($text, $s->displayCaret());
            $list->rect($tx + $cx, $ty + $cy, 1.0, $lh, $t->accent);
        }
        $list->popClip();
    }
}
