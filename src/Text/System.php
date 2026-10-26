<?php
declare(strict_types=1);

namespace MyPHP\Text;

/**
 * Lays out text with the system's text engine (mygo's text.System): the
 * engine shapes and breaks each paragraph, and the system makes the lines
 * of a layout of them, aligned, truncated with an ellipsis, its fonts and
 * layouts cached. Main thread only.
 */
final class System
{
    /** How many frames a layout no frame used stays (mygo's 240 frames), and how many the cache holds. */
    private const KEEP_FRAMES = 240, MAX_LAYOUTS = 4096;

    /** @var array<string, ?Font> */
    private array $fonts = [];
    /** @var array<string, array{0: Layout, 1: int}> layouts by their parameters, and the frame that used each last */
    private array $layouts = [];
    private int $frame = 0;
    /** How many layouts the system made, those found in its cache left out (mygo's LayoutsMade). */
    public int $made = 0;
    /** The language of texts whose style names none (setLanguage). */
    private string $lang = '';
    /** @var array<string, float> the width of the ellipsis of each style */
    private array $marks = [];
    /** @var array<string, float> the min-content widths of texts by what decides them (UI\Node::minContentWidth), the oldest going first past MAX_LAYOUTS */
    private array $minWidths = [];

    public function __construct(public readonly Engine $engine) {}

    /** Sets the language of the texts whose style names none, a BCP 47 tag, or "" for none; the layouts made before are made anew. */
    public function setLanguage(string $tag): void
    {
        if ($tag === $this->lang) {
            return;
        }
        $this->lang = $tag;
        $this->layouts = [];
        $this->marks = [];
        $this->minWidths = [];
    }

    public function language(): string { return $this->lang; }

    /** Adds a TrueType or OpenType font, or collection, that text uses with its family, or with $family when given (mygo's RegisterFont); the layouts made before are made anew. */
    public function registerFont(string $data, string $family = ''): void
    {
        $this->engine->register($data, $family);
        $this->fonts = [];
        $this->layouts = [];
        $this->marks = [];
        $this->minWidths = [];
    }

    /** The font families text can use, as a font setting offers them: the app's registered ones first, then the system's, each once, sorted without regard to case (mygo's FontFamilies). @return list<\MyPHP\UI\FontFamily> */
    public function fontFamilies(): array
    {
        [$app, $system] = $this->engine->fontFamilies();
        $out = []; $seen = [];
        foreach ([[$app, true], [$system, false]] as [$families, $registered]) {
            $from = count($out);
            foreach ($families as [$name, $mono]) {
                $key = strtolower($name);
                if ($name === '') { continue; }
                if (!isset($seen[$key])) {
                    $seen[$key] = count($out);
                    $out[] = new \MyPHP\UI\FontFamily($name, $mono, $registered);
                } elseif ($seen[$key] >= $from) { // fonts of one family, as its bold after its regular: monospaced if one is
                    $f = $out[$seen[$key]];
                    $out[$seen[$key]] = new \MyPHP\UI\FontFamily($f->name, $f->monospaced || $mono, $f->registered);
                }
            }
            $part = array_slice($out, $from);
            usort($part, fn($a, $b) => strcmp(strtolower($a->name), strtolower($b->name)) ?: strcmp($a->name, $b->name));
            array_splice($out, $from, count($part), $part);
            foreach ($part as $i => $f) { $seen[strtolower($f->name)] = $from + $i; }
        }
        return $out;
    }

    /** Forgets layouts no frame used for a while: after each frame. */
    public function endFrame(): void
    {
        $this->frame++;
        if ($this->frame % 30 === 0) {
            foreach ($this->layouts as $k => [, $used]) {
                if ($this->frame - $used > self::KEEP_FRAMES) {
                    unset($this->layouts[$k]);
                }
            }
        }
    }

    /**
     * A text's min-content width kept by keepMinWidth under $key, or null:
     * layout asks it of every text that wraps each frame, keyed by what
     * decides it without making the parameters of its layout (mygo measures
     * it once a frame; a frame of 2000 texts took five times as long).
     */
    public function minWidth(string $key): ?float
    {
        return $this->minWidths[$key] ?? null;
    }

