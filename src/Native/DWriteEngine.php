<?php
declare(strict_types=1);

namespace MyPHP\Native;

use FFI;
use FFI\CData;
use MyPHP\Text\{Font, Glyph, Span, Style};
use RuntimeException;

/**
 * The text engine on Windows (mygo's dwrite engine): a paragraph is an
 * IDWriteTextLayout of a text format of its style, the families of its
 * list the first of which the system has, its spans' families, weights,
 * styles, sizes, letter spacing and languages over their ranges; its lines
 * are the layout's, its runs those it hands its renderer as it draws, each
 * at its origin on the line, right to left from the right for a run of
 * that direction. Fonts are their faces at a size, their metrics the
 * face's design units scaled.
 */
final class DWriteEngine implements \MyPHP\Text\Engine
{
    /** The families the generic families stand for, best first (mygo's dwGeneric). */
    private const GENERIC = [
        'system-ui' => ['Segoe UI', 'Tahoma', 'Arial'],
        'sans-serif' => ['Segoe UI', 'Arial'],
        'serif' => ['Times New Roman', 'Cambria', 'Georgia'],
        'monospace' => ['Cascadia Mono', 'Consolas', 'Courier New'],
    ];

    private FFI $f;
    /** @var array<string, bool> whether the system has a family, by its name in lower case */
    private array $exists = [];
    /** @var array<string, CData> text formats, by their style's family, weight, style, size and language */
    private array $formats = [];
    /** @var array<string, ?CData> the faces matching a family, weight and style */
    private array $faces = [];
    /** @var array<string, Font> the fonts of faces at sizes */
    private array $fonts = [];
    /** @var array<int, Font> the last Font of each face, which rasterize() takes the size of */
    private array $byId = [];
    /** @var ?list<array{0: string, 1: bool}> */
    private ?array $systemFamilies = null;
    /** The renderer the layouts draw to, and the runs it collected. */
    private ?CData $renderer = null;
    /** @var list<array{x: float, face: CData, size: float, rtl: bool, pos: int, n: int, glyphs: list<int>, advances: list<float>, offsets: list<array{0: float, 1: float}>, clusters: list<int>}> */
    private array $drawing = [];

    public function __construct(private DWrite $d)
    {
        $this->f = $d->f;
    }

    private function hasSystem(string $family): bool
    {
        $key = strtolower($family);
        if (!isset($this->exists[$key])) {
            $index = $this->f->new('uint32_t'); $exists = $this->f->new('int32_t');
            $c = $this->d->system;
            $this->exists[$key] = ($c->v->FindFamilyName)($c, $this->d->wide($family), FFI::addr($index), FFI::addr($exists)) >= 0 && $exists->cdata !== 0;
        }
        return $this->exists[$key];
    }

    /** The first family of a list the system has, a generic one's best (mygo's family); Segoe UI, Windows's, for none. */
    private function family(string $list): string
    {
        foreach (Style::familyList($list) as $f) {
            if (($g = Style::generic($f)) !== '') {
                foreach (self::GENERIC[$g] as $name) {
                    if ($this->hasSystem($name)) { return $name; }
                }
                continue;
            }
            if ($this->hasSystem($f)) { return $f; }
        }
        foreach (self::GENERIC['system-ui'] as $name) {
            if ($this->hasSystem($name)) { return $name; }
        }
        return 'Segoe UI';
    }

    /** The locale DirectWrite shapes a language in: its tag, or for '' the user's (mygo's localeName). */
    private function locale(string $lang): CData
    {
        return $lang === '' ? $this->d->locale : $this->d->wide($lang);
    }

    /** The text format of a style (mygo's format), or null. */
    private function format(Style $style): ?CData
    {
        $family = $this->family($style->family);
        $key = "$family\0{$style->weight()}\0" . (int)$style->italic . "\0{$style->fontSize()}\0{$style->lang}";
        if (isset($this->formats[$key])) {
            return $this->formats[$key];
        }
        if (count($this->formats) >= 256) {
            foreach ($this->formats as $fmt) { Com::release($fmt); }
            $this->formats = [];
        }
        $out = $this->f->new('void *');
        $fac = $this->d->factory;
        $hr = ($fac->v->CreateTextFormat)($fac, $this->d->wide($family), $this->d->system, $style->weight(), $style->italic ? 2 : 0, 5, $style->fontSize(), $this->locale($style->lang), FFI::addr($out));
        if ($hr < 0 || FFI::isNull($out)) {
            return null;
        }
        return $this->formats[$key] = $out;
    }

