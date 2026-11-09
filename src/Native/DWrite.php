<?php
declare(strict_types=1);

namespace MyPHP\Native;

use FFI;
use FFI\CData;
use MyPHP\UI\Font;
use RuntimeException;

/**
 * The system's glyphs on Windows (mygo's dwrite engine, internal/text's
 * dwrite_windows.go): DirectWrite's text layouts find the fonts, fall back
 * to others for what a font lacks, shape and break lines, and hand their
 * glyph runs to a renderer of PHP's (an IDWriteTextRenderer of closures,
 * Windows calling PHP); IDWriteGlyphRunAnalysis rasterizes glyphs,
 * grayscale, in natural symmetric rendering as Direct2D draws them, or
 * aliased where the system does not smooth fonts. Fonts are known by their
 * IDWriteFontFace's address, which DWriteEngine's Fonts carry as their ids.
 * Baselines go on whole pixels, as DirectWrite snaps them; glyphs on
 * quarters of one, natural symmetric rendering's positions.
 */
final class DWrite implements \MyPHP\Platform\Fonts
{
    public FFI $f;
    /** IDWriteFactory, and its later versions where Windows has them (8.1's, 10's), null otherwise. */
    public CData $factory;
    public ?CData $factory2 = null, $factory3 = null;
    /** The system's font collection. */
    public CData $system;
    /** The user's locale of regional formats, as text of no language is shaped in. */
    public CData $locale;
    /** Whether the system draws text aliased: font smoothing off. */
    public bool $aliased = false;
    /** @var array<int, CData> the font faces known, held, by address */
    private array $faces = [];
    /** @var array<int, bool> whether each face's glyphs are in color */
    private array $colorFaces = [];
    private ?DWriteEngine $engine = null;
    private CData $slot, $slotInt;

