<?php
declare(strict_types=1);

namespace MyPHP\Native;

use FFI;
use FFI\CData;
use MyPHP\Text\{Engine, Font, Glyph, Span, Style};
use RuntimeException;

/**
 * Core Text lays out paragraphs (mygo's text engine of macOS): a
 * typesetter finds the fonts, falls back to others for what a font lacks,
 * shapes, and suggests line breaks. The system font comes from NSFont,
 * which knows its weights; named families from font descriptors. Its
 * fonts' glyphs rasterize through CoreText::rasterize, by their ids.
 */
final class TextEngine implements Engine
{
    private const ITALIC_TRAIT = 1 << 0, COLOR_GLYPHS_TRAIT = 1 << 13;
    private const RUN_RTL = 1 << 0;
    private const BASE_WRITING_DIRECTION = 13; // kCTParagraphStyleSpecifierBaseWritingDirection
    private const NUMBER_SINT32 = 3, NUMBER_SINT64 = 4, NUMBER_FLOAT64 = 6;
    private const UTF8 = 0x08000100;

    private FFI $c;
    /** @var array<string, CData> CF constants by name */
    private array $k = [];
    /** @var array{0: CData, 1: CData} the paragraph styles, left to right and right to left */
    private array $styles;
    /** The attribute marking the runes of italic spans in a text that is not italic, so that Core Text ends a run where italics start or stop even in one font (mygo's italic). */
    private CData $italic;
    /** @var array<string, CData> the CTFont of each style, owned */
    private array $primary = [];
    /** @var array<int, Font> the Fonts of CTFonts, by address */
    private array $native = [];
    /** @var array<int, float> the advance of a no-break space in each CTFont, by address: what an inline object's rune is shaped as */
    private array $spaceAdvance = [];
    /** @var array<int, Font> fonts by their slanted copies, for italics a font lacks */
    private array $oblique = [];

    private CData $slot, $slotInt;
    /** @var array<string, list<array{desc: CData, weight: float, italic: bool, mono: bool}>> the faces the app registered, by lowercased family: the font's own and the name it gave (mygo's registered) */
    private array $registered = [];
    /** @var list<string> the families registered, as listed (mygo's names) */
    private array $names = [];
    /** @var ?list<array{0: string, 1: bool}> the system's families and whether each is monospaced, listed once until a registration */
    private ?array $systemFamilies = null;
    private const MONO_TRAIT = 1 << 10;