    /** Keeps $width as the min-content width of $key's text, the oldest going first past MAX_LAYOUTS. */
    public function keepMinWidth(string $key, float $width): float
    {
        if (count($this->minWidths) >= self::MAX_LAYOUTS) {
            $this->minWidths = array_slice($this->minWidths, intdiv(self::MAX_LAYOUTS, 4), null, true);
        }
        return $this->minWidths[$key] = $width;
    }

    /** $p laid out, shared: not to be changed. */
    public function layout(Params $p): Layout
    {
        $k = $p->key();
        if (isset($this->layouts[$k])) {
            $this->layouts[$k][1] = $this->frame;
            return $this->layouts[$k][0];
        }
        if (count($this->layouts) >= self::MAX_LAYOUTS) {
            asort($this->layouts); // by frame: the oldest go
            $this->layouts = array_slice($this->layouts, intdiv(self::MAX_LAYOUTS, 4), null, true);
        }
        $l = $this->make($p);
        $this->layouts[$k] = [$l, $this->frame];
        return $l;
    }

    /** The ascent, descent and default line height of a style. @return array{0: float, 1: float, 2: float} */
    public function metrics(Style $style): array
    {
        return self::metricsOf($this->font($style), $style);
    }

    /** The font of a style. */
    public function font(Style $style): ?Font
    {
        $key = $style->family . "\0" . $style->fontSize() . "\0" . $style->weight() . "\0" . (int)$style->italic;
        if (!array_key_exists($key, $this->fonts)) {
            if (count($this->fonts) >= 1024) {
                $this->fonts = [];
            }
            $this->fonts[$key] = $this->engine->font(new Style($style->family, $style->fontSize(), $style->weight(), $style->italic));
        }
        return $this->fonts[$key];
    }

    /** @return array{0: float, 1: float, 2: float} */
    private static function metricsOf(?Font $f, Style $style): array
    {
        $size = $style->fontSize();
        $ascent = $size * 0.8; $descent = $size * 0.2; $gap = 0.0;
        if ($f !== null && $f->ascent > 0) {
            $ascent = $f->ascent; $descent = $f->descent; $gap = max($f->lineGap, 0);
        }
        $lineHeight = $style->lineHeight > 0 ? round($style->lineHeight * $size, 9) : $ascent + $descent + $gap; // rounded: a fixed line height comes as a multiple of the size, which gives back its points
        return [$ascent, $descent, $lineHeight];
    }

    /** The runes of $s, code points. @return list<int> */
    public static function runes(string $s): array
    {
        if ($s === '') {
            return [];
        }
        if (preg_match('/[\x80-\xff]/', $s) !== 1) {
            return array_values(unpack('C*', $s));
        }
        return array_values(unpack('N*', mb_convert_encoding($s, 'UTF-32BE', 'UTF-8')));
    }

    private function make(Params $p): Layout
    {
        $this->made++;
        $m = self::metricsOf($this->font($p->style), $p->style);
        $runes = self::runes($p->text);
        $l = new Layout($p, $runes);
        $style = $p->style->lang === '' && $this->lang !== '' ? $p->style->with(['lang' => $this->lang]) : $p->style; // the engine shapes in the system's language where they name none
        $y = 0.0;
        $n = count($runes);
        for ($start = 0; $start <= $n;) {
            $end = $start;
            while ($end < $n && $runes[$end] !== 0x0A) { $end++; }
            $maxLines = $p->maxLines > 0 ? $p->maxLines - count($l->lines) : 0;
            if ($this->paragraph($l, $p, $style, $runes, $start, $end, $maxLines, $end < $n, $m, $y)) {
                $l->truncated = true;
                break;
            }
            $start = $end + 1;
        }
        foreach ($l->lines as $line) {
            $l->width = max($l->width, $line->width);
        }
        $l->height = $y;
        // Each line aligned within the wrap width, or the longest line.
        $box = $p->width > 0 ? $p->width : $l->width;
        foreach ($l->lines as $line) {
            $align = $p->align;
            if ($line->rtl) {
                $align = match ($align) { Params::START => Params::END, Params::END => Params::START, default => $align };
            }
            $dx = match ($align) { Params::CENTER => ($box - $line->width) / 2, Params::END, Params::RIGHT => $box - $line->width, default => 0.0 };
            if ($dx != 0) {
                $line->x += $dx;
                foreach ($line->glyphs as $g) { $g->x += $dx; }
            }
        }
        return $l;
    }