    public function font(Style $style): ?Font
    {
        $family = $this->family($style->family);
        $key = "$family\0{$style->weight()}\0" . (int)$style->italic;
        if (!array_key_exists($key, $this->faces)) {
            $this->faces[$key] = $this->matchFace($family, $style->weight(), $style->italic);
        }
        $face = $this->faces[$key];
        return $face === null ? null : $this->fontOf($face, $style->fontSize());
    }

    /** The face of a family of the system's best matching a weight and style (mygo's matchFace), held, or null. */
    private function matchFace(string $family, int $weight, bool $italic): ?CData
    {
        $f = $this->f;
        $c = $this->d->system;
        $index = $f->new('uint32_t'); $exists = $f->new('int32_t');
        if (($c->v->FindFamilyName)($c, $this->d->wide($family), FFI::addr($index), FFI::addr($exists)) < 0 || $exists->cdata === 0) {
            return null;
        }
        $fam = $f->new('void *'); $font = $f->new('void *'); $face = $f->new('void *');
        if (($c->v->GetFontFamily)($c, $index->cdata, FFI::addr($fam)) < 0) {
            return null;
        }
        try {
            $family = $f->cast('IDWriteFontFamily *', $fam);
            if (($family->v->GetFirstMatchingFont)($family, $weight, 5, $italic ? 2 : 0, FFI::addr($font)) < 0) {
                return null;
            }
            $ft = $f->cast('IDWriteFont *', $font);
            $ok = ($ft->v->CreateFontFace)($ft, FFI::addr($face)) >= 0;
            Com::release($font);
            return $ok ? $face : null;
        } finally {
            Com::release($fam);
        }
    }

    /** The Font of a face at a size, its id the face's (mygo's fontOf): its metrics its design units scaled to the size. */
    private function fontOf(CData $face, float $size): Font
    {
        $id = $this->d->adopt($face);
        $key = "$id\0$size";
        if (isset($this->fonts[$key])) {
            return $this->byId[$id] = $this->fonts[$key];
        }
        $fc = $this->f->cast('IDWriteFontFace *', $face);
        $m = $this->f->new('DWRITE_FONT_METRICS');
        ($fc->v->GetMetrics)($fc, FFI::addr($m));
        $em = $size / max($m->designUnitsPerEm, 1);
        // DirectWrite's underline and strikethrough positions are their tops, above the baseline; Font's centers are below it.
        $uTop = $m->underlinePosition * $em; $uThick = $m->underlineThickness * $em;
        $sTop = $m->strikethroughPosition * $em; $sThick = $m->strikethroughThickness * $em;
        return $this->byId[$id] = $this->fonts[$key] = new Font($size, $m->ascent * $em, $m->descent * $em, $m->lineGap * $em, $id, -$uTop + $uThick / 2, $uThick, -$sTop + $sThick / 2, $sThick);
    }

    /** @internal DWrite's: the last Font of face $id, whose size rasterize() takes */
    public function fontOfId(int $id): ?Font
    {
        return $this->byId[$id] ?? null;
    }

    public function outline(Font $font, int $glyph): array
    {
        return []; // the geometry sink's (IDWriteFontFace::GetGlyphRunOutline), which underlines skip by, comes later
    }

    public function register(string $data, string $family): void
    {
        throw new RuntimeException('myphp: adding fonts is not on Windows yet');
    }