    public function __construct(private float $scale)
    {
        $this->f = FFI::cdef(<<<'C'
            typedef struct { uint32_t a; uint16_t b, c; uint8_t d[8]; } GUID;
            typedef struct { uint32_t startPosition, length; } DWRITE_TEXT_RANGE;
            typedef struct { float advanceOffset, ascenderOffset; } DWRITE_GLYPH_OFFSET;
            typedef struct { void *fontFace; float fontEmSize; uint32_t glyphCount; const uint16_t *glyphIndices; const float *glyphAdvances; const DWRITE_GLYPH_OFFSET *glyphOffsets; int32_t isSideways; uint32_t bidiLevel; } DWRITE_GLYPH_RUN;
            typedef struct { const uint16_t *localeName; const uint16_t *string; uint32_t stringLength; const uint16_t *clusterMap; uint32_t textPosition; } DWRITE_GLYPH_RUN_DESCRIPTION;
            typedef struct { uint32_t length, trailingWhitespaceLength, newlineLength; float height, baseline; int32_t isTrimmed; } DWRITE_LINE_METRICS;
            typedef struct { uint16_t designUnitsPerEm, ascent, descent; int16_t lineGap; uint16_t capHeight, xHeight; int16_t underlinePosition; uint16_t underlineThickness; int16_t strikethroughPosition; uint16_t strikethroughThickness; } DWRITE_FONT_METRICS;
            typedef struct { int32_t left, top, right, bottom; } RECT;

            typedef struct { void *qi, *addRef; uint32_t (*Release)(void *self); } IUnknownVtbl;
            typedef struct { IUnknownVtbl *v; } IUnknown;
            typedef struct { int32_t (*QueryInterface)(void *self, const GUID *iid, void **out); void *addRef; uint32_t (*Release)(void *self); } IQueryVtbl;
            typedef struct { IQueryVtbl *v; } IQuery;

            typedef struct { void *r0[3];
                int32_t (*GetSystemFontCollection)(void *self, void **collection, int32_t check);
                void *r4[6];
                int32_t (*CreateRenderingParams)(void *self, void **params);
                void *r11[4];
                int32_t (*CreateTextFormat)(void *self, const uint16_t *family, void *collection, int32_t weight, int32_t style, int32_t stretch, float size, const uint16_t *locale, void **format);
                void *r16[2];
                int32_t (*CreateTextLayout)(void *self, const uint16_t *s, uint32_t n, void *format, float maxW, float maxH, void **layout);
                void *r19[4];
                int32_t (*CreateGlyphRunAnalysis)(void *self, const DWRITE_GLYPH_RUN *run, float ppd, const void *transform, int32_t rendering, int32_t measuring, float x, float y, void **analysis);
            } IDWriteFactoryVtbl;
            typedef struct { IDWriteFactoryVtbl *v; } IDWriteFactory;
            typedef struct { void *r0[30];
                int32_t (*CreateGlyphRunAnalysis)(void *self, const DWRITE_GLYPH_RUN *run, const void *transform, int32_t rendering, int32_t measuring, int32_t gridFit, int32_t antialias, float x, float y, void **analysis);
                int32_t (*CreateGlyphRunAnalysis3)(void *self, const DWRITE_GLYPH_RUN *run, const void *transform, int32_t rendering, int32_t measuring, int32_t gridFit, int32_t antialias, float x, float y, void **analysis);
            } IDWriteFactory23Vtbl;
            typedef struct { IDWriteFactory23Vtbl *v; } IDWriteFactory23;
            typedef struct { void *r0[3];
                uint32_t (*GetFontFamilyCount)(void *self);
                int32_t (*GetFontFamily)(void *self, uint32_t index, void **family);
                int32_t (*FindFamilyName)(void *self, const uint16_t *name, uint32_t *index, int32_t *exists);
            } IDWriteFontCollectionVtbl;
            typedef struct { IDWriteFontCollectionVtbl *v; } IDWriteFontCollection;
            typedef struct { void *r0[6];
                int32_t (*GetFamilyNames)(void *self, void **names);
                int32_t (*GetFirstMatchingFont)(void *self, int32_t weight, int32_t stretch, int32_t style, void **font);
            } IDWriteFontFamilyVtbl;
            typedef struct { IDWriteFontFamilyVtbl *v; } IDWriteFontFamily;
            typedef struct { void *r0[13]; int32_t (*CreateFontFace)(void *self, void **face); } IDWriteFontVtbl;
            typedef struct { IDWriteFontVtbl *v; } IDWriteFont;
            typedef struct { void *r0[17]; int32_t (*IsMonospacedFont)(void *self); } IDWriteFont1Vtbl;
            typedef struct { IDWriteFont1Vtbl *v; } IDWriteFont1;
            typedef struct { void *qi; uint32_t (*AddRef)(void *self); uint32_t (*Release)(void *self); void *r3[5]; void (*GetMetrics)(void *self, DWRITE_FONT_METRICS *m); } IDWriteFontFaceVtbl;
            typedef struct { IDWriteFontFaceVtbl *v; } IDWriteFontFace;
            typedef struct { void *r0[30]; int32_t (*IsColorFont)(void *self); } IDWriteFontFace2Vtbl;
            typedef struct { IDWriteFontFace2Vtbl *v; } IDWriteFontFace2;
            typedef struct { void *r0[3];
                uint32_t (*GetCount)(void *self);
                int32_t (*FindLocaleName)(void *self, const uint16_t *locale, uint32_t *index, int32_t *exists);
                void *r5[2];
                int32_t (*GetStringLength)(void *self, uint32_t index, uint32_t *length);
                int32_t (*GetString)(void *self, uint32_t index, uint16_t *buffer, uint32_t size);
            } IDWriteLocalizedStringsVtbl;
            typedef struct { IDWriteLocalizedStringsVtbl *v; } IDWriteLocalizedStrings;
            typedef struct { void *r0[7]; int32_t (*GetRenderingMode)(void *self); } IDWriteRenderingParamsVtbl;
            typedef struct { IDWriteRenderingParamsVtbl *v; } IDWriteRenderingParams;
            typedef struct { void *r0[3];
                int32_t (*SetTextAlignment)(void *self, int32_t a);
                void *r4;
                int32_t (*SetWordWrapping)(void *self, int32_t w);
                int32_t (*SetReadingDirection)(void *self, int32_t d);
                void *r7[23];
                int32_t (*SetFontCollection)(void *self, void *collection, DWRITE_TEXT_RANGE r);
                int32_t (*SetFontFamilyName)(void *self, const uint16_t *name, DWRITE_TEXT_RANGE r);
                int32_t (*SetFontWeight)(void *self, int32_t weight, DWRITE_TEXT_RANGE r);
                int32_t (*SetFontStyle)(void *self, int32_t style, DWRITE_TEXT_RANGE r);
                void *r34;
                int32_t (*SetFontSize)(void *self, float size, DWRITE_TEXT_RANGE r);
                void *r36[5];
                int32_t (*SetLocaleName)(void *self, const uint16_t *locale, DWRITE_TEXT_RANGE r);
                void *r42[16];
                int32_t (*Draw)(void *self, void *context, void *renderer, float x, float y);
                int32_t (*GetLineMetrics)(void *self, DWRITE_LINE_METRICS *m, uint32_t max, uint32_t *count);
                void *r60[9];
                int32_t (*SetCharacterSpacing)(void *self, float leading, float trailing, float minAdvance, DWRITE_TEXT_RANGE r);
            } IDWriteTextLayoutVtbl;
            typedef struct { IDWriteTextLayoutVtbl *v; } IDWriteTextLayout;
            typedef struct { void *r0[3];
                int32_t (*GetAlphaTextureBounds)(void *self, int32_t type, RECT *bounds);
                int32_t (*CreateAlphaTexture)(void *self, int32_t type, const RECT *bounds, uint8_t *texture, uint32_t size);
            } IDWriteGlyphRunAnalysisVtbl;
            typedef struct { IDWriteGlyphRunAnalysisVtbl *v; } IDWriteGlyphRunAnalysis;
            typedef struct {
                int32_t (*QueryInterface)(void *self, const GUID *iid, void **out);
                uint32_t (*AddRef)(void *self);
                uint32_t (*Release)(void *self);
                int32_t (*IsPixelSnappingDisabled)(void *self, void *context, int32_t *disabled);
                int32_t (*GetCurrentTransform)(void *self, void *context, float *matrix);
                int32_t (*GetPixelsPerDip)(void *self, void *context, float *ppd);
                int32_t (*DrawGlyphRun)(void *self, void *context, float x, float y, int32_t measuring, const DWRITE_GLYPH_RUN *run, const DWRITE_GLYPH_RUN_DESCRIPTION *desc, void *effect);
                int32_t (*DrawUnderline)(void *self, void *context, float x, float y, const void *underline, void *effect);
                int32_t (*DrawStrikethrough)(void *self, void *context, float x, float y, const void *strike, void *effect);
                int32_t (*DrawInlineObject)(void *self, void *context, float x, float y, void *object, int32_t sideways, int32_t rtl, void *effect);
            } IDWriteTextRendererVtbl;
            typedef struct { IDWriteTextRendererVtbl *v; } IDWriteTextRenderer;

            int32_t DWriteCreateFactory(int32_t type, const GUID *iid, void **factory);
            C, 'dwrite.dll');
        $this->slot = $this->f->new('void *[1]');
        $this->slotInt = $this->f->cast('int64_t[1]', $this->slot);
        $out = $this->f->new('void *');
        $iid = $this->guid('b859ee5a-d838-4b5b-a2e8-1adc7d93db48'); // held through the call: FFI::addr() of a temporary points at what is freed
        $hr = $this->f->DWriteCreateFactory(0 /* shared */, FFI::addr($iid), FFI::addr($out));
        if ($hr < 0 || FFI::isNull($out)) {
            throw new RuntimeException(sprintf('DWriteCreateFactory failed (HRESULT 0x%08X)', $hr & 0xFFFFFFFF));
        }
        $this->factory = $this->f->cast('IDWriteFactory *', $out);
        $this->factory2 = $this->query($this->factory, '0439fc60-ca44-4994-8dee-3a9af7b732ec');
        $this->factory3 = $this->query($this->factory, '9a1b41c3-d3bb-466a-87fc-fe67556a3b65');
        $coll = $this->f->new('void *');
        $hr = ($this->factory->v->GetSystemFontCollection)($this->factory, FFI::addr($coll), 0);
        if ($hr < 0) {
            throw new RuntimeException(sprintf('IDWriteFactory::GetSystemFontCollection failed (HRESULT 0x%08X)', $hr & 0xFFFFFFFF));
        }
        $this->system = $this->f->cast('IDWriteFontCollection *', $coll);
        $k = FFI::cdef('int32_t GetUserDefaultLocaleName(uint16_t *name, int32_t max);', 'kernel32.dll');
        $buf = $k->new('uint16_t[85]');
        $n = $k->GetUserDefaultLocaleName($buf, 85);
        $this->locale = $this->wide($n > 1 ? (string)iconv('UTF-16LE', 'UTF-8', FFI::string($buf, ($n - 1) * 2)) : 'en-US');
        $this->settings();
    }