    /**
     * Lays out runes [$start, $end), a paragraph without newlines, in at
     * most $maxLines lines (0 is unlimited) from $y down, and moves $y past
     * it; whether it cut the text, which continues after the paragraph when
     * $continues is set.
     *
     * @param list<int> $runes
     * @param array{0: float, 1: float, 2: float} $m
     */
    private function paragraph(Layout $l, Params $p, Style $style, array $runes, int $start, int $end, int $maxLines, bool $continues, array $m, float &$y): bool
    {
        $text = array_slice($runes, $start, $end - $start);
        // A carriage return before the newline is part of it.
        if ($text !== [] && $text[count($text) - 1] === 0x0D) {
            array_pop($text);
        }
        $rtl = self::isRTL($text, $p->rtl);
        $spans = Span::in($p->spans, $start, $start + count($text));
        $shaped = [];
        if ($text !== []) {
            $width = $p->noWrap ? 0.0 : max($p->width, 0.0);
            $shaped = $this->engine->shape($text, $style, $spans, $width, $rtl, $p->noBreakWords);
        }
        if ($shaped === []) {
            $shaped = [['start' => 0, 'end' => count($text), 'runs' => []]];
        }
        $truncated = false;
        if ($maxLines > 0 && (count($shaped) > $maxLines || count($shaped) === $maxLines && $continues)) {
            $last = $this->ellipsize($text, $spans, $shaped[$maxLines - 1]['start'], $p, $style, $rtl);
            $shaped = [...array_slice($shaped, 0, $maxLines - 1), $last];
            $truncated = true;
        }
        $first = count($l->lines);
        foreach ($shaped as $sl) {
            $l->lines[] = self::line($p, $text, $sl, $start, $rtl, $m, $y, $spans);
        }
        // Runes between lines, if an engine left any out, belong to the line before.
        for ($i = $first, $n = count($l->lines); $i < $n - 1; $i++) {
            $l->lines[$i]->end = max($l->lines[$i]->end, $l->lines[$i + 1]->start);
        }
        if (!$truncated) {
            $l->lines[count($l->lines) - 1]->end = $end;
        }
        return $truncated;
    }

    /**
     * Positions a line of the paragraph $text, which starts at rune
     * $offset of the layout, with its top at $y, and moves $y past it.
     *
     * @param list<int> $text
     * @param list<Span> $spans
     */
    private static function line(Params $p, array $text, array $sl, int $offset, bool $rtl, array $m, float &$y, array $spans): Line
    {
        $line = new Line(start: $offset + $sl['start'], end: $offset + $sl['end'], rtl: $rtl, ascent: $m[0], descent: $m[1]);
        // Whitespace ending a line takes no room, unless kept.
        $trim = $sl['end'];
        if (!$p->keepSpaces) {
            while ($trim > $sl['start'] && self::isSpace($text[$trim - 1])) { $trim--; }
        }
        $size = $p->style->fontSize();
        $x0 = INF;
        foreach ($sl['runs'] as $run) {
            $runSize = $size;
            if (($f = $run['font']) !== null) {
                $line->ascent = max($line->ascent, $f->ascent);
                $line->descent = max($line->descent, $f->descent);
                if ($f->size > 0) {
                    $runSize = $f->size; // a span of another size
                }
            }
            // A cluster holds the runes up to the next one of its run.
            $starts = [];
            foreach ($run['glyphs'] as $g) {
                if ($g->cluster >= 0) { $starts[$g->cluster] = true; }
            }
            ksort($starts);
            $starts = array_keys($starts);
            $nextOf = [];
            foreach ($starts as $k => $s) { $nextOf[$s] = $starts[$k + 1] ?? $run['end']; }
            foreach ($run['glyphs'] as $g) {
                if ($g->cluster < 0) { // the ellipsis
                    $g->cluster = $line->end;
                } else {
                    if ($g->cluster >= $trim && $g->cluster < $sl['end']) {
                        continue;
                    }
                    $g->runes = max($nextOf[$g->cluster] - $g->cluster, 1);
                    $o = self::objectAt($spans, $g->cluster);
                    if ($o !== null) {
                        // An inline object stands on the baseline, as high as it is, whatever font its run took.
                        $g->object = true; $g->advance = $o->objectWidth; $g->id = 0;
                        $line->ascent = max($line->ascent, $o->objectHeight);
                    }
                    $g->cluster += $offset;
                }
                $g->size = $runSize;
                $x0 = min($x0, $g->x);
                // Logical width follows the pen, not the glyphs' drawing offsets.
                $line->width += $g->advance;
                $line->glyphs[] = $g;
            }
        }
        if ($line->glyphs === []) {
            $x0 = 0.0;
        }
        $line->width = max($line->width, 0.0);
        $line->height = max($m[2], $line->ascent + $line->descent);
        $line->y = $y;
        $line->baseline = $y + ($line->height - $line->ascent - $line->descent) / 2 + $line->ascent;
        foreach ($line->glyphs as $g) {
            $g->x -= $x0;
            $g->y += $line->baseline;
        }
        $y += $line->height;
        return $line;
    }