    /** No families the app registered yet; the system's, each with whether its regular font is monospaced (IDWriteFont1::IsMonospacedFont). */
    public function fontFamilies(): array
    {
        if ($this->systemFamilies === null) {
            $f = $this->f;
            $c = $this->d->system;
            $out = [];
            $english = $this->d->wide('en-us');
            for ($i = 0, $n = ($c->v->GetFontFamilyCount)($c); $i < $n; $i++) {
                $fam = $f->new('void *');
                if (($c->v->GetFontFamily)($c, $i, FFI::addr($fam)) < 0) { continue; }
                $family = $f->cast('IDWriteFontFamily *', $fam);
                $names = $f->new('void *');
                $name = '';
                if (($family->v->GetFamilyNames)($family, FFI::addr($names)) >= 0) {
                    $name = $this->localized($f->cast('IDWriteLocalizedStrings *', $names), $english);
                    Com::release($names);
                }
                $mono = false;
                $font = $f->new('void *');
                if ($name !== '' && ($family->v->GetFirstMatchingFont)($family, 400, 5, 0, FFI::addr($font)) >= 0) {
                    if (($f1 = Com::query($font, 'acd16696-8c14-4f5d-877e-fe3fc1d32738')) !== null) {
                        $ft = $f->cast('IDWriteFont1 *', $f1);
                        $mono = ($ft->v->IsMonospacedFont)($ft) !== 0;
                        Com::release($f1);
                    }
                    Com::release($font);
                }
                Com::release($fam);
                if ($name !== '') { $out[] = [$name, $mono]; }
            }
            usort($out, fn(array $a, array $b): int => strcasecmp($a[0], $b[0]));
            $this->systemFamilies = $out;
        }
        return [[], $this->systemFamilies];
    }

    /** A localized string's English one, else its first (mygo's englishName). */
    private function localized(CData $strs, CData $english): string
    {
        $f = $this->f;
        $index = $f->new('uint32_t'); $exists = $f->new('int32_t');
        $i = ($strs->v->FindLocaleName)($strs, $english, FFI::addr($index), FFI::addr($exists)) >= 0 && $exists->cdata !== 0 ? $index->cdata : 0;
        $len = $f->new('uint32_t');
        if (($strs->v->GetStringLength)($strs, $i, FFI::addr($len)) < 0) {
            return '';
        }
        $buf = $f->new('uint16_t[' . ($len->cdata + 1) . ']');
        if (($strs->v->GetString)($strs, $i, $buf, $len->cdata + 1) < 0) {
            return '';
        }
        return (string)iconv('UTF-16LE', 'UTF-8', FFI::string($buf, $len->cdata * 2));
    }

    /** The renderer of PHP's the layouts draw to: each glyph run copied as it comes (mygo's textRenderer, collect). */
    private function renderer(): CData
    {
        if ($this->renderer !== null) {
            return $this->renderer;
        }
        $f = $this->f;
        $vt = $f->new('IDWriteTextRendererVtbl', false);
        // IUnknown, IDWritePixelSnapping and IDWriteTextRenderer are itself; no other, as IDWriteTextRenderer1, whose
        // methods past these DirectWrite would call (mygo's rendererQueryInterface).
        $vt->QueryInterface = function ($self, $iid, $out) {
            if (in_array(Com::iid($iid), ['00000000-0000-0000-c000-000000000046', 'eaf3a2da-ecf4-4d24-b644-b34f6842024b', 'ef8a8135-5cc6-45fe-8825-c5a0724eb819'], true)) {
                $out[0] = $self;
                return 0;
            }
            $out[0] = null;
            return Com::E_NOINTERFACE;
        };
        $vt->AddRef = fn($self) => 1;
        $vt->Release = fn($self) => 1;
        $vt->IsPixelSnappingDisabled = function ($self, $context, $disabled) { $disabled[0] = 1; return 0; };
        $vt->GetCurrentTransform = function ($self, $context, $m) { foreach ([1.0, 0.0, 0.0, 1.0, 0.0, 0.0] as $i => $v) { $m[$i] = $v; } return 0; };
        $vt->GetPixelsPerDip = function ($self, $context, $ppd) { $ppd[0] = 1.0; return 0; };
        $vt->DrawGlyphRun = function ($self, $context, $x, $y, $measuring, $run, $desc, $effect) {
            $n = $run->glyphCount;
            // The face a pointer of its own: the run is DirectWrite's, gone once Draw returns.
            $r = ['x' => (float)$x, 'face' => $this->d->typed($run->fontFace, 'void *'), 'size' => $run->fontEmSize, 'rtl' => ($run->bidiLevel & 1) !== 0,
                'pos' => $desc->textPosition, 'n' => $desc->stringLength, 'glyphs' => [], 'advances' => [], 'offsets' => [], 'clusters' => []];
            for ($i = 0; $i < $n; $i++) {
                $r['glyphs'][] = $run->glyphIndices[$i];
                $r['advances'][] = $run->glyphAdvances[$i];
                $r['offsets'][] = FFI::isNull($run->glyphOffsets) ? [0.0, 0.0] : [$run->glyphOffsets[$i]->advanceOffset, $run->glyphOffsets[$i]->ascenderOffset];
            }
            for ($i = 0; $i < $desc->stringLength && !FFI::isNull($desc->clusterMap); $i++) { $r['clusters'][] = $desc->clusterMap[$i]; }
            $this->drawing[] = $r;
            return 0;
        };
        $vt->DrawUnderline = fn($self, $context, $x, $y, $u, $e) => 0;
        $vt->DrawStrikethrough = fn($self, $context, $x, $y, $s, $e) => 0;
        $vt->DrawInlineObject = fn($self, $context, $x, $y, $o, $sideways, $rtl, $e) => 0;
        $obj = $f->new('IDWriteTextRenderer', false);
        $obj->v = FFI::addr($vt);
        return $this->renderer = FFI::addr($obj);
    }