    /** Whether the system draws text aliased (mygo's settings): font smoothing off, or rendering parameters that say so. */
    private function settings(): void
    {
        $u = FFI::cdef('int32_t SystemParametersInfoW(uint32_t action, uint32_t param, void *value, uint32_t winIni);', 'user32.dll');
        $smooth = $u->new('int32_t');
        $u->SystemParametersInfoW(0x004A /* SPI_GETFONTSMOOTHING */, 0, FFI::addr($smooth), 0);
        $params = $this->f->new('void *');
        $aliasedParams = false;
        if (($this->factory->v->CreateRenderingParams)($this->factory, FFI::addr($params)) >= 0) {
            $p = $this->f->cast('IDWriteRenderingParams *', $params);
            $aliasedParams = ($p->v->GetRenderingMode)($p) === 1;
            $this->release($params);
        }
        $this->aliased = $smooth->cdata === 0 || $aliasedParams;
    }

    /** A GUID of its text form. */
    public function guid(string $s): CData
    {
        $h = str_replace('-', '', $s);
        $g = $this->f->new('GUID');
        $g->a = hexdec(substr($h, 0, 8)); $g->b = hexdec(substr($h, 8, 4)); $g->c = hexdec(substr($h, 12, 4));
        for ($i = 0; $i < 8; $i++) { $g->d[$i] = hexdec(substr($h, 16 + 2 * $i, 2)); }
        return $g;
    }