    // The lines drawn along text (mygo's Decoration).
    public const UNDERLINE = 0, STRIKETHROUGH = 1;

    /**
     * The strokes of decoration $d of glyphs [$i, $j) of line $li of $l,
     * runes of one style, with the layout's origin at ($x, $y) points, at
     * $scale pixels a point (mygo's Decorate): where the fonts place
     * underlines and strikethroughs, with their thickness, or, if $thick is
     * positive, $thick points around their middle, rounded to whole pixels
     * (at least one) and on whole pixels. Spaces at the ends of the line
     * are left out. Each is x0, x1, top and bottom in points.
     *
     * @return list<array{0: float, 1: float, 2: float, 3: float}>
     */
    public function decorate(Layout $l, int $li, int $i, int $j, float $x, float $y, float $scale, int $d, float $thick): array
    {
        $line = $l->lines[$li] ?? null;
        if ($line === null) {
            return [];
        }
        $gs = $line->glyphs;
        $i = max($i, 0); $j = min($j, count($gs));
        $rune = static fn(int $k): int => $l->runes[$gs[$k]->cluster] ?? 0;
        if ($i === 0) {
            while ($i < $j && self::isSpace($rune($i))) { $i++; }
        }
        if ($j === count($gs)) {
            while ($j > $i && self::isSpace($rune($j - 1))) { $j--; }
        }
        if ($i >= $j) {
            return [];
        }
        $style = $l->params->style;
        foreach ($l->params->spans as $sp) {
            if ($gs[$i]->cluster < $sp->end) {
                $style = $sp->style($style);
                break;
            }
        }
        $f = $this->font($style);
        if ($f === null) {
            return [];
        }
        $x0 = INF; $width = 0.0;
        for ($k = $i; $k < $j; $k++) { $x0 = min($x0, $gs[$k]->x); $width += $gs[$k]->advance; }
        $x1 = $x0 + ceil($width - 1e-4);
        if ($d === self::STRIKETHROUGH) {
            [$center, $th] = [$f->strikeCenter, $f->strikeThick];
        } else {
            $center = 0.0; $th = 0.0;
            for ($k = $i; $k < $j; $k++) { // the lowest and thickest of the fonts of the run
                if (($gf = $gs[$k]->font) !== null) { $center = max($center, $gf->underlineCenter); $th = max($th, $gf->underlineThick); }
            }
        }
        $base = round(($y + $line->baseline) * $scale);
        $top = $base + ($center - $th / 2) * $scale; $bottom = $base + ($center + $th / 2) * $scale;
        if ($thick > 0) {
            $h = max(round($thick * $scale), 1.0);
            $top = round(($top + $bottom - $h) / 2); $bottom = $top + $h;
        }
        return [[$x + $x0, $x + $x1, $top / $scale, $bottom / $scale]];
    }

    /** @param list<Span> $spans */
    private static function objectAt(array $spans, int $i): ?Span
    {
        foreach ($spans as $sp) {
            if ($sp->end > $i) {
                return $sp->objectWidth > 0 ? $sp : null;
            }
        }
        return null;
    }

