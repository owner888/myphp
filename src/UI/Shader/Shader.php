<?php
declare(strict_types=1);

namespace MyPHP\UI\Shader;

use Closure;
use InvalidArgumentException;
use LogicException;
use MyPHP\UI\{Affine, Image, Painter, Path, Span, WideColor};
use MyPHP\UI\Shader\Compiler\{Compiler, ParseError};
use MyPHP\UI\Shader\Ir\{BasicType, Type};
use MyPHP\UI\Shader\Syntax\{Parser, SyntaxError};

/**
 * A shader an app writes (mygo's ui/shader Shader): a fragment shader in
 * Go's syntax, Ebitengine's Kage, that computes the color of every pixel
 * of what it fills on the GPU, from its position, values the app passes
 * each frame and images it reads:
 *
 *     $waves = Shader::compile(<<<'KAGE'
 *     package main
 *
 *     var Time float
 *
 *     func Fragment(dst vec4, pos vec2) vec4 {
 *         v := 0.5 + 0.5*sin(pos.x/20+Time)
 *         return vec4(v*0.2, v*0.4, v, 1)
 *     }
 *     KAGE);
 *
 *     $c->box(0, 0)->fill()->draw(function (DisplayList $list, Node $n, Theme $t, TextPainter $text) use ($waves) {
 *         $p = new Painter($list, $text, $t);
 *         $waves->draw($p, [$n->x, $n->y, $n->w, $n->h], new Options(uniforms: ['Time' => hrtime(true) / 1e9]));
 *     });
 *
 * Fragment returns the pixel's color, premultiplied; dst is its center in
 * pixels of the window (or of the canvas drawn into), pos the same point
 * in points from the top left corner of what it fills. Exported variables
 * at the package level are uniforms, which Options sets by name, or
 * Values by variables looked up once (uniform()). It reads up to four
 * images: image0At(p), image0Sample(p) and image0Size(), and so on;
 * rectSize() returns the size of what it fills, pixelScale() the pixels a
 * point. An effect's shader has ColorEffect, DistortionEffect or
 * LayerEffect in place of Fragment, and draws an element (effect()).
 */
final class Shader
{
    /** The kinds of a shader, by its entry point: a fill, and the three effects. */
    private const FRAGMENT = 0, COLOR_EFFECT = 1, DISTORTION_EFFECT = 2, LAYER_EFFECT = 3;
    /** The entry points of the kinds, and the Fragment that calls an effect's with its content (layerAt, layerSample). */
    private const ENTRIES = [
        self::FRAGMENT => ['Fragment', ''],
        self::COLOR_EFFECT => ['ColorEffect', 'return ColorEffect(q, layerAt(q))'],
        self::DISTORTION_EFFECT => ['DistortionEffect', 'return layerSample(DistortionEffect(q))'],
        self::LAYER_EFFECT => ['LayerEffect', 'return LayerEffect(q)'],
    ];
    /** The bridge's uniforms, which drawing sets, first: the images' sizes, the size of what it fills, the pixels a point, an effect's box in its content, a backdrop's map and area (mygo's bridge). */
    private const BRIDGE = ['__imageSizes', '__rectSize', '__scale', '__layerInset', '__backdropRow0', '__backdropRow1', '__backdropArea'];
    /** Where the bridge's uniforms start, in words. */
    private const BRIDGE_WORDS_AT = [0, 8, 10, 11, 13, 16, 19];
    /** @internal the words of the bridge's uniforms, which Values leaves to drawing */
    public const BRIDGE_WORDS = 23;
    private const SCALE_WORD = 10, INSET_WORD = 11, ROW0_WORD = 13;
    /** @internal the words Metal sets for a shader reading its backdrop: image2's size, and where the backdrop's texels lie, how many pixels each covers */
    public const BACKDROP_SIZE_WORD = 4, AREA_WORD = 19;
    /** The image an effect reads its content from (layerAt), and the one a shader reads its backdrop from (backdropAt). */
    private const LAYER_IMAGE = 3, BACKDROP_IMAGE = 2;