    /** $obj's interface $iid, held, or null where it has none (QueryInterface). */
    public function query(CData $obj, string $iid): ?CData
    {
        $q = $this->f->cast('IQuery *', $obj);
        $out = $this->f->new('void *');
        $g = $this->guid($iid); // held through the call: FFI::addr() of a temporary points at what is freed
        if (($q->v->QueryInterface)($q, FFI::addr($g), FFI::addr($out)) < 0 || FFI::isNull($out)) {
            return null;
        }
        return $out;
    }

    /** Releases COM object $obj, unless it is none. */
    public function release(?CData $obj): void
    {
        if ($obj !== null && !FFI::isNull($obj)) {
            $u = $this->f->cast('IUnknown *', $obj);
            ($u->v->Release)($u);
        }
    }

    /** $s as a NUL-terminated string of UTF-16, an array a pointer parameter takes. */
    public function wide(string $s): CData
    {
        $u = (string)iconv('UTF-8', 'UTF-16LE', $s) . "\0\0";
        $buf = $this->f->new('uint16_t[' . (strlen($u) >> 1) . ']');
        FFI::memcpy($buf, $u, strlen($u));
        return $buf;
    }

    /** The integer address of a pointer. */
    public function addr(?CData $p): int
    {
        if ($p === null || FFI::isNull($p)) {
            return 0;
        }
        $this->slot[0] = $p;
        return $this->slotInt[0];
    }

    /** The id of font face $face, held from now on: its address. */
    public function adopt(CData $face): int
    {
        $id = $this->addr($face);
        if (!isset($this->faces[$id])) {
            $f = $this->f->cast('IDWriteFontFace *', $face);
            ($f->v->AddRef)($f);
            $this->faces[$id] = $f;
        }
        return $id;
    }

    public function face(int $id): CData
    {
        return $this->faces[$id] ?? throw new RuntimeException("unknown font $id");
    }

    public function engine(): \MyPHP\Text\Engine
    {
        return $this->engine ??= new DWriteEngine($this);
    }

    public function scale(): float
    {
        return $this->scale;
    }

    public function setScale(float $scale): void
    {
        $this->scale = $scale;
    }

    /** Natural symmetric rendering's four positions a pixel, aliased rendering's whole pixels (mygo's positions). */
    public function positions(float $px): int
    {
        return $this->aliased ? 1 : 4;
    }

    /** The nearest whole pixel, where DirectWrite snaps baselines. */
    public function baseline(float $y): float
    {
        return round($y);
    }

    /** Whether the face's glyphs are in color (IDWriteFontFace2::IsColorFont, Windows 8.1's). */
    public function isColor(int $fontId): bool
    {
        if (!isset($this->colorFaces[$fontId])) {
            $color = false;
            if (($f2 = $this->query($this->face($fontId), 'd8b768ff-64bc-4e66-982b-ec8e87f693f7')) !== null) {
                $face = $this->f->cast('IDWriteFontFace2 *', $f2);
                $color = ($face->v->IsColorFont)($face) !== 0;
                $this->release($f2);
            }
            $this->colorFaces[$fontId] = $color;
        }
        return $this->colorFaces[$fontId];
    }