    public function shape(array $runes, Style $style, array $spans, float $width, bool $rtl, bool $wholeWords): array
    {
        $format = $this->format($style);
        if ($format === null || $runes === []) {
            return [];
        }
        $f = $this->f;
        // The text in UTF-16, the rune of each code unit and of the end, and the code unit of each rune.
        $utf16 = ''; $index = []; $units = [0];
        foreach ($runes as $i => $r) {
            $r = $r >= 0xD800 && $r < 0xE000 || $r > 0x10FFFF ? 0xFFFD : $r;
            $utf16 .= (string)iconv('UTF-8', 'UTF-16LE', mb_chr($r, 'UTF-8'));
            $index[] = $i;
            if ($r >= 0x10000) { $index[] = $i; }
            $units[] = $units[$i] + ($r >= 0x10000 ? 2 : 1);
        }
        $n = strlen($utf16) >> 1;
        $index[] = count($runes);
        $text = $f->new("uint16_t[$n]");
        FFI::memcpy($text, $utf16, strlen($utf16));
        // A line as wide as the width fits it: the layout that gave the width may come out a rounding under it (mygo's).
        $out = $f->new('void *');
        $fac = $this->d->factory;
        if (($fac->v->CreateTextLayout)($fac, $text, $n, $format, $width > 0 ? $width + 1e-3 : 0.0, (float)(1 << 24), FFI::addr($out)) < 0 || FFI::isNull($out)) {
            return [];
        }
        $layout = $f->cast('IDWriteTextLayout *', $out);
        try {
            ($layout->v->SetWordWrapping)($layout, $width <= 0 ? 1 : ($wholeWords ? 3 : 0)); // no wrap; whole words; wrap
            $this->typeset($layout, $style, $spans, $units, count($runes));
            if ($rtl) { // trailing alignment keeps the lines at the left, as left-to-right ones are
                ($layout->v->SetReadingDirection)($layout, 1);
                ($layout->v->SetTextAlignment)($layout, 1);
            }
            $count = $f->new('uint32_t');
            ($layout->v->GetLineMetrics)($layout, null, 0, FFI::addr($count));
            if ($count->cdata === 0) {
                return [];
            }
            $metrics = $f->new("DWRITE_LINE_METRICS[{$count->cdata}]");
            if (($layout->v->GetLineMetrics)($layout, $metrics, $count->cdata, FFI::addr($count)) < 0) {
                return [];
            }
            $this->drawing = [];
            ($layout->v->Draw)($layout, null, $this->renderer(), 0.0, 0.0);
            $runs = $this->drawing;
            $this->drawing = [];
        } finally {
            Com::release($out);
        }
        $lines = []; $ends = []; $pos = 0;
        for ($i = 0; $i < $count->cdata; $i++) {
            $start = $index[min($pos, $n)];
            $pos += $metrics[$i]->length;
            $ends[] = $pos;
            $lines[] = ['start' => $start, 'end' => $index[min($pos, $n)], 'runs' => []];
        }
        foreach ($runs as $r) {
            $li = 0;
            while ($li < count($ends) && $ends[$li] <= $r['pos']) { $li++; }
            if ($li >= count($lines) || $r['pos'] + $r['n'] > $n) { continue; }
            $lines[$li]['runs'][] = $this->run($r, $index);
        }
        return $lines;
    }

