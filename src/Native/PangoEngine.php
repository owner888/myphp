<?php
declare(strict_types=1);

namespace MyPHP\Native;

use FFI;
use FFI\CData;
use MyPHP\Text\{Engine, Font, Glyph, Span, Style};
use RuntimeException;

/**
 * Pango lays out paragraphs on Linux (mygo's pango engine): fontconfig
 * finds the fonts and the fallbacks for what a font lacks, HarfBuzz
 * shapes, and Pango breaks lines. Its fonts' glyphs rasterize through
 * Pango::rasterize, by their ids.
 */
final class PangoEngine implements Engine
{
    private const WRAP_WORD = 0, WRAP_WORD_CHAR = 2, STYLE_NORMAL = 0, STYLE_ITALIC = 2, DIR_LTR = 0, DIR_RTL = 1;
    private const SET_APPLICATION = 1, MATCH_PATTERN = 0, RESULT_MATCH = 0, LANG_EQUAL = 0;

    private FFI $f;
    /** @var array<string, CData> font descriptions, by style */
    private array $descs = [];
    /** @var array<int, Font> the Fonts of PangoFonts, by id */
    private array $fonts = [];
    /** @var array<string, CData> PangoLanguages, by tag */
    private array $languages = [];
    /** @var array<string, string> the family fontconfig picks for a generic family in a language writing Han, by language and family (hanFamily) */
    private array $hanFamilies = [];
    /** @var array<string, bool> the files of the fonts registered */
    private array $registered = [];
    /** @var list<string> the names the app's fonts are listed under: the one the app gave, or their own */
    private array $names = [];
    /** @var array<string, string> the own family of each, by lowercased name */
    private array $appFamily = [];
    /** The desktop's interface font, which system-ui stands for before fontconfig's default; '' for none known. */
    private string $uiFamily = '';

    public function __construct(private Pango $p)
    {
        $this->f = $p->f;
    }

    /** The desktop's interface font (GtkSettings' gtk-font-name's family), which "system-ui" stands for first. */
    public function setUIFamily(string $family): void
    {
        if ($family === $this->uiFamily) {
            return;
        }
        $this->uiFamily = $family;
        $this->forgetDescs();
    }

    private function forgetDescs(): void
    {
        foreach ($this->descs as $d) { $this->f->pango_font_description_free($d); }
        $this->descs = [];
    }

    /** The family names of a comma-separated list, without quotes (mygo's familyList). @return list<string> */
    private static function familyList(string $family): array
    {
        $out = [];
        foreach (explode(',', $family) as $f) {
            $f = trim(trim($f), '"\'');
            if ($f !== '') { $out[] = $f; }
        }
        return $out;
    }

    /** The generic family a name stands for (mygo's generic): '' for none. */
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

    /**
     * The family list of a style for Pango, where fontconfig knows the
     * generic families, and system-ui is the desktop's interface font when
     * known; what $before returns for the first generic family goes before
     * it, or after a list without one (mygo's pangoFamily).
     * @param ?\Closure(string): string $before
     */
    private function pangoFamily(string $list, ?\Closure $before = null): string
    {
        $out = [];
        $systemUI = $this->uiFamily !== '' ? [$this->uiFamily, 'system-ui', 'sans-serif'] : ['system-ui', 'sans-serif'];
        $insert = function (string $generic) use (&$before, &$out): void {
            if ($before !== null) {
                $f = $before($generic);
                if ($f !== '') { $out[] = $f; }
                $before = null;
            }
        };
        foreach (self::familyList($list) as $f) {
            $g = self::generic($f);
            if ($g === 'system-ui') {
                $insert($g);
                array_push($out, ...$systemUI);
            } elseif ($g === '') {
                $out[] = $f;
            } else {
                $insert($g);
                $out[] = $g;
            }
        }
        if ($out === []) {
            $insert('system-ui');
            array_push($out, ...$systemUI);
        }
        $insert('system-ui');
        return implode(',', $out);
    }