    public function __construct(private CoreText $ct)
    {
        $this->c = FFI::cdef('
            typedef struct { long location, length; } CFRange;
            typedef struct { double x, y; } CGPoint;
            typedef struct { double w, h; } CGSize;
            typedef struct { double a, b, c, d, tx, ty; } CGAffineTransform;
            typedef struct { unsigned int spec; unsigned long size; const void* value; } CTParagraphStyleSetting;
            typedef unsigned short CGGlyph;
            void* dlopen(const char*, int);
            void* dlsym(void*, const char*);
            void  CFRelease(void*);
            void* CFRetain(void*);
            void* CFStringCreateWithCharacters(void*, const unsigned short*, long);
            void* CFStringCreateWithCString(void*, const char*, unsigned int);
            void* CFDictionaryCreate(void*, const void**, const void**, long, const void*, const void*);
            void* CFDictionaryGetValue(void*, const void*);
            void* CFArrayCreate(void*, const void**, long, const void*);
            long  CFArrayGetCount(void*);
            void* CFArrayGetValueAtIndex(void*, long);
            void* CFSetCreate(void*, const void**, long, const void*);
            void* CFNumberCreate(void*, long, const void*);
            void* CFAttributedStringCreate(void*, void*, void*);
            void* CFAttributedStringCreateMutableCopy(void*, long, void*);
            void  CFAttributedStringSetAttribute(void*, CFRange, void*, void*);
            void* CTParagraphStyleCreate(const CTParagraphStyleSetting*, unsigned long);
            void* CTFontCreateUIFontForLanguage(unsigned int, double, void*);
            void* CTFontCreateWithFontDescriptor(void*, double, const CGAffineTransform*);
            void* CTFontCreateCopyWithSymbolicTraits(void*, double, const CGAffineTransform*, unsigned int, unsigned int);
            void* CTFontCreateCopyWithAttributes(void*, double, const CGAffineTransform*, void*);
            void* CTFontDescriptorCreateWithAttributes(void*);
            void* CTFontDescriptorCreateMatchingFontDescriptor(void*, void*);
            double CTFontGetAscent(void*);
            double CTFontGetDescent(void*);
            double CTFontGetLeading(void*);
            double CTFontGetSize(void*);
            double CTFontGetUnderlinePosition(void*);
            double CTFontGetUnderlineThickness(void*);
            double CTFontGetXHeight(void*);
            unsigned int CTFontGetSymbolicTraits(void*);
            CGAffineTransform CTFontGetMatrix(void*);
            void* CTTypesetterCreateWithAttributedString(void*);
            long  CTTypesetterSuggestLineBreak(void*, long, double);
            void* CTTypesetterCreateLine(void*, CFRange);
            void* CTLineGetGlyphRuns(void*);
            CFRange CTLineGetStringRange(void*);
            long  CTRunGetGlyphCount(void*);
            CFRange CTRunGetStringRange(void*);
            unsigned int CTRunGetStatus(void*);
            void* CTRunGetAttributes(void*);
            void  CTRunGetGlyphs(void*, CFRange, CGGlyph*);
            bool  CTFontGetGlyphsForCharacters(void*, const unsigned short*, CGGlyph*, long);
            double CTFontGetAdvancesForGlyphs(void*, unsigned int, const CGGlyph*, CGSize*, long);
            void  CTRunGetPositions(void*, CFRange, CGPoint*);
            void  CTRunGetAdvances(void*, CFRange, CGSize*);
            void  CTRunGetStringIndices(void*, CFRange, long*);
            void* CFDataCreate(void*, const unsigned char*, long);
            bool  CFNumberGetValue(void*, long, void*);
            bool  CFStringGetCString(void*, char*, long, unsigned int);
            void* CTFontManagerCreateFontDescriptorsFromData(void*);
            void* CTFontManagerCopyAvailableFontFamilyNames(void);
            void* CTFontDescriptorCopyAttribute(void*, void*);
        ', '/System/Library/Frameworks/CoreText.framework/CoreText');
        $text = $this->c->dlopen('/System/Library/Frameworks/CoreText.framework/CoreText', 2);
        $cf = $this->c->dlopen('/System/Library/Frameworks/CoreFoundation.framework/CoreFoundation', 2);
        $value = fn($lib, string $name) => $this->c->cast('void*[1]', $this->c->dlsym($lib, $name) ?? throw new RuntimeException($name))[0];
        foreach (['kCTFontAttributeName', 'kCTParagraphStyleAttributeName', 'kCTFontFamilyNameAttribute', 'kCTFontTraitsAttribute', 'kCTFontWeightTrait',
            'kCTFontSymbolicTrait', 'kCTTrackingAttributeName', 'kCTKernAttributeName', 'kCTLanguageAttributeName', 'kCTFontFeatureSettingsAttribute',
            'kCTFontOpenTypeFeatureTag', 'kCTFontOpenTypeFeatureValue', 'kCTFontCascadeListAttribute'] as $name) {
            $this->k[$name] = $value($text, $name);
        }
        foreach (['kCFTypeDictionaryKeyCallBacks', 'kCFTypeDictionaryValueCallBacks', 'kCFTypeSetCallBacks', 'kCFTypeArrayCallBacks'] as $name) {
            $this->k[$name] = $this->c->dlsym($cf, $name) ?? throw new RuntimeException($name);
        }
        $styles = [];
        foreach ([0, 1] as $dir) { // kCTWritingDirectionLeftToRight, RightToLeft
            $d = $this->c->new('signed char'); $d->cdata = $dir;
            $s = $this->c->new('CTParagraphStyleSetting');
            $s->spec = self::BASE_WRITING_DIRECTION; $s->size = 1; $s->value = FFI::addr($d);
            $styles[] = $this->c->CTParagraphStyleCreate(FFI::addr($s), 1);
        }
        $this->styles = $styles;
        $this->italic = $this->str('MyPHPItalic');
        $this->slot = $this->c->new('void*[1]');
        $this->slotInt = $this->c->cast('unsigned long[1]', $this->slot);
    }

    private function str(string $s): CData
    {
        return $this->c->CFStringCreateWithCString(null, $s, self::UTF8) ?? throw new RuntimeException('CFString');
    }

    private function addr(?CData $p): int
    {
        if ($p === null) {
            return 0;
        }
        $this->slot[0] = $p; // the pointer itself, read as an integer (as ObjC::addr)
        return $this->slotInt[0];
    }

    /** @param list<CData> $keys @param list<CData> $values */
    private function dict(array $keys, array $values): CData
    {
        $n = count($keys);
        $k = $this->c->new("void*[$n]"); $v = $this->c->new("void*[$n]");
        foreach ($keys as $i => $key) { $k[$i] = $key; $v[$i] = $values[$i]; }
        return $this->c->CFDictionaryCreate(null, $k, $v, $n, $this->k['kCFTypeDictionaryKeyCallBacks'], $this->k['kCFTypeDictionaryValueCallBacks']);
    }

    private function float(float $v): CData
    {
        $d = $this->c->new('double'); $d->cdata = $v;
        return $this->c->CFNumberCreate(null, self::NUMBER_FLOAT64, FFI::addr($d));
    }

    /** A CSS weight on NSFont's (and Core Text's) scale, from -1 to 1 (mygo's nsWeight). */
    public static function nsWeight(int $w): float
    {
        $steps = [-0.8, -0.6, -0.4, 0, 0.23, 0.3, 0.4, 0.56, 0.62];
        $f = (max(100, min($w, 900)) - 100) / 100;
        $i = min((int)$f, count($steps) - 2);
        return $steps[$i] + ($steps[$i + 1] - $steps[$i]) * ($f - $i);
    }

    /** The system font, or its monospaced kind, owned (mygo's systemFont). */
    private function systemFont(float $size, int $weight, bool $italic, bool $mono): ?CData
    {
        $sel = $mono ? 'monospacedSystemFontOfSize:weight:' : 'systemFontOfSize:weight:';
        $ns = ObjC::msg('p:dd', ObjC::cls('NSFont'), $sel, $size, self::nsWeight($weight));
        $font = $ns === null ? null : $this->c->CFRetain($this->c->cast('void*', ObjC::addr($ns)));
        if ($font === null) {
            $font = $mono ? $this->named('Menlo', $size, $weight, false) : $this->c->CTFontCreateUIFontForLanguage(2, $size, null);
        }
        if ($font !== null && $italic) {
            $f = $this->c->CTFontCreateCopyWithSymbolicTraits($font, 0, null, self::ITALIC_TRAIT, self::ITALIC_TRAIT);
            if ($f !== null) {
                $this->c->CFRelease($font);
                $font = $f;
            }
        }
        return $font;
    }

    /** A font of a family the system has, owned, or null. */
    private function named(string $family, float $size, int $weight, bool $italic): ?CData
    {
        $desc = $this->namedDesc($family, $weight, $italic);
        if ($desc === null) {
            return null;
        }
        $font = $this->c->CTFontCreateWithFontDescriptor($desc, $size, null);
        $this->c->CFRelease($desc);
        return $font;
    }

    /** The descriptor of the face of a family the system has that best matches a weight and italics, owned, or null. */
    private function namedDesc(string $family, int $weight, bool $italic): ?CData
    {
        $name = $this->str($family);
        $sym = $this->c->new('int'); $sym->cdata = $italic ? self::ITALIC_TRAIT : 0;
        $w = $this->float(self::nsWeight($weight));
        $s = $this->c->CFNumberCreate(null, self::NUMBER_SINT32, FFI::addr($sym));
        $traits = $this->dict([$this->k['kCTFontWeightTrait'], $this->k['kCTFontSymbolicTrait']], [$w, $s]);
        $attrs = $this->dict([$this->k['kCTFontFamilyNameAttribute'], $this->k['kCTFontTraitsAttribute']], [$name, $traits]);
        $desc = $this->c->CTFontDescriptorCreateWithAttributes($attrs);
        $match = null;
        if ($desc !== null) {
            $mandatory = $this->c->new('void*[1]'); $mandatory[0] = $this->k['kCTFontFamilyNameAttribute'];
            $set = $this->c->CFSetCreate(null, $mandatory, 1, $this->k['kCFTypeSetCallBacks']);
            $match = $this->c->CTFontDescriptorCreateMatchingFontDescriptor($desc, $set);
            $this->c->CFRelease($set);
            $this->c->CFRelease($desc);
        }
        foreach ([$attrs, $traits, $s, $w, $name] as $o) { $this->c->CFRelease($o); }
        return $match;
    }

    /**
     * Adds a TrueType or OpenType font, or collection, from $data, under
     * $family, or its own family name for '' (mygo's register): the faces
     * are kept by their descriptors, which styles of the family match by
     * weight and italics.
     */
    public function register(string $data, string $family): void
    {
        if ($data === '') {
            throw new RuntimeException('no font data');
        }
        $d = $this->c->CFDataCreate(null, $data, strlen($data));
        $descs = $this->c->CTFontManagerCreateFontDescriptorsFromData($d);
        $this->c->CFRelease($d);
        $n = $descs === null ? 0 : $this->c->CFArrayGetCount($descs);
        if ($n === 0) {
            if ($descs !== null) { $this->c->CFRelease($descs); }
            throw new RuntimeException('cannot add font: not a font Core Text reads');
        }
        for ($i = 0; $i < $n; $i++) {
            $desc = $this->c->CFRetain($this->c->CFArrayGetValueAtIndex($descs, $i));
            $face = ['desc' => $desc, 'weight' => 0.0, 'italic' => false, 'mono' => false];
            $name = '';
            if (($s = $this->c->CTFontDescriptorCopyAttribute($desc, $this->k['kCTFontFamilyNameAttribute'])) !== null) {
                $name = $this->string($s);
                $this->c->CFRelease($s);
            }
            if (($traits = $this->c->CTFontDescriptorCopyAttribute($desc, $this->k['kCTFontTraitsAttribute'])) !== null) {
                if (($w = $this->c->CFDictionaryGetValue($traits, $this->k['kCTFontWeightTrait'])) !== null) {
                    $v = $this->c->new('double'); $this->c->CFNumberGetValue($w, self::NUMBER_FLOAT64, FFI::addr($v)); $face['weight'] = $v->cdata;
                }
                if (($t = $this->c->CFDictionaryGetValue($traits, $this->k['kCTFontSymbolicTrait'])) !== null) {
                    $v = $this->c->new('long long'); $this->c->CFNumberGetValue($t, self::NUMBER_SINT64, FFI::addr($v));
                    $face['italic'] = ($v->cdata & self::ITALIC_TRAIT) !== 0; $face['mono'] = ($v->cdata & self::MONO_TRAIT) !== 0;
                }
                $this->c->CFRelease($traits);
            }
            foreach (array_unique(array_filter([$name, $family])) as $f) {
                $this->registered[strtolower($f)][] = $face;
            }
            $this->names[] = $family !== '' ? $family : $name; // listed under the name the app gave it, which it uses, or its own
        }
        $this->c->CFRelease($descs);
        foreach ($this->primary as $f) { if ($f !== null) { $this->c->CFRelease($f); } }
        $this->primary = []; // styles find their fonts anew
        $this->systemFamilies = null;
    }

    /** The families the app registered, then the system's, each with whether it is monospaced (mygo's appFamilies, systemFamilies): the system's listed once, until a registration. @return array{0: list<array{0: string, 1: bool}>, 1: list<array{0: string, 1: bool}>} */
    public function fontFamilies(): array
    {
        if ($this->systemFamilies === null) {
            $this->systemFamilies = [];
            $arr = $this->c->CTFontManagerCopyAvailableFontFamilyNames();
            for ($i = 0, $n = $arr === null ? 0 : $this->c->CFArrayGetCount($arr); $i < $n; $i++) {
                $name = $this->string($this->c->CFArrayGetValueAtIndex($arr, $i));
                if ($name === '' || $name[0] === '.') { continue; } // the system's hidden families
                $desc = $this->namedDesc($name, 400, false);
                $mono = false;
                if ($desc !== null && ($traits = $this->c->CTFontDescriptorCopyAttribute($desc, $this->k['kCTFontTraitsAttribute'])) !== null) {
                    if (($t = $this->c->CFDictionaryGetValue($traits, $this->k['kCTFontSymbolicTrait'])) !== null) {
                        $v = $this->c->new('long long'); $this->c->CFNumberGetValue($t, self::NUMBER_SINT64, FFI::addr($v)); $mono = ($v->cdata & self::MONO_TRAIT) !== 0;
                    }
                    $this->c->CFRelease($traits);
                }
                if ($desc !== null) { $this->c->CFRelease($desc); }
                $this->systemFamilies[] = [$name, $mono];
            }
            if ($arr !== null) { $this->c->CFRelease($arr); }
        }
        $app = [];
        foreach ($this->names as $name) {
            $mono = false;
            foreach ($this->registered[strtolower($name)] ?? [] as $f) { $mono = $mono || $f['mono']; }
            $app[] = [$name, $mono];
        }
        return [$app, $this->systemFamilies];
    }

    /** A CFString as PHP's. */
    private function string(CData $s): string
    {
        $buf = $this->c->new('char[1024]');
        return $this->c->CFStringGetCString($s, $buf, 1024, self::UTF8) ? FFI::string($buf) : '';
    }

    /** The descriptor of the registered face of $faces that best matches a weight and italics, not owned (mygo's registeredDesc). */
    private static function registeredDesc(array $faces, int $weight, bool $italic): CData
    {
        $want = self::nsWeight($weight);
        $best = null; $score = INF;
        foreach ($faces as $f) {
            $s = abs($f['weight'] - $want) + ($f['italic'] !== $italic ? 4 : 0);
            if ($s < $score) { $best = $f; $score = $s; }
        }
        return $best['desc'];
    }

    /** The family names of a comma-separated list, without quotes. @return list<string> */
    private static function families(string $family): array
    {
        $out = [];
        foreach (explode(',', $family) as $f) {
            $f = trim(trim($f), '"\'');
            if ($f !== '') { $out[] = $f; }
        }
        return $out;
    }

    /** The generic family a name stands for (mygo's generic): "" for none. */
    private static function generic(string $family): string
    {
        return match (strtolower($family)) {
            '', 'system-ui', 'ui-sans-serif', '-apple-system', 'blinkmacsystemfont' => 'system-ui',
            'sans-serif' => 'sans-serif',
            'serif', 'ui-serif' => 'serif',
            'monospace', 'ui-monospace' => 'monospace',
            default => '',
        };
    }

    /** The CTFont of a style (mygo's ctFont). */
    private function ctFont(Style $style): ?CData
    {
        $key = $style->family . "\0" . $style->fontSize() . "\0" . $style->weight() . "\0" . (int)$style->italic . "\0" . $style->features;
        if (isset($this->primary[$key])) {
            return $this->primary[$key];
        }
        if ($style->features !== '') {
            // The font without the features, with them.
            $font = $this->ctFont($style->with(['features' => '']));
            $with = $font === null ? null : $this->withFeatures($font, $style->features);
            if ($with === null && $font !== null) {
                $this->c->CFRetain($font);
                $with = $font;
            }
            return $this->primary[$key] = $with;
        }
        $size = $style->fontSize(); $weight = $style->weight(); $italic = $style->italic;
        $font = null;
        $families = self::families($style->family);
        $chosen = -1;
        foreach ($families as $i => $family) {
            $chosen = $i;
            $font = match (self::generic($family)) {
                'system-ui', 'sans-serif' => $this->systemFont($size, $weight, $italic, false),
                'monospace' => $this->systemFont($size, $weight, $italic, true),
                'serif' => $this->named('Times New Roman', $size, $weight, $italic) ?? $this->named('Times', $size, $weight, $italic) ?? $this->named('Georgia', $size, $weight, $italic),
                default => isset($this->registered[strtolower($family)])
                    ? $this->c->CTFontCreateWithFontDescriptor(self::registeredDesc($this->registered[strtolower($family)], $weight, $italic), $size, null)
                    : $this->named($family, $size, $weight, $italic),
            };
            if ($font !== null) {
                break;
            }
        }
        if ($font === null) {
            $font = $this->systemFont($size, $weight, $italic, false);
        } else {
            // The families after it come before the system's for what it lacks.
            $descs = [];
            foreach (array_slice($families, $chosen + 1) as $family) {
                if (self::generic($family) === '' && isset($this->registered[strtolower($family)])) {
                    $descs[] = $this->c->CFRetain(self::registeredDesc($this->registered[strtolower($family)], $weight, $italic));
                } elseif (self::generic($family) === '' && ($d = $this->namedDesc($family, $weight, $italic)) !== null) {
                    $descs[] = $d;
                }
            }
            if ($descs !== []) {
                $n = count($descs);
                $arr = $this->c->new("void*[$n]");
                foreach ($descs as $i => $d) { $arr[$i] = $d; }
                $array = $this->c->CFArrayCreate(null, $arr, $n, $this->k['kCFTypeArrayCallBacks']);
                $attrs = $this->dict([$this->k['kCTFontCascadeListAttribute']], [$array]);
                $desc = $this->c->CTFontDescriptorCreateWithAttributes($attrs);
                $with = $this->c->CTFontCreateCopyWithAttributes($font, 0, null, $desc);
                if ($with !== null) {
                    $this->c->CFRelease($font);
                    $font = $with;
                }
                foreach ([...$descs, $array, $attrs, $desc] as $o) { $this->c->CFRelease($o); }
            }
        }
        return $this->primary[$key] = $font;
    }

    /** A copy of $font with OpenType features, owned, or null. */
    private function withFeatures(CData $font, string $list): ?CData
    {
        $settings = [];
        foreach (explode(',', $list) as $item) {
            [$tag, $value] = array_pad(explode('=', trim($item), 2), 2, null);
            $tag = trim((string)$tag);
            $v = 1;
            if ($value !== null) {
                if (!ctype_digit(trim($value))) { continue; }
                $v = (int)trim($value);
            }
            if (strlen($tag) !== 4 || preg_match('/[^\x20-\x7e]/', $tag) === 1) {
                continue;
            }
            $t = $this->str($tag);
            $n = $this->c->new('long long'); $n->cdata = $v;
            $num = $this->c->CFNumberCreate(null, self::NUMBER_SINT64, FFI::addr($n));
            $settings[] = $this->dict([$this->k['kCTFontOpenTypeFeatureTag'], $this->k['kCTFontOpenTypeFeatureValue']], [$t, $num]);
            $this->c->CFRelease($t); $this->c->CFRelease($num);
        }
        if ($settings === []) {
            return null;
        }
        $n = count($settings);
        $arr = $this->c->new("void*[$n]");
        foreach ($settings as $i => $s) { $arr[$i] = $s; }
        $array = $this->c->CFArrayCreate(null, $arr, $n, $this->k['kCFTypeArrayCallBacks']);
        foreach ($settings as $s) { $this->c->CFRelease($s); }
        $attrs = $this->dict([$this->k['kCTFontFeatureSettingsAttribute']], [$array]);
        $this->c->CFRelease($array);
        $desc = $this->c->CTFontDescriptorCreateWithAttributes($attrs);
        $this->c->CFRelease($attrs);
        $with = $this->c->CTFontCreateCopyWithAttributes($font, 0, null, $desc); // size 0 keeps the font's
        $this->c->CFRelease($desc);
        return $with;
    }

    public function font(Style $style): ?Font
    {
        $f = $this->ctFont($style);
        return $f === null ? null : $this->fontOf($f);
    }

    public function outline(Font $font, int $glyph): array
    {
        return $this->ct->outline($font->id, $glyph);
    }

    /** The Font of a CTFont, registered for rasterizing. */
    private function fontOf(CData $font): Font
    {
        $a = $this->addr($font);
        if (isset($this->native[$a])) {
            return $this->native[$a];
        }
        $this->c->CFRetain($font);
        $id = $this->ct->adopt($a);
        $c = $this->c;
        [$uc, $ut] = self::underline($c->CTFontGetUnderlinePosition($font), $c->CTFontGetUnderlineThickness($font), $c->CTFontGetAscent($font), $c->CTFontGetDescent($font));
        return $this->native[$a] = new Font($c->CTFontGetSize($font), $c->CTFontGetAscent($font), $c->CTFontGetDescent($font), $c->CTFontGetLeading($font), $id,
            $uc, $ut, -$c->CTFontGetXHeight($font) / 2, $c->CTFontGetUnderlineThickness($font));
    }

    /** Where Core Text centers the underline of a font below the baseline, and how thick it draws it, in points (mygo's ctUnderline). @return array{0: float, 1: float} */
    private static function underline(float $pos, float $th, float $asc, float $desc): array
    {
        $d = $desc < 2 ? ($asc + $desc) / 4 : $desc;
        $reach = min($d * 5.3636991028295373, $asc + $desc);
        if ($pos >= 0) { // no underline position
            $pos = -0.08805546253922189 * $reach;
        }
        $p = -$pos; $t = $th;
        if ($d >= 2 && $th > 0.35) {
            $t = ceil($th);
            if ($t >= $d || $d <= 4 && $t >= 3 || $d <= 2.5 && $t >= 2) {
                $t--;
            }
            $p = ((int)$t % 2 === 0) ? floor($p + 0.5) : floor($p) + 0.5;
            if ($p < 1.5 || $p == 1.5 && $d > 4) {
                $p++;
            }
        }
        if ($d > 0) {
            $p = min($p, floor($d) - $t / 2);
        }
        $p = max($p, ceil($th) + $t / 2);
        if ($t <= 0) {
            $t = $reach * 0.044027731269610945;
        }
        return [$p, $t];
    }

    /** The Font of a run's CTFont, slanted when the run is italic and the font has no italic face (mygo's runFont). */
    private function runFont(CData $font, bool $italic): Font
    {
        $base = $this->fontOf($font);
        if (!$italic) {
            return $base;
        }
        $k = spl_object_id($base);
        if (isset($this->oblique[$k])) {
            return $this->oblique[$k];
        }
        $f = $base;
        if (($this->c->CTFontGetSymbolicTraits($font) & (self::ITALIC_TRAIT | self::COLOR_GLYPHS_TRAIT)) === 0) {
            $m = $this->c->CTFontGetMatrix($font);
            if ($m->c == 0) {
                $m->c = tan(14 * M_PI / 180); // slanted by 14°, as WebKit does
                $with = $this->c->CTFontCreateCopyWithAttributes($font, 0, FFI::addr($m), null);
                if ($with !== null) {
                    $f = $this->fontOf($with);
                    $this->c->CFRelease($with);
                }
            }
        }
        return $this->oblique[$k] = $f;
    }

    public function shape(array $runes, Style $style, array $spans, float $width, bool $rtl, bool $wholeWords): array
    {
        $c = $this->c;
        $font = $this->ctFont($style);
        if ($font === null || $runes === []) {
            return [];
        }
        // The text in UTF-16, with the rune of each code unit and of the end; an inline object's rune a no-break space, which styleSpans widens to the object.
        $objects = [];
        $from = 0;
        foreach ($spans as $sp) {
            if ($sp->objectWidth > 0) { for ($i = $from; $i < min($sp->end, count($runes)); $i++) { $objects[$i] = true; } }
            $from = max($from, $sp->end);
        }
        $u16 = []; $index = []; $at = [0];
        foreach ($runes as $i => $r) {
            if (isset($objects[$i])) {
                $u16[] = 0x00A0; $index[] = $i;
            } elseif ($r >= 0x10000 && $r <= 0x10FFFF) {
                $r -= 0x10000;
                $u16[] = 0xD800 + ($r >> 10); $u16[] = 0xDC00 + ($r & 0x3FF);
                $index[] = $i; $index[] = $i;
            } else {
                $u16[] = $r > 0xFFFF || $r >= 0xD800 && $r < 0xE000 ? 0xFFFD : $r;
                $index[] = $i;
            }
            $at[] = count($u16);
        }
        $index[] = count($runes);
        $n = count($u16);
        $chars = $c->new("unsigned short[$n]");
        foreach ($u16 as $i => $u) { $chars[$i] = $u; }
        $str = $c->CFStringCreateWithCharacters(null, $chars, $n);
        $keys = [$this->k['kCTFontAttributeName'], $this->k['kCTParagraphStyleAttributeName']];
        $values = [$font, $this->styles[$rtl ? 1 : 0]];
        $owned = [];
        if ($style->lang !== '') {
            // The language picks the glyphs of a font that has several, as Han characters in Chinese and Japanese, and the fonts Core Text falls back to.
            $keys[] = $this->k['kCTLanguageAttributeName']; $values[] = $owned[] = $this->str($style->lang);
        }
        if ($style->letterSpacing != 0) {
            $keys[] = $this->k['kCTTrackingAttributeName']; $values[] = $owned[] = $this->float($style->letterSpacing);
        }
        if (self::noKerning($style->features)) {
            $keys[] = $this->k['kCTKernAttributeName']; $values[] = $owned[] = $this->float(0);
        }
        $attrs = $this->dict($keys, $values);
        $attributed = $c->CFAttributedStringCreate(null, $str, $attrs);
        if ($spans !== []) {
            $styled = $this->styleSpans($attributed, $style, $spans, $at, count($runes));
            $c->CFRelease($attributed);
            $attributed = $styled;
        }
        $typesetter = $c->CTTypesetterCreateWithAttributedString($attributed);
        $lines = [];
        if ($typesetter !== null) {
            $breaks = $wholeWords && $width > 0 ? \MyPHP\Text\LineBreaks::at($runes) : null;
            $range = $c->new('CFRange');
            for ($start = 0; $start < $n;) {
                $count = $n - $start;
                if ($width > 0) {
                    $count = max($c->CTTypesetterSuggestLineBreak($typesetter, $start, $width), 1);
                    // Core Text breaks a word that does not fit a line; carry on to the next break instead.
                    if ($breaks !== null) {
                        $end = min($start + $count, $n);
                        while ($end < $n && ($index[$end] === $index[$end - 1] || !isset($breaks[$index[$end]]))) { $end++; }
                        $count = $end - $start;
                    }
                }
                $range->location = $start; $range->length = $count;
                $line = $c->CTTypesetterCreateLine($typesetter, $range);
                if ($line === null) {
                    break;
                }
                $lines[] = $this->line($line, $index, $n, $style->italic);
                $c->CFRelease($line);
                $start += $count;
            }
            $c->CFRelease($typesetter);
        }
        $c->CFRelease($attributed);
        $c->CFRelease($attrs);
        $c->CFRelease($str);
        foreach ($owned as $o) { $c->CFRelease($o); }
        return $lines;
    }

    /** Whether features turn kerning off, which Core Text does by a kern of 0 rather than by the feature. */
    private static function noKerning(string $list): bool
    {
        foreach (explode(',', $list) as $f) {
            if (preg_replace('/\s+/', '', $f) === 'kern=0') {
                return true;
            }
        }
        return false;
    }

    /**
     * A copy of the attributed string of a text of $style with the font and
     * tracking of each span over its runes, owned (mygo's styleSpans).
     *
     * @param list<Span> $spans
     * @param list<int> $at the UTF-16 unit each rune starts at, and the end
     */
    private function styleSpans(CData $attributed, Style $style, array $spans, array $at, int $runes): CData
    {
        $c = $this->c;
        $styled = $c->CFAttributedStringCreateMutableCopy(null, 0, $attributed);
        $r = $c->new('CFRange');
        $from = 0;
        foreach ($spans as $sp) {
            $to = max($from, min($sp->end, $runes));
            $r->location = $at[$from]; $r->length = $at[$to] - $at[$from];
            $from = $to;
            if ($r->length === 0) {
                continue;
            }
            $s = $sp->style($style);
            $font = $this->ctFont($s);
            if ($font !== null) {
                $c->CFAttributedStringSetAttribute($styled, $r, $this->k['kCTFontAttributeName'], $font);
            }
            if ($s->italic && !$style->italic) {
                $c->CFAttributedStringSetAttribute($styled, $r, $this->italic, $this->italic);
            }
            if ($s->letterSpacing != $style->letterSpacing) {
                $t = $this->float($s->letterSpacing);
                $c->CFAttributedStringSetAttribute($styled, $r, $this->k['kCTTrackingAttributeName'], $t);
                $c->CFRelease($t);
            }
            if ($sp->objectWidth > 0 && $font !== null) { // an inline object: its no-break space kerned to the object's width
                $k = $this->float($sp->objectWidth - $this->spaceAdvance($font));
                $c->CFAttributedStringSetAttribute($styled, $r, $this->k['kCTKernAttributeName'], $k);
                $c->CFRelease($k);
            } elseif (self::noKerning($sp->features)) {
                $z = $this->float(0);
                $c->CFAttributedStringSetAttribute($styled, $r, $this->k['kCTKernAttributeName'], $z);
                $c->CFRelease($z);
            }
            if ($s->lang !== $style->lang && $s->lang !== '') {
                $tag = $this->str($s->lang);
                $c->CFAttributedStringSetAttribute($styled, $r, $this->k['kCTLanguageAttributeName'], $tag);
                $c->CFRelease($tag);
            }
        }
        return $styled;
    }

    /** The advance of a no-break space in $font, which shapes an inline object's rune. */
    private function spaceAdvance(CData $font): float
    {
        $a = $this->addr($font);
        if (!isset($this->spaceAdvance[$a])) {
            $c = $this->c;
            $ch = $c->new('unsigned short[1]'); $ch[0] = 0x00A0;
            $g = $c->new('CGGlyph[1]');
            $this->spaceAdvance[$a] = $c->CTFontGetGlyphsForCharacters($font, $ch, $g, 1) ? $c->CTFontGetAdvancesForGlyphs($font, 0, $g, null, 1) : 0.0;
        }
        return $this->spaceAdvance[$a];
    }

        /** The runs of a line of a text that $italic says is italic, or whose runs styleSpans marked so (mygo's line). @param list<int> $index */
    private function line(CData $line, array $index, int $n, bool $italic): array
    {
        $c = $this->c;
        $at = fn(int $i) => $index[max(0, min($i, $n))];
        $r = $c->CTLineGetStringRange($line);
        $sl = ['start' => $at($r->location), 'end' => $at($r->location + $r->length), 'runs' => []];
        $runs = $c->CTLineGetGlyphRuns($line);
        $all = $c->new('CFRange');
        for ($i = 0, $nr = $c->CFArrayGetCount($runs); $i < $nr; $i++) {
            $run = $c->CFArrayGetValueAtIndex($runs, $i);
            $rr = $c->CTRunGetStringRange($run);
            $attrs = $c->CTRunGetAttributes($run);
            $f = $this->runFont($c->CFDictionaryGetValue($attrs, $this->k['kCTFontAttributeName']), $italic || $c->CFDictionaryGetValue($attrs, $this->italic) !== null);
            $sr = ['font' => $f, 'start' => $at($rr->location), 'end' => $at($rr->location + $rr->length), 'glyphs' => []];
            $count = $c->CTRunGetGlyphCount($run);
            if ($count > 0) {
                $ids = $c->new("CGGlyph[$count]"); $pos = $c->new("CGPoint[$count]"); $adv = $c->new("CGSize[$count]"); $idx = $c->new("long[$count]");
                $c->CTRunGetGlyphs($run, $all, $ids);
                $c->CTRunGetPositions($run, $all, $pos);
                $c->CTRunGetAdvances($run, $all, $adv);
                $c->CTRunGetStringIndices($run, $all, $idx);
                $rtl = ($c->CTRunGetStatus($run) & self::RUN_RTL) !== 0;
                for ($j = 0; $j < $count; $j++) {
                    $sr['glyphs'][] = new Glyph($f, $ids[$j], $f->size, $pos[$j]->x, -$pos[$j]->y, $adv[$j]->w, $at($idx[$j]), 0, $rtl);
                }
            }
            $sl['runs'][] = $sr;
        }
        self::penAdvances($sl['runs']);
        return $sl;
    }

    /**
     * Gives the room Core Text leaves between letters by their positions
     * alone, as between Han and Latin letters, to the advance of the letter
     * before it, so that the widths of lines and the carets follow the pen
     * (mygo's penAdvances).
     */
    private static function penAdvances(array $runs): void
    {
        $prev = null; $pen = 0.0;
        foreach ($runs as $run) {
            foreach ($run['glyphs'] as $g) {
                if ($prev !== null && !$prev->rtl && !$g->rtl) {
                    $gap = $g->x - $pen;
                    if ($gap > 1e-3) {
                        $prev->advance += $gap;
                        $pen += $gap;
                    }
                } else {
                    $pen = $g->x;
                }
                $pen += $g->advance;
                $prev = $g;
            }
        }
    }
}