    /** @var array<string, array{0: Type, 1: int, 2: int}> the uniforms by name: type, first word and words */
    private array $uniforms = [];
    private int $words = 0;
    /** @var list<int> its words, all zero, which a draw sets */
    private array $zeros = [];
    /** @internal how Metal draws it */
    public readonly Code $code;

    private function __construct(private readonly int $kind, private readonly bool $backdrop) {}

    /**
     * Compiles $src, the source of a Go file as described above (mygo's
     * Compile). It throws ParseError for what is wrong with the source,
     * line by line, and InvalidArgumentException for a shader of two entry
     * points or reading an image that holds its content or its backdrop.
     * Compile shaders once, as the app starts, not every frame.
     */
    public static function compile(string $src): self
    {
        $kind = self::kindOf($src);
        $full = $src;
        if ($kind !== self::FRAGMENT) {
            // An effect draws over all of its content, its positions from the corner of the element's box within it.
            $full .= "\nfunc Fragment(dst vec4, pos vec2) vec4 {\n\tq := pos - __layerInset/pixelScale()\n\t" . self::ENTRIES[$kind][1] . "\n}\n";
        }
        $layerRead = $kind !== self::FRAGMENT && preg_match('/\bimage' . self::LAYER_IMAGE . '(At|Sample|Size)\b/', $src) === 1;
        try {
            $p = Compiler::compileFragment($full);
        } catch (ParseError $e) {
            if ($kind !== self::FRAGMENT && !self::parses($src)) {
                Compiler::compileFragment($src); // throws its syntax error, as the source has it, no entry wrapped after it
            }
            if ($layerRead) {
                throw self::layerRead();
            }
            throw $e;
        }
        if ($layerRead) {
            throw self::layerRead();
        }
        foreach (self::BRIDGE as $i => $name) {
            if (($p->uniformNames[$i] ?? null) !== $name) {
                throw new LogicException('shader: the uniforms start with ' . implode(', ', $p->uniformNames) . ', not ' . implode(', ', self::BRIDGE));
            }
        }
        $backdrop = preg_match('/\bbackdrop(At|Sample)\b/', $src) === 1;
        if ($backdrop && preg_match('/\bimage' . self::BACKDROP_IMAGE . '(At|Sample|Size)\b/', $src)) {
            throw new InvalidArgumentException('shader: a shader reading its backdrop reads image' . self::BACKDROP_IMAGE . ' no more: it holds the backdrop (backdropAt, backdropSample)');
        }
        $s = new self($kind, $backdrop);
        foreach ($p->uniforms as $i => $t) {
            if ($i < count(self::BRIDGE_WORDS_AT) && $s->words !== self::BRIDGE_WORDS_AT[$i]) {
                throw new LogicException("shader: the bridge's {$p->uniformNames[$i]} at word {$s->words}, not " . self::BRIDGE_WORDS_AT[$i]);
            }
            $n = $t->dwordCount();
            $s->uniforms[$p->uniformNames[$i]] = [$t, $s->words, $n];
            $s->words += $n;
        }
        $s->zeros = array_fill(0, $s->words, 0);
        $s->code = new Code($p, $backdrop);
        return $s;
    }

    /**
     * The kind of the shader of $src by its entry point, of which it has
     * one; a source that doesn't parse is the compiler's to report. Found
     * in the source, its comments and literals left out, without parsing
     * it, which compiling does: a function's name follows func, which a
     * method's receiver or a function literal's parameters follow instead.
     */
    private static function kindOf(string $src): int
    {
        $code = preg_replace('~//[^\n]*|/\*.*?\*/|"(?:[^"\\\\\n]|\\\\.)*"|`[^`]*`|\'(?:[^\'\\\\\n]|\\\\.)*\'~s', ' ', $src) ?? throw new LogicException('shader: ' . preg_last_error_msg());
        preg_match_all('/\bfunc\s+(' . implode('|', array_column(self::ENTRIES, 0)) . ')\s*\(/', $code, $m);
        $found = array_map(static fn(string $name): int => array_search($name, array_column(self::ENTRIES, 0), true), $m[1]);
        if (count($found) > 1 && self::parses($src)) {
            throw new InvalidArgumentException(sprintf('shader: %s and %s: a shader has one entry point', self::ENTRIES[$found[0]][0], self::ENTRIES[$found[1]][0]));
        }
        return count($found) === 1 ? $found[0] : self::FRAGMENT;
    }