    /** Whether a language writes Han characters (mygo's writesHan). */
    private static function writesHan(string $lang): bool
    {
        $base = strtolower(preg_split('/[-_]/', $lang)[0]);
        return in_array($base, ['zh', 'ja', 'ko', 'yue', 'cmn', 'wuu', 'hak', 'nan', 'gan', 'hsn', 'lzh', 'cjy', 'cpx', 'czh', 'czo', 'mnp', 'cdo'], true);
    }

    /** Whether $r is a Han character, or punctuation or a full-width form the languages writing Han draw apart (mygo's isCJK). */
    private static function isCJK(int $r): bool
    {
        if ($r < 0x2E80) {
            return false;
        }
        if ($r >= 0x3000 && $r <= 0x303F || $r >= 0xFF00 && $r <= 0xFFEF) {
            return true;
        }
        return preg_match('/\p{Han}/u', mb_chr($r, 'UTF-8')) === 1;
    }

    /** fontconfig's language for a language writing Han (mygo's fcHanLang). */
    private static function fcHanLang(string $tag): string
    {
        $parts = array_values(array_filter(preg_split('/[-_]/', strtolower($tag)), fn(string $p): bool => $p !== ''));
        if ($parts === []) {
            return 'zh-cn';
        }
        if ($parts[0] === 'ja' || $parts[0] === 'ko') {
            return $parts[0];
        }
        $script = $region = '';
        foreach (array_slice($parts, 1) as $p) {
            if (strlen($p) === 4 && $script === '') { $script = $p; } elseif (strlen($p) === 2 && $region === '') { $region = $p; }
        }
        return match (true) {
            in_array($region, ['tw', 'hk', 'mo'], true) && $script !== 'hans' => "zh-$region",
            $region === 'sg' && $script !== 'hant' => 'zh-sg',
            $script === 'hant' => 'zh-tw',
            $script === '' && $parts[0] === 'yue' => 'zh-hk',
            default => 'zh-cn',
        };
    }

    /** The family fontconfig picks for a generic family in a language writing Han, when it supports the language, as fc-match does; '' otherwise (mygo's hanFamily). */
    private function hanFamily(string $lang, string $generic): string
    {
        $lang = self::fcHanLang($lang);
        if ($generic === 'system-ui') { $generic = 'sans-serif'; }
        $key = "$lang\0$generic";
        if (isset($this->hanFamilies[$key])) {
            return $this->hanFamilies[$key];
        }
        $f = $this->f;
        $family = '';
        $p = $f->FcPatternCreate();
        $f->FcPatternAddString($p, 'family', $generic);
        $f->FcPatternAddString($p, 'lang', $lang);
        $f->FcConfigSubstitute(null, $p, self::MATCH_PATTERN);
        $f->FcDefaultSubstitute($p);
        $result = $f->new('int[1]');
        $m = $f->FcFontMatch(null, $p, $result);
        if ($m !== null) {
            $name = $f->new('char*[1]');
            $langs = $f->new('void*[1]');
            if ($f->FcPatternGetString($m, 'family', 0, $name) === self::RESULT_MATCH && $f->FcPatternGetLangSet($m, 'lang', 0, $langs) === self::RESULT_MATCH
                && $f->FcLangSetHasLang($langs[0], $lang) === self::LANG_EQUAL) {
                $family = FFI::string($name[0]);
            }
            $f->FcPatternDestroy($m);
        }
        $f->FcPatternDestroy($p);
        return $this->hanFamilies[$key] = $family;
    }

    /** The font description of a style (mygo's desc). */
    private function desc(Style $style): CData
    {
        $key = $style->family . "\0" . $style->fontSize() . "\0" . $style->weight() . "\0" . (int)$style->italic;
        if (isset($this->descs[$key])) {
            return $this->descs[$key];
        }
        if (count($this->descs) >= 256) {
            $this->forgetDescs();
        }
        $f = $this->f;
        $d = $f->pango_font_description_new();
        $f->pango_font_description_set_family($d, $this->pangoFamily($style->family));
        $f->pango_font_description_set_weight($d, $style->weight());
        $f->pango_font_description_set_style($d, $style->italic ? self::STYLE_ITALIC : self::STYLE_NORMAL);
        $f->pango_font_description_set_absolute_size($d, $style->fontSize() * Pango::SCALE);
        return $this->descs[$key] = $d;
    }