    /** Shapes $text, one line, at $size with UI\Font style bits: the system's interface font, or its monospaced one. */
    public function shape(string $text, float $size, int $style = 0): array
    {
        $runes = $text === '' ? [] : array_map('mb_ord', mb_str_split($text));
        $st = new \MyPHP\Text\Style(($style & Font::MONO) ? 'monospace' : 'system-ui', $size, ($style & Font::BOLD) ? 700 : 400, ($style & Font::ITALIC) !== 0);
        $font = $this->engine()->font($st);
        $ascent = $font?->ascent ?? $size * 0.8;
        $descent = $font?->descent ?? $size * 0.2;
        // The UTF-16 index of each rune, and of the end.
        $u16 = []; $u = 0;
        foreach ($runes as $i => $r) { $u16[$i] = $u; $u += $r >= 0x10000 ? 2 : 1; }
        $u16[count($runes)] = $u;
        $glyphs = []; $carets = [0 => 0.0]; $width = 0.0;
        foreach ($runes === [] ? [] : $this->engine()->shape($runes, $st, [], 0, false, false) as $line) {
            foreach ($line['runs'] as $run) {
                $id = $run['font']?->id ?? 0;
                foreach ($run['glyphs'] as $g) {
                    $index = $u16[$g->cluster] ?? 0;
                    $glyphs[] = ['font' => $id, 'glyph' => $g->id, 'x' => $g->x, 'y' => -$g->y, 'index' => $index];
                    $carets[$index] ??= $g->x;
                    $width = max($width, $g->x + $g->advance);
                }
            }
        }
        $carets[$u] = $width;
        return ['width' => $width, 'ascent' => $ascent, 'descent' => $descent, 'glyphs' => $glyphs, 'carets' => $carets];
    }

    /**
     * Rasterizes a glyph with IDWriteGlyphRunAnalysis, grayscale coverage,
     * at scale(), or $scale, pixels a point (mygo's glyph), $dx its pen's
     * part of a pixel: ox from the pen's pixel to the bitmap's left edge,
     * oy up from the baseline to its bottom edge, as CoreText::rasterize.
     * A color font's glyph draws in its own color, as its outline, till its
     * layers come (TranslateColorGlyphRun). $stroke, $blur and $cutOut grow,
     * blur and hollow it as Pango's do.
     */
    public function rasterize(int $fontId, int $glyph, ?float $scale = null, int $shade = -1, float $stroke = 0.0, float $blur = 0.0, bool $cutOut = false, float $dx = 0.0): ?array
    {
        $s = $scale ?? $this->scale;
        $face = $this->face($fontId);
        $font = $this->engine()->fontOfId($fontId);
        $cov = $this->coverage($face, $glyph, ($font?->size ?? 12.0) * $s, $dx);
        if ($cov === null) {
            return null;
        }
        [$left, $top, $w, $h, $alpha] = $cov;
        $pad = (int)ceil($stroke * $s) + (int)ceil(3 * $blur * $s);
        if ($pad > 0) { // room for the outline and the blur, the coverage in its middle
            $grown = str_repeat("\0", ($w + 2 * $pad) * ($h + 2 * $pad));
            for ($y = 0; $y < $h; $y++) { $grown = substr_replace($grown, substr($alpha, $y * $w, $w), ($y + $pad) * ($w + 2 * $pad) + $pad, $w); }
            [$left, $top, $w, $h, $alpha] = [$left - $pad, $top - $pad, $w + 2 * $pad, $h + 2 * $pad, $grown];
        }
        $glyphOnly = $alpha;
        if ($stroke > 0) { $alpha = self::dilate($alpha, $w, $h, $stroke * $s); }
        $rows = Mem::alloc($w * $h);
        $addr = Mem::addr($rows);
        Mem::write($addr, $alpha);
        if ($blur > 0) { Gaussian::blur($addr, $w, $h, $blur * $s); }
        if ($cutOut && $stroke > 0) { // the glyph taken out of its outline
            $out = Mem::read($addr, $w * $h);
            for ($i = 0, $n = $w * $h; $i < $n; $i++) {
                $m = ord($glyphOnly[$i]);
                if ($m > 0) { $out[$i] = chr(intdiv(ord($out[$i]) * (255 - $m) + 127, 255)); }
            }
            Mem::write($addr, $out);
        }
        return ['w' => $w, 'h' => $h, 'ox' => $left, 'oy' => -($top + $h), 'rows' => $rows, 'addr' => $addr, 'color' => false];
    }

