<?php
declare(strict_types=1);

namespace MyPHP\Native;

use MyPHP\UI\Font;
use FFI;
use FFI\CData;
use RuntimeException;

/**
 * Text shaping and glyph rasterization through CoreText and CoreGraphics.
 * Shapes a string with the system UI font (with CoreText's own fallback
 * fonts for scripts it lacks) and rasterizes single glyphs to 8-bit
 * coverage bitmaps at the display's backing scale.
 */
final class CoreText
{
    private const UTF8 = 0x08000100;         // kCFStringEncodingUTF8
    private const UI_FONT_SYSTEM = 2;        // kCTFontUIFontSystem
    private const UI_FONT_USER_FIXED_PITCH = 1;
    private const TRAIT_ITALIC = 1 << 0, TRAIT_BOLD = 1 << 1; // CTFontSymbolicTraits
    private const ALPHA_ONLY = 7;            // kCGImageAlphaOnly
    private const RGBA_PREMULTIPLIED = 1 | 4 << 12; // kCGImageAlphaPremultipliedLast | kCGBitmapByteOrder32Big: R G B A in memory
    private const TRAIT_COLOR_GLYPHS = 1 << 13; // kCTFontTraitColorGlyphs: Apple Color Emoji's

    public FFI $c;
    private $fontKey;         // kCTFontAttributeName
    private $keyCallbacks;    // &kCFTypeDictionaryKeyCallBacks
    private $valueCallbacks;  // &kCFTypeDictionaryValueCallBacks
    private $srgb;            // the sRGB color space color glyphs are drawn in
    /** @var array<string, CData> CTFont by "size" */
    private array $fonts = [];
    /** @var array<int, CData> fonts runs used, retained, by CFHash */
    private array $runFonts = [];
    /** @var array<int, bool> whether each run font draws in color, by CFHash */
    private array $colorFonts = [];
    /** Whether the user leaves font smoothing on (AppleFontSmoothing), read when a glyph is first drawn. */
    private ?bool $smooth = null;
    /** The callback CGPathApply hands an outline's elements, made once and held (outline()). */
    private ?CData $applier = null;
    /** @var list<array{0: float, 1: float, 2: float, 3: float}> the segments of the outline being read */
    private array $segs = [];
    /** The current point of the outline being read, and the start of its subpath. */
    private float $atX = 0, $atY = 0, $fromX = 0, $fromY = 0;

