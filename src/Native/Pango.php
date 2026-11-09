<?php
declare(strict_types=1);

namespace MyPHP\Native;

use FFI;
use FFI\CData;
use MyPHP\UI\Font;
use RuntimeException;

/**
 * The system's glyphs on Linux (mygo's pango engine's glyph, positions and
 * baseline): Pango shapes a line, fontconfig finds the fonts, and cairo
 * rasterizes glyphs with FreeType, in color for color fonts. They are the
 * libraries GTK draws with. Fonts are known by their PangoFont's address,
 * which PangoEngine's Fonts carry as their ids.
 *
 * Glyphs go on whole pixels, as GTK's labels' (Pango rounds their
 * positions): positions() is 1; a baseline is the whole pixel at or above
 * it.
 */
final class Pango implements \MyPHP\Platform\Fonts
{
    public const SCALE = 1024;
    public const GLYPH_EMPTY = 0x0FFFFFFF, GLYPH_UNKNOWN = 0x10000000;
    private const FORMAT_ARGB32 = 0, FORMAT_A8 = 2;
    private const ANTIALIAS_GRAY = 2, HINT_STYLE_MEDIUM = 3, HINT_METRICS_ON = 2;
    private const FT_FACE_FLAG_COLOR = 1 << 14;
    private const OPERATOR_DEST_OUT = 6;

    public FFI $f;
    public CData $fontMap, $context;
    /** @var array<int, CData> the PangoFonts known, held, by address */
    private array $fonts = [];
    /** @var array<string, CData> cairo's scaled fonts drawing a font's glyphs at a scale, by id and scale */
    private array $scaled = [];
    /** @var array<int, bool> whether each font's glyphs are in color */
    private array $colorFonts = [];
    private ?PangoEngine $engine = null;