    /**
     * The coverage of glyph $glyph of $face at $px pixels an em, drawn with
     * its pen at $dx (mygo's analyze): its bitmap's left and top from the
     * pen's pixel and the baseline, its size, a byte a pixel; null for none.
     * @return ?array{0: int, 1: int, 2: int, 3: int, 4: string}
     */
    private function coverage(CData $face, int $glyph, float $px, float $dx): ?array
    {
        $f = $this->f;
        $index = $f->new('uint16_t[1]'); $index[0] = $glyph;
        $advance = $f->new('float[1]');
        $run = $f->new('DWRITE_GLYPH_RUN');
        $run->fontFace = $face;
        $run->fontEmSize = $px;
        $run->glyphCount = 1;
        $run->glyphIndices = $f->cast('const uint16_t *', FFI::addr($index[0]));
        $run->glyphAdvances = $f->cast('const float *', FFI::addr($advance[0]));
        $mode = $this->aliased ? 1 : 5; // DWRITE_RENDERING_MODE_ALIASED, NATURAL_SYMMETRIC
        $out = $f->new('void *');
        if ($this->factory2 !== null) { // IDWriteFactory2's: grayscale coverage; IDWriteFactory3's takes Windows 10's modes too
            $fac = $f->cast('IDWriteFactory23 *', $this->factory3 ?? $this->factory2);
            $make = $this->factory3 !== null ? $fac->v->CreateGlyphRunAnalysis3 : $fac->v->CreateGlyphRunAnalysis;
            $hr = $make($fac, FFI::addr($run), null, $mode, 0 /* natural */, 0 /* grid fit default */, 1 /* grayscale */, $dx, 0.0, FFI::addr($out));
        } else {
            $hr = ($this->factory->v->CreateGlyphRunAnalysis)($this->factory, FFI::addr($run), 1.0, null, $mode, 0, $dx, 0.0, FFI::addr($out));
        }
        if ($hr < 0 || FFI::isNull($out)) {
            return null;
        }
        $a = $f->cast('IDWriteGlyphRunAnalysis *', $out);
        try {
            // Grayscale coverage is DWRITE_TEXTURE_ALIASED_1x1 with IDWriteFactory2's analyses; without, ClearType's three values a pixel, averaged.
            $type = $this->factory2 !== null || $this->aliased ? 0 : 1;
            $r = $f->new('RECT');
            if (($a->v->GetAlphaTextureBounds)($a, $type, FFI::addr($r)) < 0 || $r->right <= $r->left || $r->bottom <= $r->top) {
                return null;
            }
            $w = $r->right - $r->left; $h = $r->bottom - $r->top;
            if ($w > 2048 || $h > 2048) {
                return null;
            }
            $n = $w * $h * ($type === 0 ? 1 : 3);
            $buf = $f->new("uint8_t[$n]");
            if (($a->v->CreateAlphaTexture)($a, $type, FFI::addr($r), $buf, $n) < 0) {
                return null;
            }
            $bytes = FFI::string($buf, $n);
            if ($type === 1) {
                $gray = '';
                for ($i = 0; $i < $w * $h; $i++) { $gray .= chr(intdiv(ord($bytes[3 * $i]) + ord($bytes[3 * $i + 1]) + ord($bytes[3 * $i + 2]) + 1, 3)); }
                $bytes = $gray;
            }
            return [$r->left, $r->top, $w, $h, $bytes];
        } finally {
            $this->release($out);
        }
    }

    /** Coverage $a grown by $radius pixels all round: each pixel the most of those within the radius, an outline's stroke as cairo strokes it round. */
    private static function dilate(string $a, int $w, int $h, float $radius): string
    {
        $r = (int)ceil($radius);
        $out = $a;
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $m = 0;
                for ($dy = -$r; $dy <= $r && $m < 255; $dy++) {
                    $yy = $y + $dy;
                    if ($yy < 0 || $yy >= $h) { continue; }
                    for ($dx = -$r; $dx <= $r; $dx++) {
                        $xx = $x + $dx;
                        if ($xx < 0 || $xx >= $w || $dx * $dx + $dy * $dy > $radius * $radius + 0.5) { continue; }
                        $m = max($m, ord($a[$yy * $w + $xx]));
                    }
                }
                $out[$y * $w + $x] = chr($m);
            }
        }
        return $out;
    }
}