    /**
     * A layout's spans: their families, weights, styles, sizes, letter
     * spacing and languages over their ranges, the style's letter spacing
     * over all (mygo's typeset). @param list<Span> $spans @param list<int> $units
     */
    private function typeset(CData $layout, Style $style, array $spans, array $units, int $count): void
    {
        $f = $this->f;
        $range = function (int $from, int $to) use ($f, $units): CData {
            $r = $f->new('DWRITE_TEXT_RANGE');
            $r->startPosition = $units[$from];
            $r->length = $units[$to] - $units[$from];
            return $r;
        };
        $this->spacing($layout, $style->letterSpacing, $range(0, $count));
        $from = 0;
        foreach ($spans as $sp) {
            $to = max($from, min($sp->end, $count));
            $r = $range($from, $to);
            $from = $to;
            if ($r->length === 0) { continue; }
            if ($sp->family !== '') {
                ($layout->v->SetFontCollection)($layout, $this->d->system, $r);
                ($layout->v->SetFontFamilyName)($layout, $this->d->wide($this->family($sp->family)), $r);
            }
            if ($sp->weight > 0) { ($layout->v->SetFontWeight)($layout, min($sp->weight, 999), $r); }
            if ($sp->italic) { ($layout->v->SetFontStyle)($layout, 2, $r); }
            if ($sp->size > 0) { ($layout->v->SetFontSize)($layout, $sp->size, $r); }
            if ($sp->lang !== '') { ($layout->v->SetLocaleName)($layout, $this->d->wide($sp->lang), $r); }
            $this->spacing($layout, $sp->letterSpacing, $r);
        }
    }

    /** Letter spacing over a range (IDWriteTextLayout1's, Windows 8's). */
    private function spacing(CData $layout, float $spacing, CData $r): void
    {
        if ($spacing == 0.0 || ($l1 = Com::query($layout, '9064d822-80a7-465c-a986-df65f78b8feb')) === null) {
            return;
        }
        $l = $this->f->cast('IDWriteTextLayout *', $l1);
        ($l->v->SetCharacterSpacing)($l, 0.0, $spacing, 0.0, $r);
        Com::release($l1);
    }

    /**
     * A run's glyphs, from its origin on its line, on the right for a
     * right-to-left run (mygo's run): each its cluster's first rune.
     * @param array{x: float, face: CData, size: float, rtl: bool, pos: int, n: int, glyphs: list<int>, advances: list<float>, offsets: list<array{0: float, 1: float}>, clusters: list<int>} $r
     * @param list<int> $index
     * @return array{font: Font, start: int, end: int, glyphs: list<Glyph>}
     */
    private function run(array $r, array $index): array
    {
        $font = $this->fontOf($r['face'], $r['size']);
        $out = ['font' => $font, 'start' => $index[$r['pos']], 'end' => $index[$r['pos'] + $r['n']], 'glyphs' => []];
        // The code unit starting the cluster of each glyph.
        $ng = count($r['glyphs']);
        $first = array_fill(0, $ng, 0);
        $nc = count($r['clusters']);
        for ($k = 0; $k < $nc;) {
            $g0 = $r['clusters'][$k];
            $k1 = $k + 1;
            while ($k1 < $nc && $r['clusters'][$k1] === $g0) { $k1++; }
            $g1 = $k1 < $nc ? $r['clusters'][$k1] : $ng;
            for ($g = $g0; $g < min($g1, $ng); $g++) { $first[$g] = $k; }
            $k = $k1;
        }
        $pen = $r['x'];
        foreach ($r['glyphs'] as $i => $id) {
            [$dx, $up] = $r['offsets'][$i];
            $advance = $r['advances'][$i];
            if ($r['rtl']) {
                $pen -= $advance;
                $x = $pen - $dx;
            } else {
                $x = $pen + $dx;
                $pen += $advance;
            }
            $out['glyphs'][] = new Glyph($font, $id, $font->size, $x, -$up, $advance, $index[$r['pos'] + $first[$i]], 0, $r['rtl']);
        }
        return $out;
    }
}