    public function __construct(private float $scale)
    {
        $this->f = FFI::cdef('
            typedef struct _GSList { void* data; struct _GSList* next; } GSList;
            typedef struct { void* layout; int start_index; int length; GSList* runs; unsigned int flags; } PangoLayoutLine;
            typedef struct { void* shape_engine; void* lang_engine; void* font; unsigned char level; unsigned char gravity; unsigned char flags; unsigned char script; void* language; void* extra_attrs; } PangoAnalysis;
            typedef struct { int offset; int length; int num_chars; PangoAnalysis analysis; } PangoItem;
            typedef struct { unsigned int glyph; int width; int x_offset; int y_offset; unsigned int attr; } PangoGlyphInfo;
            typedef struct { int num_glyphs; PangoGlyphInfo* glyphs; int* log_clusters; int space; } PangoGlyphString;
            typedef struct { PangoItem* item; PangoGlyphString* glyphs; int y_offset; int start_x_offset; int end_x_offset; } PangoGlyphItem;
            typedef struct { int x; int y; int width; int height; } PangoRectangle;
            typedef struct { void* klass; unsigned int start_index; unsigned int end_index; } PangoAttribute;
            typedef struct { double xx, yx, xy, yy, x0, y0; } cairo_matrix_t;
            typedef struct { unsigned long index; double x, y; } cairo_glyph_t;
            typedef struct { double x_bearing, y_bearing, width, height, x_advance, y_advance; } cairo_text_extents_t;
            typedef union { struct { int type; int length; } header; struct { double x, y; } point; } cairo_path_data_t;
            typedef struct { int status; cairo_path_data_t* data; int num_data; } cairo_path_t;
            typedef struct { long num_faces; long face_index; long face_flags; } FT_FaceRec_head;
            typedef struct { int nfont; int sfont; void** fonts; } FcFontSet;

            void* g_object_ref(void*); void g_object_unref(void*); void g_free(void*);
            void* pango_cairo_font_map_new(void);
            void* pango_font_map_create_context(void*);
            void pango_cairo_context_set_font_options(void*, void*);
            void pango_context_set_round_glyph_positions(void*, int);
            void pango_context_set_base_dir(void*, int);
            void* pango_font_map_load_font(void*, void*, void*);
            void pango_font_map_list_families(void*, void***, int*);
            const char* pango_font_family_get_name(void*);
            int pango_font_family_is_monospace(void*);
            void pango_fc_font_map_config_changed(void*);
            void* pango_cairo_font_get_scaled_font(void*);
            void* pango_font_get_metrics(void*, void*);
            void* pango_font_describe_with_absolute_size(void*);
            void pango_font_get_glyph_extents(void*, unsigned int, PangoRectangle*, PangoRectangle*);
            void* pango_font_get_hb_font(void*);
            int pango_font_metrics_get_ascent(void*); int pango_font_metrics_get_descent(void*); int pango_font_metrics_get_height(void*);
            int pango_font_metrics_get_underline_position(void*); int pango_font_metrics_get_underline_thickness(void*);
            int pango_font_metrics_get_strikethrough_position(void*); int pango_font_metrics_get_strikethrough_thickness(void*);
            void pango_font_metrics_unref(void*);
            void* pango_font_description_new(void); void pango_font_description_free(void*);
            void pango_font_description_set_family(void*, const char*); void pango_font_description_set_weight(void*, int);
            void pango_font_description_set_style(void*, int); void pango_font_description_set_absolute_size(void*, double);
            int pango_font_description_get_size(void*);
            void* pango_layout_new(void*); void pango_layout_set_text(void*, const char*, int);
            void pango_layout_set_font_description(void*, void*); void pango_layout_set_width(void*, int); void pango_layout_set_wrap(void*, int);
            void pango_layout_set_auto_dir(void*, int); int pango_layout_get_line_count(void*);
            PangoLayoutLine* pango_layout_get_line_readonly(void*, int); void pango_layout_set_attributes(void*, void*);
            int pango_layout_get_baseline(void*); void pango_layout_get_extents(void*, PangoRectangle*, PangoRectangle*);
            void* pango_attr_list_new(void); void pango_attr_list_unref(void*); void pango_attr_list_insert(void*, PangoAttribute*);
            PangoAttribute* pango_attr_letter_spacing_new(int); PangoAttribute* pango_attr_family_new(const char*);
            PangoAttribute* pango_attr_size_new_absolute(int); PangoAttribute* pango_attr_weight_new(int); PangoAttribute* pango_attr_style_new(int);
            PangoAttribute* pango_attr_shape_new(const PangoRectangle*, const PangoRectangle*); PangoAttribute* pango_attr_language_new(void*);
            PangoAttribute* pango_attr_font_features_new(const char*);
            void* pango_language_from_string(const char*); void* pango_language_get_default(void);
            int hb_font_get_nominal_glyph(void*, unsigned int, unsigned int*); int hb_font_get_glyph_h_advance(void*, unsigned int);
            void* cairo_font_options_create(void); void cairo_font_options_destroy(void*);
            void cairo_font_options_set_antialias(void*, int); void cairo_font_options_set_hint_metrics(void*, int);
            void cairo_font_options_set_hint_style(void*, int); void cairo_font_options_set_subpixel_order(void*, int);
            void* cairo_scaled_font_get_font_face(void*); void cairo_scaled_font_get_font_matrix(void*, cairo_matrix_t*);
            void cairo_scaled_font_get_font_options(void*, void*);
            void* cairo_scaled_font_create(void*, const cairo_matrix_t*, const cairo_matrix_t*, void*);
            int cairo_scaled_font_status(void*); void cairo_scaled_font_destroy(void*);
            void cairo_scaled_font_glyph_extents(void*, const cairo_glyph_t*, int, cairo_text_extents_t*);
            void* cairo_image_surface_create(int, int, int); unsigned char* cairo_image_surface_get_data(void*);
            int cairo_image_surface_get_stride(void*); void cairo_surface_flush(void*); void cairo_surface_destroy(void*);
            void* cairo_create(void*); void cairo_destroy(void*); void cairo_set_scaled_font(void*, void*);
            void cairo_set_source_rgba(void*, double, double, double, double); void cairo_show_glyphs(void*, const cairo_glyph_t*, int);
            void cairo_glyph_path(void*, const cairo_glyph_t*, int); void cairo_set_line_width(void*, double); void cairo_set_line_join(void*, int);
            void cairo_fill_preserve(void*); void cairo_stroke(void*); void cairo_set_operator(void*, int);
            cairo_path_t* cairo_copy_path_flat(void*); void cairo_path_destroy(cairo_path_t*);
            FT_FaceRec_head* cairo_ft_scaled_font_lock_face(void*); void cairo_ft_scaled_font_unlock_face(void*);
            void* FcConfigGetCurrent(void); int FcConfigAppFontAddFile(void*, const char*); FcFontSet* FcConfigGetFonts(void*, int);
            int FcConfigParseAndLoadFromMemory(void*, const char*, int);
            int FcPatternGetString(void*, const char*, int, char**); void* FcPatternCreate(void); void FcPatternDestroy(void*);
            int FcPatternAddString(void*, const char*, const char*); int FcPatternGetLangSet(void*, const char*, int, void**);
            int FcLangSetHasLang(void*, const char*); int FcConfigSubstitute(void*, void*, int); void FcDefaultSubstitute(void*);
            void* FcFontMatch(void*, void*, int*);
        ', 'libpangocairo-1.0.so.0');
        $this->fontMap = $this->f->pango_cairo_font_map_new() ?? throw new RuntimeException('pango_cairo_font_map_new');
        $this->context = $this->f->pango_font_map_create_context($this->fontMap);
        // GDK's defaults on X11 without a settings daemon: grayscale antialiasing, medium hinting, metrics on whole pixels (mygo's newPango).
        $opts = $this->f->cairo_font_options_create();
        $this->f->cairo_font_options_set_antialias($opts, self::ANTIALIAS_GRAY);
        $this->f->cairo_font_options_set_hint_style($opts, self::HINT_STYLE_MEDIUM);
        $this->f->cairo_font_options_set_hint_metrics($opts, self::HINT_METRICS_ON);
        $this->f->pango_cairo_context_set_font_options($this->context, $opts);
        $this->f->cairo_font_options_destroy($opts);
        $this->f->pango_context_set_round_glyph_positions($this->context, 1);
    }

    public function engine(): \MyPHP\Text\Engine
    {
        return $this->engine ??= new PangoEngine($this);
    }

    public function scale(): float
    {
        return $this->scale;
    }

    public function setScale(float $scale): void
    {
        $this->scale = $scale;
    }

    public function positions(float $px): int
    {
        return 1;
    }

    public function baseline(float $y): float
    {
        return floor($y + 1e-3);
    }

    /** The id of PangoFont $font, held from now on: its address. */
    public function adopt(CData $font): int
    {
        $id = Mem::addr($font);
        if (!isset($this->fonts[$id])) {
            $this->fonts[$id] = $this->f->g_object_ref($font);
        }
        return $id;
    }

    public function font(int $id): CData
    {
        return $this->fonts[$id] ?? throw new RuntimeException("unknown font $id");
    }

    public function isColor(int $fontId): bool
    {
        if (!isset($this->colorFonts[$fontId])) {
            $sf = $this->f->pango_cairo_font_get_scaled_font($this->font($fontId));
            $color = false;
            if ($sf !== null) {
                $face = $this->f->cairo_ft_scaled_font_lock_face($sf);
                if ($face !== null) {
                    $color = ($face->face_flags & self::FT_FACE_FLAG_COLOR) !== 0;
                    $this->f->cairo_ft_scaled_font_unlock_face($sf);
                }
            }
            $this->colorFonts[$fontId] = $color;
        }
        return $this->colorFonts[$fontId];
    }

    /** cairo's font of $fontId at $scale pixels a point, its user space in pixels (mygo's scaledFont), or null. */
    private function scaledFont(int $fontId, float $scale): ?CData
    {
        $key = "$fontId:$scale";
        if (isset($this->scaled[$key])) {
            return $this->scaled[$key];
        }
        if (count($this->scaled) >= 256) {
            foreach ($this->scaled as $sf) { $this->f->cairo_scaled_font_destroy($sf); }
            $this->scaled = [];
        }
        $src = $this->f->pango_cairo_font_get_scaled_font($this->font($fontId));
        if ($src === null) {
            return null;
        }
        $m = $this->f->new('cairo_matrix_t');
        $this->f->cairo_scaled_font_get_font_matrix($src, FFI::addr($m));
        $m->xx *= $scale; $m->yx *= $scale; $m->xy *= $scale; $m->yy *= $scale;
        $id = $this->f->new('cairo_matrix_t');
        $id->xx = 1.0; $id->yy = 1.0;
        $opts = $this->f->cairo_font_options_create();
        $this->f->cairo_scaled_font_get_font_options($src, $opts);
        $sf = $this->f->cairo_scaled_font_create($this->f->cairo_scaled_font_get_font_face($src), FFI::addr($m), FFI::addr($id), $opts);
        $this->f->cairo_font_options_destroy($opts);
        if ($sf === null || $this->f->cairo_scaled_font_status($sf) !== 0) {
            return null;
        }
        return $this->scaled[$key] = $sf;
    }

    /**
     * Shapes $text, one line, at $size with UI\Font style bits: the
     * system's interface font, or its monospaced one.
     */
    public function shape(string $text, float $size, int $style = 0): array
    {
        $f = $this->f;
        $desc = $f->pango_font_description_new();
        $f->pango_font_description_set_family($desc, ($style & Font::MONO) ? 'monospace' : 'system-ui,sans-serif');
        $f->pango_font_description_set_weight($desc, ($style & Font::BOLD) ? 700 : 400);
        $f->pango_font_description_set_style($desc, ($style & Font::ITALIC) ? 2 : 0);
        $f->pango_font_description_set_absolute_size($desc, $size * self::SCALE);
        $f->pango_context_set_base_dir($this->context, 0);
        $layout = $f->pango_layout_new($this->context);
        $f->pango_layout_set_auto_dir($layout, 0);
        $f->pango_layout_set_font_description($layout, $desc);
        $f->pango_layout_set_text($layout, $text, strlen($text));
        $ink = $f->new('PangoRectangle'); $logical = $f->new('PangoRectangle');
        $f->pango_layout_get_extents($layout, FFI::addr($ink), FFI::addr($logical));
        $ascent = $f->pango_layout_get_baseline($layout) / self::SCALE;
        // The UTF-16 index of each byte of the text, and of its end.
        $u16 = []; $u = 0;
        for ($i = 0, $n = strlen($text); $i < $n;) {
            $b = ord($text[$i]);
            $len = $b < 0x80 ? 1 : ($b < 0xE0 ? 2 : ($b < 0xF0 ? 3 : 4));
            for ($k = 0; $k < $len; $k++) { $u16[$i + $k] = $u; }
            $u += $len === 4 ? 2 : 1;
            $i += $len;
        }
        $u16[strlen($text)] = $u;
        $glyphs = []; $carets = [0 => 0.0]; $x = 0;
        $line = $f->pango_layout_get_line_readonly($layout, 0);
        for ($r = $line === null ? null : $line->runs; $r !== null; $r = $r->next) {
            $run = $f->cast('PangoGlyphItem*', $r->data);
            $item = $run->item; $gs = $run->glyphs;
            $id = $this->adopt($item->analysis->font);
            for ($j = 0; $j < $gs->num_glyphs; $j++) {
                $g = $gs->glyphs[$j];
                $index = $u16[$item->offset + $gs->log_clusters[$j]] ?? 0;
                if ($g->glyph !== self::GLYPH_EMPTY && ($g->glyph & self::GLYPH_UNKNOWN) === 0) {
                    $glyphs[] = ['font' => $id, 'glyph' => $g->glyph, 'x' => ($x + $g->x_offset) / self::SCALE, 'y' => -$g->y_offset / self::SCALE, 'index' => $index];
                }
                $carets[$index] ??= $x / self::SCALE;
                $x += $g->width;
            }
        }
        $carets[$u] = $logical->width / self::SCALE;
        $f->g_object_unref($layout);
        $f->pango_font_description_free($desc);
        return ['width' => $logical->width / self::SCALE, 'ascent' => $ascent, 'descent' => $logical->height / self::SCALE - $ascent, 'glyphs' => $glyphs, 'carets' => $carets];
    }

    /**
     * Rasterizes a glyph with cairo, grayscale, or in color for a color
     * font's (premultiplied RGBA), at scale(), or $scale, pixels a point:
     * as CoreText::rasterize, ox from the pen's pixel to the bitmap's left
     * edge, oy up from the baseline to its bottom edge. A $stroke grows the
     * glyph all round, a $blur blurs it (Gaussian), $cutOut takes the glyph
     * out of the outline so drawn. $shade is Core Graphics' font smoothing,
     * which cairo has none of.
     */
    public function rasterize(int $fontId, int $glyph, ?float $scale = null, int $shade = -1, float $stroke = 0.0, float $blur = 0.0, bool $cutOut = false, float $dx = 0.0): ?array
    {
        if ($glyph === self::GLYPH_EMPTY || ($glyph & self::GLYPH_UNKNOWN) !== 0) {
            return null; // Pango's empty glyphs, and the boxes it draws for missing ones, are not the font's
        }
        $s = $scale ?? $this->scale;
        $sf = $this->scaledFont($fontId, $s);
        if ($sf === null) {
            return null;
        }
        $f = $this->f;
        $g = $f->new('cairo_glyph_t[1]');
        $g[0]->index = $glyph;
        $ext = $f->new('cairo_text_extents_t');
        $f->cairo_scaled_font_glyph_extents($sf, $g, 1, FFI::addr($ext));
        if ($ext->width <= 0 || $ext->height <= 0) {
            return null;
        }
        $color = $this->isColor($fontId);
        if (($stroke > 0 || $blur > 0) && $color) {
            return null; // a color glyph is its own picture: no outline or blur of it
        }
        $pad = 1 + (int)ceil($stroke * $s) + (int)ceil(3 * $blur * $s);
        $left = (int)floor($ext->x_bearing + $dx) - $pad;
        $top = (int)floor($ext->y_bearing) - $pad;
        $w = (int)ceil($ext->x_bearing + $ext->width + $dx) + $pad - $left;
        $h = (int)ceil($ext->y_bearing + $ext->height) + $pad - $top;
        if ($w > 2048 || $h > 2048) {
            return null;
        }
        $g[0]->x = $dx - $left; $g[0]->y = -$top;
        $surface = $this->draw($sf, $g, $w, $h, $color, $stroke * $s);
        $bpp = $color ? 4 : 1;
        $rows = Mem::alloc($bpp * $w * $h);
        $addr = Mem::addr($rows);
        $this->copyOut($surface, $addr, $w, $h, $color);
        $f->cairo_surface_destroy($surface);
        if ($blur > 0) { Gaussian::blur($addr, $w, $h, $blur * $s); }
        if ($cutOut && $stroke > 0) { // the glyph taken out of its outline, blurred or not
            $glyphOnly = $this->draw($sf, $g, $w, $h, false, 0.0);
            $data = $f->cairo_image_surface_get_data($glyphOnly);
            $stride = $f->cairo_image_surface_get_stride($glyphOnly);
            $mask = FFI::string($data, $stride * $h);
            $out = Mem::read($addr, $w * $h);
            for ($y = 0; $y < $h; $y++) {
                for ($x = 0; $x < $w; $x++) {
                    $m = ord($mask[$y * $stride + $x]);
                    if ($m > 0) {
                        $i = $y * $w + $x;
                        $out[$i] = chr(intdiv(ord($out[$i]) * (255 - $m) + 127, 255));
                    }
                }
            }
            Mem::write($addr, $out);
            $f->cairo_surface_destroy($glyphOnly);
        }
        return ['w' => $w, 'h' => $h, 'ox' => $left, 'oy' => -($top + $h), 'rows' => $rows, 'addr' => $addr, 'color' => $color];
    }

    /** A surface of $w × $h with glyph $g drawn white: its coverage (A8), or in color (ARGB32); grown by $stroke pixels all round when above 0. */
    private function draw(CData $sf, CData $g, int $w, int $h, bool $color, float $stroke)
    {
        $f = $this->f;
        $surface = $f->cairo_image_surface_create($color ? self::FORMAT_ARGB32 : self::FORMAT_A8, $w, $h);
        $cr = $f->cairo_create($surface);
        $f->cairo_set_scaled_font($cr, $sf);
        $f->cairo_set_source_rgba($cr, 1.0, 1.0, 1.0, 1.0);
        if ($stroke > 0) { // its outline filled and stroked twice as wide, joins round
            $f->cairo_glyph_path($cr, $g, 1);
            $f->cairo_set_line_width($cr, 2 * $stroke);
            $f->cairo_set_line_join($cr, 1); // CAIRO_LINE_JOIN_ROUND
            $f->cairo_fill_preserve($cr);
            $f->cairo_stroke($cr);
        } else {
            $f->cairo_show_glyphs($cr, $g, 1);
        }
        $f->cairo_destroy($cr);
        $f->cairo_surface_flush($surface);
        return $surface;
    }

    /** Copies a surface's pixels to $addr, rows packed: coverage, or premultiplied RGBA from cairo's BGRA (in memory, little-endian ARGB32). */
    private function copyOut($surface, int $addr, int $w, int $h, bool $color): void
    {
        $f = $this->f;
        $data = $f->cairo_image_surface_get_data($surface);
        $stride = $f->cairo_image_surface_get_stride($surface);
        $src = FFI::string($data, $stride * $h);
        $bpp = $color ? 4 : 1;
        $out = '';
        for ($y = 0; $y < $h; $y++) {
            $row = substr($src, $y * $stride, $w * $bpp);
            if ($color) {
                $px = '';
                for ($x = 0; $x < $w * 4; $x += 4) { $px .= $row[$x + 2] . $row[$x + 1] . $row[$x] . $row[$x + 3]; }
                $row = $px;
            }
            $out .= $row;
        }
        Mem::write($addr, $out);
    }

    /**
     * The outline of glyph $glyph of font $fontId in points from its
     * origin, y up, as line segments [x0, y0, x1, y1] (mygo's
     * glyphOutline), cairo's curves flattened; none for a glyph without one.
     * @return list<array{0: float, 1: float, 2: float, 3: float}>
     */
    public function outline(int $fontId, int $glyph): array
    {
        if ($glyph === self::GLYPH_EMPTY || ($glyph & self::GLYPH_UNKNOWN) !== 0) {
            return [];
        }
        $sf = $this->scaledFont($fontId, 1.0);
        if ($sf === null) {
            return [];
        }
        $f = $this->f;
        $surface = $f->cairo_image_surface_create(self::FORMAT_A8, 1, 1);
        $cr = $f->cairo_create($surface);
        $f->cairo_set_scaled_font($cr, $sf);
        $g = $f->new('cairo_glyph_t[1]');
        $g[0]->index = $glyph;
        $f->cairo_glyph_path($cr, $g, 1);
        $path = $f->cairo_copy_path_flat($cr);
        $segs = [];
        $at = [0.0, 0.0]; $from = [0.0, 0.0];
        for ($i = 0; $i < $path->num_data; $i += $path->data[$i]->header->length) {
            $type = $path->data[$i]->header->type;
            $p = $path->data[$i]->header->length > 1 ? [$path->data[$i + 1]->point->x, -$path->data[$i + 1]->point->y] : null;
            switch ($type) {
                case 0: $at = $from = $p; break; // move to
                case 1: $segs[] = [$at[0], $at[1], $p[0], $p[1]]; $at = $p; break; // line to
                case 3: $segs[] = [$at[0], $at[1], $from[0], $from[1]]; $at = $from; break; // close
            }
        }
        $f->cairo_path_destroy($path);
        $f->cairo_destroy($cr);
        $f->cairo_surface_destroy($surface);
        return $segs;
    }
}