    private static function layerRead(): InvalidArgumentException
    {
        return new InvalidArgumentException('shader: an effect\'s shader reads image0 to image' . (self::LAYER_IMAGE - 1) . ': image' . self::LAYER_IMAGE . ' is its content (layerAt, layerSample)');
    }

    /** Whether Go's syntax takes $src. */
    private static function parses(string $src): bool
    {
        try {
            Parser::parse($src);
            return true;
        } catch (SyntaxError) {
            return false;
        }
    }

    /** The uniform variable $name, which Values sets by: look it up once (mygo's Uniform). It throws for a name the shader has not. */
    public function uniform(string $name): Uniform
    {
        $u = $this->uniforms[$name] ?? null;
        if ($u === null || $name === '' || $name[0] === '_') {
            throw new InvalidArgumentException("shader: no uniform variable $name");
        }
        return new Uniform($this, $name, $u[0], $u[1], $u[2]);
    }

    /** Values for the shader's uniform variables, all zero (mygo's NewValues). */
    public function newValues(): Values
    {
        return new Values($this, $this->words);
    }

    /**
     * Fills $r, in points of $p, with the shader, as $p's other drawings
     * are: clipped, transformed and faded with the painter; Options::$radius
     * rounds its corners (mygo's Draw).
     * @param array{0: float, 1: float, 2: float, 3: float} $r
     */
    public function draw(Painter $p, array $r, Options $o = new Options): void
    {
        $this->must(self::FRAGMENT);
        if ($r[2] <= 0 || $r[3] <= 0) {
            return;
        }
        [$bytes, $wide, $images] = $this->input($p->scale(), $p->world(), $r, $o, [0.0, 0.0]);
        $p->shaderEffect($this->code, $r, $o->radius, $bytes, $wide, $images, max($o->backdropBlur, 0.0) * $p->scale());
    }

    /**
     * Paints the text, paths and icons $fn paints with $p with the shader
     * in place of their colors, as a gradient fills them: each pixel the
     * shader's color times the shape's coverage, the shader filling $box,
     * in points, whose corner its positions start from and whose size
     * rectSize() returns. Emoji keep their colors, and the rest $fn paints,
     * as rectangles and images, paints as it would (mygo's Fill).
     * @param array{0: float, 1: float, 2: float, 3: float} $box
     */
    public function fill(Painter $p, array $box, Options $o, Closure $fn): void
    {
        $this->must(self::FRAGMENT);
        if ($this->backdrop) {
            throw new LogicException('shader: a shader reading its backdrop fills rectangles (draw), not shapes (fill)');
        }
        $p->shaderFill($this->code, $box, $fn, ...$this->input($p->scale(), $p->world(), $box, $o, [0.0, 0.0]));
    }

    /** Fills $path with the shader (non-zero winding), the shader filling the path's bounds (mygo's FillPath). */
    public function fillPath(Painter $p, Path $path, Options $o = new Options): void
    {
        $b = $path->bounds();
        if ($b !== null) {
            $this->fill($p, $b, $o, fn() => $p->fillPath($path, 0xffffff));
        }
    }

    /** Strokes $path with the shader, $width points wide, with its caps and joins, the shader filling the path's bounds grown by half the width (mygo's StrokePath). */
    public function strokePath(Painter $p, Path $path, float $width, Options $o = new Options): void
    {
        $b = $path->bounds();
        if ($b !== null) {
            $h = $width / 2;
            $this->fill($p, [$b[0] - $h, $b[1] - $h, $b[2] + $width, $b[3] + $width], $o, fn() => $p->strokePath($path, $width, 0xffffff));
        }
    }

    /** Draws a line of $text with its top left corner at ($x, $y), $size points tall, filled with the shader, which fills the text's box (mygo's Text). */
    public function text(Painter $p, float $x, float $y, string $text, float $size, Options $o = new Options): void
    {
        [$w, $h] = $p->measureText(0, new Span($text, size: $size));
        $this->fill($p, [$x, $y, $w, $h], $o, fn() => $p->text($x, $y, $text, $size, 0xffffff));
    }