    public function font(Style $style): ?Font
    {
        $font = $this->f->pango_font_map_load_font($this->p->fontMap, $this->p->context, $this->desc($style));
        if ($font === null) {
            return null;
        }
        $out = $this->fontOf($font);
        $this->f->g_object_unref($font);
        return $out;
    }

    /** The Font of a PangoFont, its id its address, held by Pango (mygo's fontOf). */
    private function fontOf(CData $font): Font
    {
        $id = $this->p->adopt($font);
        if (isset($this->fonts[$id])) {
            return $this->fonts[$id];
        }
        $f = $this->f;
        $size = 0.0;
        if (($d = $f->pango_font_describe_with_absolute_size($font)) !== null) {
            $size = $f->pango_font_description_get_size($d) / Pango::SCALE;
            $f->pango_font_description_free($d);
        }
        $asc = $desc = $gap = $uPos = $uThick = $sPos = $sThick = 0.0;
        if (($m = $f->pango_font_get_metrics($font, null)) !== null) {
            $asc = $f->pango_font_metrics_get_ascent($m) / Pango::SCALE;
            $desc = $f->pango_font_metrics_get_descent($m) / Pango::SCALE;
            $gap = max($f->pango_font_metrics_get_height($m) / Pango::SCALE - $asc - $desc, 0.0);
            $uPos = $f->pango_font_metrics_get_underline_position($m) / Pango::SCALE; // the top of the line, above the baseline
            $uThick = $f->pango_font_metrics_get_underline_thickness($m) / Pango::SCALE;
            $sPos = $f->pango_font_metrics_get_strikethrough_position($m) / Pango::SCALE;
            $sThick = $f->pango_font_metrics_get_strikethrough_thickness($m) / Pango::SCALE;
            $f->pango_font_metrics_unref($m);
        }
        // Font's centers are below the baseline: Pango's positions are the lines' tops, above it.
        return $this->fonts[$id] = new Font($size, $asc, $desc, $gap, $id, -$uPos + $uThick / 2, $uThick, -$sPos + $sThick / 2, $sThick);
    }

    public function outline(Font $font, int $glyph): array
    {
        return $this->p->outline($font->id, $glyph);
    }

    /** The PangoLanguage of a tag, the user's default one for ''. */
    private function language(string $tag): CData
    {
        if ($tag === '') {
            return $this->f->pango_language_get_default();
        }
        return $this->languages[$tag] ??= $this->f->pango_language_from_string($tag);
    }