    public function __construct(public float $scale)
    {
        $this->c = FFI::cdef("
            typedef struct { long location, length; } CFRange;
            typedef struct { double x, y; } CGPoint;
            typedef struct { double w, h; } CGSize;
            typedef struct { CGPoint origin; CGSize size; } CGRect;
            typedef unsigned short CGGlyph;
            typedef struct { int type; CGPoint* points; } CGPathElement;
            typedef void (*CGPathApplier)(void*, const CGPathElement*);
            typedef struct { CGPathApplier fn; } PathApplierHolder;
            void* CTFontCreatePathForGlyph(void*, CGGlyph, void*);
            void  CGPathApply(void*, void*, CGPathApplier);
            void  CGPathRelease(void*);
            void* dlopen(const char*, int);
            void* dlsym(void*, const char*);
            void* CFStringCreateWithCString(void*, const char*, unsigned int);
            void* CFAttributedStringCreate(void*, void*, void*);
            void* CFDictionaryCreate(void*, const void**, const void**, long, const void*, const void*);
            void* CFDictionaryGetValue(void*, const void*);
            long  CFArrayGetCount(void*);
            void* CFArrayGetValueAtIndex(void*, long);
            void* CFRetain(void*);
            void  CFRelease(void*);
            unsigned long CFHash(void*);
            void* CTFontCreateUIFontForLanguage(unsigned int, double, void*);
            void* CTFontCreateCopyWithSymbolicTraits(void*, double, const void*, unsigned int, unsigned int);
            double CTFontGetAscent(void*);
            double CTFontGetDescent(void*);
            CGRect CTFontGetBoundingRectsForGlyphs(void*, unsigned int, const CGGlyph*, CGRect*, long);
            void  CTFontDrawGlyphs(void*, const CGGlyph*, const CGPoint*, unsigned long, void*);
            unsigned int CTFontGetSymbolicTraits(void*);
            void* CGColorSpaceCreateWithName(void*);
            void* CTLineCreateWithAttributedString(void*);
            void* CTLineGetGlyphRuns(void*);
            double CTLineGetTypographicBounds(void*, double*, double*, double*);
            long  CTRunGetGlyphCount(void*);
            void  CTRunGetGlyphs(void*, CFRange, CGGlyph*);
            void  CTRunGetPositions(void*, CFRange, CGPoint*);
            void  CTRunGetStringIndices(void*, CFRange, long*);
            void* CTRunGetAttributes(void*);
            void* CGBitmapContextCreate(void*, unsigned long, unsigned long, unsigned long, unsigned long, void*, unsigned int);
            void* CGBitmapContextGetData(void*);
            void  CGContextRelease(void*);
            void  CGContextTranslateCTM(void*, double, double);
            void  CGContextScaleCTM(void*, double, double);
            void  CGContextSetShouldSmoothFonts(void*, bool);
            void  CGContextSetRGBFillColor(void*, double, double, double, double);
            void  CGContextAddPath(void*, void*);
            void  CGContextSetLineWidth(void*, double);
            void  CGContextSetLineJoin(void*, int);
            void  CGContextDrawPath(void*, int);
        ", '/System/Library/Frameworks/CoreText.framework/CoreText');
        $lib = $this->c->dlopen('/System/Library/Frameworks/CoreText.framework/CoreText', 2);
        $cf = $this->c->dlopen('/System/Library/Frameworks/CoreFoundation.framework/CoreFoundation', 2);
        // kCTFontAttributeName is a CFStringRef variable: dlsym gives its address, the cast reads its value.
        $this->fontKey = $this->c->cast('void*[1]', $this->c->dlsym($lib, 'kCTFontAttributeName'))[0]
            ?? throw new RuntimeException('kCTFontAttributeName');
        $this->keyCallbacks = $this->c->dlsym($cf, 'kCFTypeDictionaryKeyCallBacks');
        $this->valueCallbacks = $this->c->dlsym($cf, 'kCFTypeDictionaryValueCallBacks');
        $cg = $this->c->dlopen('/System/Library/Frameworks/CoreGraphics.framework/CoreGraphics', 2);
        $this->srgb = $this->c->CGColorSpaceCreateWithName($this->c->cast('void*[1]', $this->c->dlsym($cg, 'kCGColorSpaceSRGB'))[0])
            ?? throw new RuntimeException('kCGColorSpaceSRGB');
    }

    /** Whether the user leaves font smoothing on: AppKit turns it off when AppleFontSmoothing is 0 (mygo's fontSmoothing); unset, it is on. */
    private static function fontSmoothing(): bool
    {
        $defaults = ObjC::msg('p:', ObjC::cls('NSUserDefaults'), 'standardUserDefaults');
        $key = ObjC::str('AppleFontSmoothing');
        return ObjC::msg('p:p', $defaults, 'objectForKey:', $key) === null || ObjC::msg('l:p', $defaults, 'integerForKey:', $key) !== 0;
    }

    /** The sRGB gray of the relative luminance shade $shade stands for, a quarter a shade (mygo's shadeGray). */
    private static function shadeGray(int $shade): float
    {
        $y = $shade / 4;
        return $y <= 0.0031308 ? $y * 12.92 : 1.055 * $y ** (1 / 2.4) - 0.055;
    }

    /** Whether the glyphs of font $fontId are bitmaps in color (Apple Color Emoji), which rasterize() draws in their colors. */
    public function isColor(int $fontId): bool
    {
        $font = $this->runFonts[$fontId] ?? throw new RuntimeException("unknown font $fontId");
        return $this->colorFonts[$fontId] ??= ($this->c->CTFontGetSymbolicTraits($font) & self::TRAIT_COLOR_GLYPHS) !== 0;
    }

    /** The system font (or the fixed-pitch one) at $size with UI\Font style bits. */
    private function font(float $size, int $style)
    {
        $key = "$size:$style";
        if (isset($this->fonts[$key])) {
            return $this->fonts[$key];
        }
        $kind = $style & Font::MONO ? self::UI_FONT_USER_FIXED_PITCH : self::UI_FONT_SYSTEM;
        $font = $this->c->CTFontCreateUIFontForLanguage($kind, $size, null) ?? throw new RuntimeException('system font');
        $traits = ($style & Font::BOLD ? self::TRAIT_BOLD : 0) | ($style & Font::ITALIC ? self::TRAIT_ITALIC : 0);
        if ($traits !== 0) {
            // A font without the variant (no italic fixed-pitch, say) gives null: it stays as it is.
            $font = $this->c->CTFontCreateCopyWithSymbolicTraits($font, 0.0, null, $traits, $traits) ?? $font;
        }
        return $this->fonts[$key] = $font;
    }

    /**
     * Shapes $text at $size. Positions are in points from the pen origin on
     * the baseline, y up as CoreText gives them.
     *
     * carets maps each UTF-16 index at a glyph boundary to the x of the
     * caret before it; the end of the string maps to the width.
     *
     * @return array{width: float, ascent: float, descent: float, glyphs: list<array{font: int, glyph: int, x: float, y: float, index: int}>, carets: array<int, float>} index is the UTF-16 index of the glyph's cluster
     */
    public function shape(string $text, float $size, int $style = 0): array
    {
        $c = $this->c;
        $font = $this->font($size, $style);
        $str = $c->CFStringCreateWithCString(null, $text, self::UTF8) ?? throw new RuntimeException('CFString');
        $keys = $c->new('void*[1]'); $keys[0] = $this->fontKey;
        $values = $c->new('void*[1]'); $values[0] = $font;
        $attrs = $c->CFDictionaryCreate(null, $keys, $values, 1, $this->keyCallbacks, $this->valueCallbacks);
        $attributed = $c->CFAttributedStringCreate(null, $str, $attrs);
        $line = $c->CTLineCreateWithAttributedString($attributed);

        $asc = $c->new('double'); $desc = $c->new('double'); $lead = $c->new('double');
        $width = $c->CTLineGetTypographicBounds($line, FFI::addr($asc), FFI::addr($desc), FFI::addr($lead));

        $glyphs = [];
        $carets = [0 => 0.0];
        $runs = $c->CTLineGetGlyphRuns($line);
        $runCount = $c->CFArrayGetCount($runs);
        $range = $c->new('CFRange'); // {0, 0} means the whole run
        for ($r = 0; $r < $runCount; $r++) {
            $run = $c->CFArrayGetValueAtIndex($runs, $r);
            $n = $c->CTRunGetGlyphCount($run);
            if ($n === 0) {
                continue;
            }
            // Fallback fonts (PingFang for CJK, say) are new objects per line,
            // so fonts are told apart by CFHash, which equal fonts share.
            $runFont = $c->CFDictionaryGetValue($c->CTRunGetAttributes($run), $this->fontKey);
            $fontId = $c->CFHash($runFont);
            if (!isset($this->runFonts[$fontId])) {
                $this->runFonts[$fontId] = $c->CFRetain($runFont);
            }
            $ids = $c->new("CGGlyph[$n]");
            $pos = $c->new("CGPoint[$n]");
            $idx = $c->new("long[$n]");
            $c->CTRunGetGlyphs($run, $range, $ids);
            $c->CTRunGetPositions($run, $range, $pos);
            $c->CTRunGetStringIndices($run, $range, $idx);
            for ($i = 0; $i < $n; $i++) {
                $glyphs[] = ['font' => $fontId, 'glyph' => $ids[$i], 'x' => $pos[$i]->x, 'y' => $pos[$i]->y, 'index' => $idx[$i]];
                $carets[$idx[$i]] = $pos[$i]->x;
            }
        }
        $c->CFRelease($line);
        $c->CFRelease($attributed);
        $c->CFRelease($attrs);
        $c->CFRelease($str);
        $carets[self::utf16Length($text)] = $width;
        return ['width' => $width, 'ascent' => $asc->cdata, 'descent' => $desc->cdata, 'glyphs' => $glyphs, 'carets' => $carets];
    }

    private static function utf16Length(string $s): int
    {
        return intdiv(strlen(mb_convert_encoding($s, 'UTF-16LE', 'UTF-8')), 2);
    }

    /** The id rasterize() knows the CTFont at address $addr by, which another FFI instance made (TextEngine): retained once, by CFHash, as the runs' fonts. */
    public function adopt(int $addr): int
    {
        $font = $this->c->cast('void*', $addr);
        $id = $this->c->CFHash($font);
        if (!isset($this->runFonts[$id])) {
            $this->runFonts[$id] = $this->c->CFRetain($font);
        }
        return $id;
    }

    /**
     * Rasterizes one glyph at the backing scale, or at $scale pixels a
     * point. Returns null for glyphs
     * without ink (spaces). Offsets are in device pixels from the glyph
     * origin: ox to the right, oy up to the bitmap's bottom edge. The
     * bitmap (w*h bytes of coverage, top row first; for a color glyph,
     * $color, w*h premultiplied RGBA pixels in sRGB, as mygo's) is
     * Mem-allocated: free it with Mem::free.
     *
     * For text of $shade (Text::shadeOf, 0 to 3) the glyph is drawn with
     * font smoothing while the user leaves it on, as AppKit draws text and
     * mygo's: Core Graphics emboldens it the more the lighter the shade's
     * gray is, in a mask too. A $shade of -1 draws it without, as a
     * canvas's text. A $stroke (points) draws the glyph grown by it all
     * round, without smoothing, the mask an outline of the text paints
     * under it (Node::textOutline); a color glyph has none.
     *
     * @return array{w: int, h: int, ox: int, oy: int, rows: CData, addr: int, color: bool}|null
     */
    public function rasterize(int $fontId, int $glyph, ?float $scale = null, int $shade = -1, float $stroke = 0.0): ?array
    {
        $c = $this->c;
        $font = $this->runFonts[$fontId] ?? throw new RuntimeException("unknown font $fontId");
        $ids = $c->new('CGGlyph[1]'); $ids[0] = $glyph;
        $bounds = $c->new('CGRect[1]');
        $c->CTFontGetBoundingRectsForGlyphs($font, 0, $ids, $bounds, 1);
        $b = $bounds[0];
        if ($b->size->w <= 0 || $b->size->h <= 0) {
            return null;
        }
        $s = $scale ?? $this->scale;
        $color = $this->isColor($fontId);
        if ($stroke > 0 && $color) {
            return null; // a color glyph is its own picture: no outline of it
        }
        $smooth = $stroke <= 0 && $shade >= 0 && !$color && ($this->smooth ??= self::fontSmoothing());
        $pad = ($smooth ? 2 : 1) + (int)ceil($stroke * $s); // smoothing spreads a glyph up to a pixel further, an outline its width
        $x0 = (int)floor($b->origin->x * $s) - $pad;
        $y0 = (int)floor($b->origin->y * $s) - $pad;
        $w = (int)ceil(($b->origin->x + $b->size->w) * $s) + $pad - $x0;
        $h = (int)ceil(($b->origin->y + $b->size->h) * $s) + $pad - $y0;
        $bpp = $color ? 4 : 1;
        $ctx = ($color ? $c->CGBitmapContextCreate(null, $w, $h, 8, 4 * $w, $this->srgb, self::RGBA_PREMULTIPLIED) : $c->CGBitmapContextCreate(null, $w, $h, 8, $w, null, self::ALPHA_ONLY))
            ?? throw new RuntimeException('CGBitmapContextCreate');
        $c->CGContextSetShouldSmoothFonts($ctx, $smooth);
        if ($smooth) {
            $gray = self::shadeGray($shade);
            $c->CGContextSetRGBFillColor($ctx, $gray, $gray, $gray, 1.0); // the lighter, the bolder, as for text of that color
        }
        // CG coordinates run y-up, but the bitmap's first row in memory is
        // the top of the image, which is the order the atlas wants.
        $c->CGContextTranslateCTM($ctx, -$x0, -$y0);
        $c->CGContextScaleCTM($ctx, $s, $s);
        if ($stroke > 0) { // the glyph grown by $stroke all round: its outline filled and stroked twice as wide, joins round
            $path = $c->CTFontCreatePathForGlyph($font, $glyph, null);
            if ($path === null) {
                $c->CGContextRelease($ctx);
                return null;
            }
            $c->CGContextAddPath($ctx, $path);
            $c->CGContextSetLineWidth($ctx, 2 * $stroke);
            $c->CGContextSetLineJoin($ctx, 1); // kCGLineJoinRound
            $c->CGContextDrawPath($ctx, 3); // kCGPathFillStroke
            $c->CGPathRelease($path);
        } else {
            $origin = $c->new('CGPoint[1]');
            $c->CTFontDrawGlyphs($font, $ids, $origin, 1, $ctx);
        }
        $rows = Mem::alloc($bpp * $w * $h);
        $addr = Mem::addr($rows);
        Mem::copy($addr, Mem::addr($c->CGBitmapContextGetData($ctx)), $bpp * $w * $h);
        $c->CGContextRelease($ctx);
        return ['w' => $w, 'h' => $h, 'ox' => $x0, 'oy' => $y0, 'rows' => $rows, 'addr' => $addr, 'color' => $color];
    }

    /**
     * The outline of glyph $glyph of font $fontId in points from its
     * origin, y up, as line segments [x0, y0, x1, y1], its curves each in
     * 16 lines (mygo's glyphOutline); none for a glyph without one (a
     * bitmap's, a space).
     *
     * @return list<array{0: float, 1: float, 2: float, 3: float}>
     */
    public function outline(int $fontId, int $glyph): array
    {
        $c = $this->c;
        $font = $this->runFonts[$fontId] ?? throw new RuntimeException("unknown font $fontId");
        if ($this->applier === null) {
            $this->applier = $c->new('PathApplierHolder'); // assigning the closure makes the C-callable thunk
            $this->applier->fn = function ($info, $el): void {
                $pt = fn(int $i): array => [$el->points[$i]->x, $el->points[$i]->y];
                switch ($el->type) {
                    case 0: // move
                        [$this->atX, $this->atY] = $pt(0);
                        [$this->fromX, $this->fromY] = [$this->atX, $this->atY];
                        break;
                    case 1: // line
                        $this->lineTo(...$pt(0));
                        break;
                    case 2: // a quadratic curve, and
                    case 3: // a cubic one, in 16 lines
                        $cp = [[$this->atX, $this->atY], $pt(0), $pt(1)];
                        if ($el->type === 3) {
                            $cp[] = $pt(2);
                        }
                        for ($k = 1; $k <= 16; $k++) {
                            $this->lineTo(...self::bezier($cp, $k / 16));
                        }
                        break;
                    case 4: // close
                        $this->lineTo($this->fromX, $this->fromY);
                        break;
                }
            };
        }
        $path = $c->CTFontCreatePathForGlyph($font, $glyph, null);
        if ($path === null) {
            return [];
        }
        $this->segs = [];
        $c->CGPathApply($path, null, $this->applier->fn);
        $c->CGPathRelease($path);
        return $this->segs;
    }

    private function lineTo(float $x, float $y): void
    {
        $this->segs[] = [$this->atX, $this->atY, $x, $y];
        $this->atX = $x; $this->atY = $y;
    }

    /** The point at $t of the Bézier curve of control points $cp (mygo's bezier). @param list<array{0: float, 1: float}> $cp @return array{0: float, 1: float} */
    private static function bezier(array $cp, float $t): array
    {
        for ($n = count($cp) - 1; $n > 0; $n--) {
            for ($k = 0; $k < $n; $k++) {
                $cp[$k] = [$cp[$k][0] + ($cp[$k + 1][0] - $cp[$k][0]) * $t, $cp[$k][1] + ($cp[$k + 1][1] - $cp[$k][1]) * $t];
            }
        }
        return $cp[0];
    }
}