    /**
     * The paragraph $text from rune $start on one line ending with an
     * ellipsis: as many of its graphemes as fit the width with it.
     *
     * @param list<int> $text
     * @param list<Span> $spans
     */
    private function ellipsize(array $text, array $spans, int $start, Params $p, Style $style, bool $rtl): array
    {
        $rest = array_slice($text, $start);
        $restSpans = Span::in($spans, $start, count($text));
        $mark = System::runes($p->ellipsis !== '' ? $p->ellipsis : '…');
        $cut = count($rest);
        if ($p->width > 0) {
            $key = $style->key() . "\0" . $p->ellipsis . "\0" . (int)$rtl;
            if (!isset($this->marks[$key])) {
                $w = 0.0;
                foreach ($this->engine->shape($mark, $style, [], 0, $rtl, false) as $l) {
                    $w = max($w, self::advance($l));
                }
                if (count($this->marks) >= 256) { $this->marks = []; }
                $this->marks[$key] = $w;
            }
            $ellipsis = $this->marks[$key];
            // The advance of each cluster, at its first rune.
            $advances = array_fill(0, count($rest), 0.0);
            foreach ($this->engine->shape($rest, $style, $restSpans, 0, $rtl, false) as $l) {
                foreach ($l['runs'] as $run) {
                    foreach ($run['glyphs'] as $g) {
                        if ($g->cluster >= 0 && $g->cluster < count($rest)) {
                            $advances[$g->cluster] += $g->advance;
                        }
                    }
                }
            }
            $b = new Boundaries();
            $b->reset($rest, mb_convert_encoding(pack('N*', ...$rest), 'UTF-8', 'UTF-32BE'));
            $w = 0.0; $cut = 0;
            while ($cut < count($rest)) {
                $next = $b->nextGrapheme($cut);
                $gw = array_sum(array_slice($advances, $cut, $next - $cut));
                if ($w + $gw + $ellipsis > $p->width) {
                    break;
                }
                $w += $gw;
                $cut = $next;
            }
        }
        while ($cut > 0 && self::isSpace($rest[$cut - 1])) { $cut--; }
        $t = [...array_slice($rest, 0, $cut), ...$mark];
        $out = ['start' => $start, 'end' => $start + $cut, 'runs' => []];
        // The ellipsis takes the style of the text it ends.
        $tSpans = Span::in($restSpans, 0, $cut);
        $n = count($tSpans);
        if ($n > 0 && $tSpans[$n - 1]->end === $cut) {
            $tSpans[$n - 1] = $tSpans[$n - 1]->until($cut + count($mark));
        }
        foreach ($this->engine->shape($t, $style, $tSpans, 0, $rtl, false) as $l) {
            foreach ($l['runs'] as $run) {
                $run['start'] = $start + min($run['start'], $cut);
                $run['end'] = $start + min($run['end'], $cut);
                foreach ($run['glyphs'] as $g) {
                    $g->cluster = $g->cluster >= $cut ? -1 : $g->cluster + $start;
                }
                $out['runs'][] = $run;
            }
        }
        return $out;
    }

    /** The width a shaped line's glyphs take. */
    private static function advance(array $l): float
    {
        $w = 0.0;
        foreach ($l['runs'] as $run) {
            foreach ($run['glyphs'] as $g) { $w += $g->advance; }
        }
        return max($w, 0.0);
    }

    private static function isSpace(int $r): bool
    {
        return $r === 0x20 || $r >= 0x09 && $r <= 0x0D || $r === 0x85 || $r === 0xA0 || $r === 0x1680 || $r >= 0x2000 && $r <= 0x200A || $r === 0x2028 || $r === 0x2029 || $r === 0x202F || $r === 0x205F || $r === 0x3000;
    }

    /** Whether a paragraph is right to left: whether its first strongly directional rune is, else $neutral. @param list<int> $para */
    /** Whether paragraph $para (code points) reads right to left: by its first strong character, $neutral without one (mygo's paragraph direction). @param list<int> $para */
    public static function isRTL(array $para, bool $neutral): bool
    {
        foreach ($para as $r) {
            if ($r < 0x80) {
                if (($r | 0x20) >= 0x61 && ($r | 0x20) <= 0x7a) {
                    return false;
                }
                continue;
            }
            $c = mb_chr($r, 'UTF-8');
            if (preg_match('/^[\p{Hebrew}\p{Arabic}\p{Syriac}\p{Thaana}\p{Nko}\p{Samaritan}\p{Mandaic}\p{Adlam}]$/u', $c) === 1) {
                return true;
            }
            if (preg_match('/^\p{L}$/u', $c) === 1) {
                return false;
            }
        }
        return $neutral;
    }
}