    public function shape(array $runes, Style $style, array $spans, float $width, bool $rtl, bool $wholeWords): array
    {
        if ($runes === []) {
            return [];
        }
        $f = $this->f;
        // The text in UTF-8, with the rune of each byte and of the end.
        $utf8 = ''; $index = [];
        foreach ($runes as $i => $r) {
            $c = mb_chr($r >= 0xD800 && $r < 0xE000 || $r > 0x10FFFF ? 0xFFFD : $r, 'UTF-8');
            $utf8 .= $c;
            for ($k = strlen($c); $k > 0; $k--) { $index[] = $i; }
        }
        $index[] = count($runes);
        $bytes = strlen($utf8);
        $f->pango_context_set_base_dir($this->p->context, $rtl ? self::DIR_RTL : self::DIR_LTR);
        $layout = $f->pango_layout_new($this->p->context);
        if ($layout === null) {
            return [];
        }
        $f->pango_layout_set_auto_dir($layout, 0);
        $f->pango_layout_set_font_description($layout, $this->desc($style));
        $f->pango_layout_set_text($layout, $utf8, $bytes);
        if (($attrs = $this->attributes($style, $spans, $runes)) !== null) {
            $f->pango_layout_set_attributes($layout, $attrs);
            $f->pango_attr_list_unref($attrs);
        }
        if ($width > 0) {
            // Whole Pango units, rounded up; and the letter spacing Pango applies after the last glyph when wrapping but trims from the measured line (mygo's shape).
            $w = ceil($width * Pango::SCALE);
            if ($style->letterSpacing > 0) { $w += round($style->letterSpacing * Pango::SCALE); }
            $f->pango_layout_set_width($layout, (int)min($w, 0x7FFFFFFF));
            $f->pango_layout_set_wrap($layout, $wholeWords ? self::WRAP_WORD : self::WRAP_WORD_CHAR);
        }
        $at = fn(int $b): int => $index[max(0, min($b, $bytes))];
        $lines = [];
        for ($i = 0, $n = $f->pango_layout_get_line_count($layout); $i < $n; $i++) {
            $line = $f->pango_layout_get_line_readonly($layout, $i);
            if ($line === null) {
                continue;
            }
            $sl = ['start' => $at($line->start_index), 'end' => $at($line->start_index + $line->length), 'runs' => []];
            $x = 0;
            for ($r = $line->runs; $r !== null; $r = $r->next) { // the runs in visual order, each with its glyphs
                $run = $f->cast('PangoGlyphItem*', $r->data);
                $item = $run->item; $gs = $run->glyphs;
                $font = $this->fontOf($item->analysis->font);
                $isRtl = ($item->analysis->level & 1) !== 0;
                $sr = ['font' => $font, 'start' => $at($item->offset), 'end' => $at($item->offset + $item->length), 'glyphs' => []];
                for ($j = 0; $j < $gs->num_glyphs; $j++) {
                    $g = $gs->glyphs[$j];
                    $cluster = $at($item->offset + $gs->log_clusters[$j]);
                    $id = $g->glyph; $advance = $g->width;
                    // Pango empties the space ending a wrapped line; the other engines keep it, as editors need.
                    if ($id === Pango::GLYPH_EMPTY && $cluster < count($runes) && \IntlChar::isUWhiteSpace($runes[$cluster])) {
                        [$id, $advance] = $this->space($item->analysis->font, $runes[$cluster]);
                    }
                    $sr['glyphs'][] = new Glyph($font, $id, $font->size, ($x + $g->x_offset) / Pango::SCALE, $g->y_offset / Pango::SCALE, $advance / Pango::SCALE, $cluster, 0, $isRtl);
                    $x += $advance;
                }
                $sl['runs'][] = $sr;
            }
            $lines[] = $sl;
        }
        $f->g_object_unref($layout);
        return $lines;
    }

    /** The glyph and advance, in Pango units, of a whitespace rune in a font, from HarfBuzz as Pango shapes it (mygo's space). @return array{0: int, 1: int} */
    private function space(CData $font, int $r): array
    {
        $f = $this->f;
        $hb = $f->pango_font_get_hb_font($font);
        $glyph = $f->new('unsigned int[1]');
        if ($hb === null || $f->hb_font_get_nominal_glyph($hb, $r, $glyph) === 0) {
            return [Pango::GLYPH_EMPTY, 0];
        }
        return [$glyph[0], $f->hb_font_get_glyph_h_advance($hb, $glyph[0])];
    }

