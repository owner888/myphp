<?php
declare(strict_types=1);

namespace MyPHP\Native;

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
    private const ALPHA_ONLY = 7;            // kCGImageAlphaOnly

    public FFI $c;
    private $fontKey;         // kCTFontAttributeName
    private $keyCallbacks;    // &kCFTypeDictionaryKeyCallBacks
    private $valueCallbacks;  // &kCFTypeDictionaryValueCallBacks
    /** @var array<string, CData> CTFont by "size" */
    private array $fonts = [];
    /** @var array<int, CData> fonts runs used, retained, by CFHash */
    private array $runFonts = [];

    public function __construct(public float $scale)
    {
        $this->c = FFI::cdef("
            typedef struct { long location, length; } CFRange;
            typedef struct { double x, y; } CGPoint;
            typedef struct { double w, h; } CGSize;
            typedef struct { CGPoint origin; CGSize size; } CGRect;
            typedef unsigned short CGGlyph;
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
            double CTFontGetAscent(void*);
            double CTFontGetDescent(void*);
            CGRect CTFontGetBoundingRectsForGlyphs(void*, unsigned int, const CGGlyph*, CGRect*, long);
            void  CTFontDrawGlyphs(void*, const CGGlyph*, const CGPoint*, unsigned long, void*);
            void* CTLineCreateWithAttributedString(void*);
            void* CTLineGetGlyphRuns(void*);
            double CTLineGetTypographicBounds(void*, double*, double*, double*);
            long  CTRunGetGlyphCount(void*);
            void  CTRunGetGlyphs(void*, CFRange, CGGlyph*);
            void  CTRunGetPositions(void*, CFRange, CGPoint*);
            void* CTRunGetAttributes(void*);
            void* CGBitmapContextCreate(void*, unsigned long, unsigned long, unsigned long, unsigned long, void*, unsigned int);
            void* CGBitmapContextGetData(void*);
            void  CGContextRelease(void*);
            void  CGContextTranslateCTM(void*, double, double);
            void  CGContextScaleCTM(void*, double, double);
            void  CGContextSetShouldSmoothFonts(void*, bool);
        ", '/System/Library/Frameworks/CoreText.framework/CoreText');
        $lib = $this->c->dlopen('/System/Library/Frameworks/CoreText.framework/CoreText', 2);
        $cf = $this->c->dlopen('/System/Library/Frameworks/CoreFoundation.framework/CoreFoundation', 2);
        // kCTFontAttributeName is a CFStringRef variable: dlsym gives its address, the cast reads its value.
        $this->fontKey = $this->c->cast('void*[1]', $this->c->dlsym($lib, 'kCTFontAttributeName'))[0]
            ?? throw new RuntimeException('kCTFontAttributeName');
        $this->keyCallbacks = $this->c->dlsym($cf, 'kCFTypeDictionaryKeyCallBacks');
        $this->valueCallbacks = $this->c->dlsym($cf, 'kCFTypeDictionaryValueCallBacks');
    }

    private function font(float $size)
    {
        return $this->fonts["$size"] ??= $this->c->CTFontCreateUIFontForLanguage(self::UI_FONT_SYSTEM, $size, null)
            ?? throw new RuntimeException('system font');
    }

    /**
     * Shapes $text at $size. Positions are in points from the pen origin on
     * the baseline, y up as CoreText gives them.
     *
     * @return array{width: float, ascent: float, descent: float, glyphs: list<array{font: int, glyph: int, x: float, y: float}>}
     */
    public function shape(string $text, float $size): array
    {
        $c = $this->c;
        $font = $this->font($size);
        $str = $c->CFStringCreateWithCString(null, $text, self::UTF8) ?? throw new RuntimeException('CFString');
        $keys = $c->new('void*[1]'); $keys[0] = $this->fontKey;
        $values = $c->new('void*[1]'); $values[0] = $font;
        $attrs = $c->CFDictionaryCreate(null, $keys, $values, 1, $this->keyCallbacks, $this->valueCallbacks);
        $attributed = $c->CFAttributedStringCreate(null, $str, $attrs);
        $line = $c->CTLineCreateWithAttributedString($attributed);

        $asc = $c->new('double'); $desc = $c->new('double'); $lead = $c->new('double');
        $width = $c->CTLineGetTypographicBounds($line, FFI::addr($asc), FFI::addr($desc), FFI::addr($lead));

        $glyphs = [];
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
            $c->CTRunGetGlyphs($run, $range, $ids);
            $c->CTRunGetPositions($run, $range, $pos);
            for ($i = 0; $i < $n; $i++) {
                $glyphs[] = ['font' => $fontId, 'glyph' => $ids[$i], 'x' => $pos[$i]->x, 'y' => $pos[$i]->y];
            }
        }
        $c->CFRelease($line);
        $c->CFRelease($attributed);
        $c->CFRelease($attrs);
        $c->CFRelease($str);
        return ['width' => $width, 'ascent' => $asc->cdata, 'descent' => $desc->cdata, 'glyphs' => $glyphs];
    }

    /**
     * Rasterizes one glyph at the backing scale. Returns null for glyphs
     * without ink (spaces). Offsets are in device pixels from the glyph
     * origin: ox to the right, oy up to the bitmap's bottom edge. The
     * bitmap (w*h bytes, top row first) is Mem-allocated: free it with Mem::free.
     *
     * @return array{w: int, h: int, ox: int, oy: int, rows: CData, addr: int}|null
     */
    public function rasterize(int $fontId, int $glyph): ?array
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
        $s = $this->scale;
        $x0 = (int)floor($b->origin->x * $s) - 1;
        $y0 = (int)floor($b->origin->y * $s) - 1;
        $w = (int)ceil(($b->origin->x + $b->size->w) * $s) + 1 - $x0;
        $h = (int)ceil(($b->origin->y + $b->size->h) * $s) + 1 - $y0;
        $ctx = $c->CGBitmapContextCreate(null, $w, $h, 8, $w, null, self::ALPHA_ONLY)
            ?? throw new RuntimeException('CGBitmapContextCreate');
        $c->CGContextSetShouldSmoothFonts($ctx, false);
        // CG coordinates run y-up, but the bitmap's first row in memory is
        // the top of the image, which is the order the atlas wants.
        $c->CGContextTranslateCTM($ctx, -$x0, -$y0);
        $c->CGContextScaleCTM($ctx, $s, $s);
        $origin = $c->new('CGPoint[1]');
        $c->CTFontDrawGlyphs($font, $ids, $origin, 1, $ctx);
        $rows = Mem::alloc($w * $h);
        $addr = Mem::addr($rows);
        Mem::copy($addr, Mem::addr($c->CGBitmapContextGetData($ctx)), $w * $h);
        $c->CGContextRelease($ctx);
        return ['w' => $w, 'h' => $h, 'ox' => $x0, 'oy' => $y0, 'rows' => $rows, 'addr' => $addr];
    }
}