    /**
     * The shader drawing an element with $o, for Node::effect: the shader of
     * an effect, whose entry point reads the element's content, rendered
     * with its children, in points from the corner of its box, as
     * SwiftUI's effects do (mygo's Effect):
     *
     *     func ColorEffect(pos vec2, color vec4) vec4  // the content's colors, changed
     *     func DistortionEffect(pos vec2) vec2         // where to read the content for pos
     *     func LayerEffect(pos vec2) vec4              // the color, from layerAt(p) or layerSample(p)
     *
     * It reads images 0 to 2 of its own (image3 is the content), and moves
     * pixels at most Options::$maxSampleOffset away. It throws for a shader
     * whose entry point is Fragment, which draw() and fill() draw.
     */
    public function effect(Options $o = new Options): Effect
    {
        if ($this->kind === self::FRAGMENT) {
            throw new LogicException('shader: effect() of a shader of Fragment: an effect\'s has ColorEffect, DistortionEffect or LayerEffect');
        }
        return new Effect($this, $o);
    }

    /** @internal Effect: paints the element from its content, a canvas's bitmap, the box's corner at $inset in the content's pixels. @param array{0: float, 1: float, 2: float, 3: float} $box @param array{0: float, 1: float} $inset */
    public function paintEffect(Painter $p, array $box, Image $content, array $inset, Options $o): void
    {
        $images = $o->images;
        $images[self::LAYER_IMAGE] = $content;
        $s = $p->scale();
        $area = [$box[0] - $inset[0] / $s, $box[1] - $inset[1] / $s, $content->w / $s, $content->h / $s];
        $o = clone $o;
        $o->images = $images;
        [$bytes, $wide, $imgs] = $this->input($p->scale(), $p->world(), $box, $o, $inset);
        $p->shaderEffect($this->code, $area, 0.0, $bytes, $wide, $imgs, max($o->backdropBlur, 0.0) * $p->scale());
    }

    /** Throws unless the shader is of kind $k. */
    private function must(int $k): void
    {
        if ($this->kind !== $k) {
            throw new LogicException('shader: a shader of ' . self::ENTRIES[$this->kind][0] . ' draws through effect(), not draw() or fill()');
        }
    }

    /**
     * @internal what the shader reads drawn with $o in $r at $scale pixels
     * a point, as input()'s, for a draw the painter makes itself (a canvas
     * larger than a page of their atlas drawn as an image)
     * @param array{0: float, 1: float, 2: float, 3: float} $r
     * @return array{0: string, 1: ?string, 2: list<?Image>}
     */
    public function inputAt(float $scale, array $r, Options $o): array
    {
        return $this->input($scale, Affine::identity(), $r, $o, [0.0, 0.0]);
    }