    /**
     * Pango's attributes of a style's letter spacing, OpenType features and
     * language over the whole text, and of its spans' styles over their
     * runes; null without any (mygo's attributes).
     * @param list<Span> $spans
     * @param list<int> $runes
     */
    private function attributes(Style $style, array $spans, array $runes): ?CData
    {
        $f = $this->f;
        $list = null;
        $add = function (?CData $attr, int $from = 0, int $to = 0) use (&$list, $f): void {
            if ($attr === null) {
                return;
            }
            $list ??= $f->pango_attr_list_new();
            if ($to > 0) {
                $attr->start_index = $from;
                $attr->end_index = $to;
            }
            $f->pango_attr_list_insert($list, $attr);
        };
        $features = function (string $list) use ($f): ?CData {
            $out = [];
            foreach (explode(',', $list) as $item) { // HarfBuzz's syntax, which Pango hands over
                [$tag, $value] = array_pad(explode('=', trim($item), 2), 2, null);
                $tag = trim((string)$tag);
                $v = 1;
                if ($value !== null) {
                    if (!ctype_digit(trim($value))) { continue; }
                    $v = (int)trim($value);
                }
                if (strlen($tag) !== 4 || preg_match('/[^\x20-\x7e]/', $tag) === 1) { continue; }
                $out[] = "$tag=$v";
            }
            return $out === [] ? null : $f->pango_attr_font_features_new(implode(',', $out));
        };
        $spacing = fn(float $v): ?CData => $v == 0 ? null : $f->pango_attr_letter_spacing_new((int)round($v * Pango::SCALE));
        $add($spacing($style->letterSpacing));
        $add($style->features === '' ? null : $features($style->features));
        if ($style->lang !== '') {
            $add($f->pango_attr_language_new($this->language($style->lang)));
        }
        $langRuns = $this->langRuns($runes, $style->lang, $spans);
        $han = $this->hanPunctRuns($runes, $style, $spans);
        if ($spans === [] && $langRuns === [] && $han === []) {
            return $list;
        }
        $at = [0]; // the byte each rune starts at
        foreach ($runes as $i => $r) { $at[] = $at[$i] + strlen(mb_chr($r >= 0xD800 && $r < 0xE000 || $r > 0x10FFFF ? 0xFFFD : $r, 'UTF-8')); }
        $n = count($runes);
        $from = 0;
        foreach ($spans as $sp) {
            $to = max($from, min($sp->end, $n));
            [$b0, $b1] = [$at[$from], $at[$to]];
            $start = $from;
            $from = $to;
            if ($b1 === $b0) {
                continue;
            }
            if ($sp->family !== '') { $add($f->pango_attr_family_new($this->pangoFamily($sp->family)), $b0, $b1); }
            if ($sp->size > 0) { $add($f->pango_attr_size_new_absolute((int)round($sp->size * Pango::SCALE)), $b0, $b1); }
            if ($sp->weight > 0) { $add($f->pango_attr_weight_new(min($sp->weight, 999)), $b0, $b1); }
            if ($sp->italic) { $add($f->pango_attr_style_new(self::STYLE_ITALIC), $b0, $b1); }
            $add($spacing($sp->letterSpacing), $b0, $b1);
            $add($sp->features === '' ? null : $features($sp->features), $b0, $b1);
            if ($sp->objectWidth > 0) { // each rune an inline object: a shape of its size on the baseline, its descent below it
                $box = $f->new('PangoRectangle');
                $box->y = -(int)round($sp->objectHeight * Pango::SCALE);
                $box->width = (int)round($sp->objectWidth * Pango::SCALE);
                $box->height = (int)round(($sp->objectHeight + $sp->objectDescent) * Pango::SCALE);
                for ($k = $start; $k < $to; $k++) {
                    $add($f->pango_attr_shape_new(FFI::addr($box), FFI::addr($box)), $at[$k], $at[$k + 1]);
                }
            }
        }
        foreach ($langRuns as [$s, $e, $lang]) { // over the whole text's: an attribute added later over a range wins
            $add($f->pango_attr_language_new($this->language($lang)), $at[$s], $at[$e]);
        }
        // Pango gives punctuation its neighbours' script, and so the first font for Latin that has it: the font the language picks goes before the generic families.
        foreach ($han as [$s, $e, $lang, $family]) {
            $add($f->pango_attr_family_new($this->pangoFamily($family, fn(string $generic): string => $this->hanFamily($lang, $generic))), $at[$s], $at[$e]);
        }
        return $list;
    }

    /**
     * The runs of a text whose language the engine is told apart from $base:
     * those of spans of another, and Han characters in a language that does
     * not write them, in the engine's own ('') (mygo's langRuns).
     * @param list<int> $text @param list<Span> $spans @return list<array{0: int, 1: int, 2: string}>
     */
    private function langRuns(array $text, string $base, array $spans): array
    {
        $any = false;
        foreach ($spans as $sp) { $any = $any || $sp->lang !== ''; }
        if (($base === '' || self::writesHan($base)) && !$any) {
            return [];
        }
        $out = [];
        $span = 0; $from = 0;
        foreach ($text as $i => $r) {
            $lang = $base;
            while ($span < count($spans) && max($spans[$span]->end, $from) <= $i) {
                $from = max($spans[$span]->end, $from);
                $span++;
            }
            if ($span < count($spans) && $spans[$span]->lang !== '') { $lang = $spans[$span]->lang; }
            if ($lang !== '' && self::isCJK($r) && !self::writesHan($lang)) { $lang = ''; }
            if ($lang !== $base) {
                $k = count($out) - 1;
                if ($k >= 0 && $out[$k][1] === $i && $out[$k][2] === $lang) { $out[$k][1] = $i + 1; } else { $out[] = [$i, $i + 1, $lang]; }
            }
        }
        return $out;
    }

    /**
     * The runs of a text's punctuation and full-width forms in the languages
     * writing Han, each of one language and family (mygo's hanPunctRuns).
     * @param list<int> $text @param list<Span> $spans @return list<array{0: int, 1: int, 2: string, 3: string}>
     */
    private function hanPunctRuns(array $text, Style $style, array $spans): array
    {
        $any = self::writesHan($style->lang);
        foreach ($spans as $sp) { $any = $any || self::writesHan($sp->lang); }
        if (!$any) {
            return [];
        }
        $out = [];
        $span = 0; $from = 0;
        foreach ($text as $i => $r) {
            if (($r < 0x3000 || $r > 0x303F) && ($r < 0xFF00 || $r > 0xFFEF)) {
                continue;
            }
            while ($span < count($spans) && max($spans[$span]->end, $from) <= $i) {
                $from = max($spans[$span]->end, $from);
                $span++;
            }
            [$lang, $family] = [$style->lang, $style->family];
            if ($span < count($spans)) {
                if ($spans[$span]->lang !== '') { $lang = $spans[$span]->lang; }
                if ($spans[$span]->family !== '') { $family = $spans[$span]->family; }
            }
            if (!self::writesHan($lang)) {
                continue;
            }
            $k = count($out) - 1;
            if ($k >= 0 && $out[$k][1] === $i && $out[$k][2] === $lang && $out[$k][3] === $family) { $out[$k][1] = $i + 1; continue; }
            $out[] = [$i, $i + 1, $lang, $family];
        }
        return $out;
    }