    /**
     * What the shader reads drawn with $o in $r, the box it fills, its
     * corner at $inset in pixels from what it draws over: its uniforms'
     * bytes, the bridge's first, those a wide gamut reads in their place
     * where they differ, and its images.
     * @param array{0: float, 1: float, 2: float, 3: float} $r
     * @param array{0: float, 1: float} $inset
     * @return array{0: string, 1: ?string, 2: list<?Image>}
     */
    private function input(float $scale, Affine $world, array $r, Options $o, array $inset): array
    {
        $words = $this->zeros;
        $colors = [];
        if ($o->values !== null) {
            if ($o->values->shader !== $this) {
                throw new InvalidArgumentException('shader: Options::$values of another shader');
            }
            $words = $o->values->words; // the bridge's words zero, which Values leaves to drawing
            $colors = $o->values->colors;
        }
        $f = static fn(float $x): int => unpack('V', pack('g', $x))[1];
        foreach ($o->images as $i => $img) {
            if ($img !== null) {
                [$words[2 * $i], $words[2 * $i + 1]] = [$f((float)$img->w), $f((float)$img->h)];
            }
        }
        [$words[self::SCALE_WORD - 2], $words[self::SCALE_WORD - 1]] = [$f($r[2]), $f($r[3])];
        $words[self::SCALE_WORD] = $f($scale);
        [$words[self::INSET_WORD], $words[self::INSET_WORD + 1]] = [$f($inset[0]), $f($inset[1])];
        if ($this->backdrop) {
            // The map from its points, from the box's corner, to the window's pixels; Metal sets where the texels lie, which only it knows.
            $m = $world; $s = $scale;
            [$a, $b, $c, $d, $x, $y] = $m->set ? [$m->a, $m->b, $m->c, $m->d, $m->x, $m->y] : [1.0, 0.0, 0.0, 1.0, 0.0, 0.0];
            foreach ([$a * $s, $c * $s, ($a * $r[0] + $c * $r[1] + $x) * $s, $b * $s, $d * $s, ($b * $r[0] + $d * $r[1] + $y) * $s] as $i => $v) {
                $words[self::ROW0_WORD + $i] = $f($v);
            }
        }
        foreach ($o->uniforms as $name => $v) {
            $u = $this->uniforms[$name] ?? null;
            if ($u === null || $name[0] === '_') {
                continue;
            }
            [$t, $at, $n] = $u;
            if (is_int($v) && ($t->main === BasicType::VEC3 || $t->main === BasicType::VEC4)) {
                $colors[$at] = [$n, $v, 1.0]; // a color
                continue;
            }
            $kind = $t->main === BasicType::ARRAY ? $t->sub[0]->scalar() : $t->scalar();
            self::set($words, $at, $n, $kind, $name, $v);
        }
        // Colors in sRGB, and outside it in extended sRGB, which a wide gamut reads in their place.
        $wideAt = [];
        foreach ($colors as $at => [$n, $rgb, $alpha]) {
            $srgb = WideColor::srgb($rgb);
            $c = [(($srgb >> 16) & 255) / 255, (($srgb >> 8) & 255) / 255, ($srgb & 255) / 255, $alpha];
            for ($i = 0; $i < $n && $i < 4; $i++) { $words[$at + $i] = $f($c[$i]); }
            if (($ext = WideColor::wide($rgb)) !== null) {
                $wideAt[$at] = [min($n, 3), $ext];
            }
        }
        $wide = null;
        if ($wideAt !== []) {
            $wide = $words;
            foreach ($wideAt as $at => [$n, $ext]) {
                for ($i = 0; $i < $n; $i++) { $wide[$at + $i] = $f($ext[$i]); }
            }
        }
        return [$this->code->bytes($words), $wide === null ? null : $this->code->bytes($wide), $o->images];
    }

    /**
     * Writes $v, the value of uniform $name, into its $n words from $at, as
     * numbers of $kind: a number, a bool, or a list of them, from the
     * first. Integers set ints and uints by their low 32 bits; floats set
     * them dropping their fraction (mygo's set).
     * @param list<int> $words
     */
    private static function set(array &$words, int $at, int $n, int $kind, string $name, mixed $v): void
    {
        if (is_array($v)) {
            $xs = array_slice(array_values($v), 0, $n);
            foreach ($xs as $x) {
                if (!is_int($x) && !is_float($x) && !is_bool($x)) {
                    throw new InvalidArgumentException("shader: the uniform $name is set to an array of " . get_debug_type($x) . ', not numbers');
                }
            }
        } elseif (!is_int($v) && !is_float($v) && !is_bool($v)) {
            throw new InvalidArgumentException("shader: the uniform $name is set to a " . get_debug_type($v) . ', not a number, numbers or a color');
        } else {
            $xs = $n > 0 ? [$v] : [];
        }
        if ($xs === []) {
            return;
        }
        $lanes = match ($kind) {
            BasicType::FLOAT => array_values(unpack('V*', pack('g*', ...$xs))), // all at once: a bool as 0 or 1
            BasicType::BOOL => array_map(static fn(int|float|bool $x): int => $x != 0 ? 1 : 0, $xs),
            default => array_map(static fn(int|float|bool $x): int => (is_int($x) ? $x : (int)$x) & 0xffffffff, $xs), // a float's fraction dropped
        };
        foreach ($lanes as $i => $w) { $words[$at + $i] = $w; }
    }
}