    /**
     * Adds a font file to fontconfig's fonts for the app, from the user's
     * cache directory, and an alias for $family (mygo's register).
     */
    public function register(string $data, string $family): void
    {
        if ($data === '') {
            throw new RuntimeException('no font data');
        }
        $f = $this->f;
        $dir = (getenv('XDG_CACHE_HOME') ?: (getenv('HOME') ?: sys_get_temp_dir()) . '/.cache') . '/myphp/fonts';
        if (!is_dir($dir) && !@mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw new RuntimeException("myphp: cannot add font: cannot make $dir");
        }
        $path = $dir . '/' . substr(hash('sha256', $data), 0, 32) . '.font';
        if (!is_file($path)) { // through a file of its own renamed into place, so that processes writing it at once leave it whole
            $tmp = tempnam($dir, 'font');
            if ($tmp === false || file_put_contents($tmp, $data) !== strlen($data) || !chmod($tmp, 0o644) || !rename($tmp, $path)) {
                if ($tmp !== false) { @unlink($tmp); }
                if (!is_file($path)) {
                    throw new RuntimeException("myphp: cannot add font: cannot write $path");
                }
            }
        }
        $config = $f->FcConfigGetCurrent();
        if (!isset($this->registered[$path])) {
            if ($f->FcConfigAppFontAddFile($config, $path) === 0) {
                throw new RuntimeException('myphp: cannot add font: not a font fontconfig reads');
            }
            $this->registered[$path] = true;
        }
        $names = $this->familiesOf($config, $path);
        if ($family !== '') {
            if ($names === []) {
                throw new RuntimeException('myphp: cannot add font: no family name');
            }
            if (strcasecmp($names[0], $family) !== 0) {
                $esc = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_XML1);
                $xml = '<?xml version="1.0"?><!DOCTYPE fontconfig SYSTEM "fonts.dtd"><fontconfig><alias binding="same"><family>' . $esc($family)
                    . '</family><prefer><family>' . $esc($names[0]) . '</family></prefer></alias></fontconfig>';
                if ($f->FcConfigParseAndLoadFromMemory($config, $xml, 1) === 0) {
                    throw new RuntimeException('myphp: cannot add font under another family name');
                }
            }
            $this->appFamily[strtolower($family)] = $names[0];
            $this->names[] = $family; // listed under the name the app gave it, which it uses
        } else {
            foreach ($names as $name) {
                $this->appFamily[strtolower($name)] = $name;
                $this->names[] = $name;
            }
        }
        $f->pango_fc_font_map_config_changed($this->p->fontMap);
        $this->forgetDescs(); // fonts found before may resolve differently now
        $this->hanFamilies = [];
    }

    /** The families of the app's fonts from file $path. @return list<string> */
    private function familiesOf(CData $config, string $path): array
    {
        $f = $this->f;
        $set = $f->FcConfigGetFonts($config, self::SET_APPLICATION);
        if ($set === null || $set->nfont <= 0) {
            return [];
        }
        $names = [];
        $s = $f->new('char*[1]');
        for ($i = 0; $i < $set->nfont; $i++) {
            $p = $set->fonts[$i];
            if ($f->FcPatternGetString($p, 'file', 0, $s) !== self::RESULT_MATCH || FFI::string($s[0]) !== $path) {
                continue;
            }
            if ($f->FcPatternGetString($p, 'family', 0, $s) === self::RESULT_MATCH) {
                $names[] = FFI::string($s[0]);
            }
        }
        return $names;
    }

    /** The font map's families, without the generic ones Pango adds, which are aliases (mygo's fontMapFamilies). @return list<array{0: string, 1: bool}> */
    private function mapFamilies(): array
    {
        $f = $this->f;
        $arr = $f->new('void**[1]');
        $n = $f->new('int[1]');
        $f->pango_font_map_list_families($this->p->fontMap, $arr, $n);
        $out = [];
        for ($i = 0; $i < $n[0]; $i++) {
            $fam = $arr[0][$i];
            $name = (string)$f->pango_font_family_get_name($fam);
            if (in_array(strtolower($name), ['', 'sans', 'serif', 'monospace', 'system-ui'], true)) {
                continue;
            }
            $out[] = [$name, $f->pango_font_family_is_monospace($fam) !== 0];
        }
        if ($arr[0] !== null) { $f->g_free($arr[0]); }
        return $out;
    }

    public function fontFamilies(): array
    {
        $all = $this->mapFamilies();
        $mono = []; $own = [];
        foreach ($all as [$name, $m]) { $mono[strtolower($name)] = $m; }
        foreach ($this->appFamily as $o) { $own[strtolower($o)] = true; }
        $app = [];
        foreach ($this->names as $name) {
            $app[] = [$name, $mono[strtolower($this->appFamily[strtolower($name)] ?? $name)] ?? false];
        }
        return [$app, array_values(array_filter($all, fn(array $fam): bool => !isset($own[strtolower($fam[0])])))];
    }
}
