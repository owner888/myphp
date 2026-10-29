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
    /** @var array<string, array{0: float, 1: float}|false> where glyphs' outlines cross underlines (inkInBand), by font, glyph and band */
    private array $bands = [];

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
    }

    public function language(): string { return $this->lang; }

    /** Adds a TrueType or OpenType font, or collection, that text uses with its family, or with $family when given (mygo's RegisterFont); the layouts made before are made anew. */
    public function registerFont(string $data, string $family = ''): void
    {
        $this->engine->register($data, $family);
        $this->fonts = [];
        $this->layouts = [];
        $this->marks = [];
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
        $indent = max($p->indent, 0.0);
        foreach ($l->lines as $i => $line) {
            $l->width = max($l->width, $line->width + ($i === 0 ? $indent : 0.0));
        }
        $l->height = $y;
        // Each line aligned within the wrap width, or the longest line, the first beside its indent.
        $box = $p->width > 0 ? $p->width : $l->width;
        $justify = $p->justify && $p->width > 0 && !$p->noWrap; $justified = false;
        foreach ($l->lines as $i => $line) {
            [$lo, $hi] = [0.0, $box];
            if ($i === 0) {
                if ($line->rtl) { $hi -= $indent; } else { $lo += $indent; }
            }
            $align = $p->align;
            if ($line->rtl) {
                $align = match ($align) { Params::START => Params::END, Params::END => Params::START, default => $align };
            }
            // The last line of a paragraph, and a line an ellipsis ends, keep the alignment.
            $last = $line->end === $n || $runes[$line->end] === 0x0A || $l->truncated && $i === count($l->lines) - 1;
            if ($justify && !$last && ($left = self::justifyLine($line, $runes, $hi - $lo)) !== null) {
                [$align, $lo, $justified] = [Params::LEFT, $lo - $left, true]; // its words fill the room, whatever its whitespace's end
            }
            $dx = match ($align) { Params::CENTER => $lo + ($hi - $lo - $line->width) / 2, Params::END, Params::RIGHT => $hi - $line->width, default => $lo };
            if ($dx != 0) {
                $line->x += $dx;
                foreach ($line->glyphs as $g) { $g->x += $dx; }
            }
        }
        if ($justified) { // the lines stretched are as wide as the width, or wider by the whitespace they keep
            foreach ($l->lines as $i => $line) {
                $l->width = max($l->width, $line->width + ($i === 0 ? $indent : 0.0));
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
        $hy = in_array(Hyphens::SOFT, $text, true) ? new Hyphens($this->engine, $style, $spans) : null;
        $indent = $start === 0 ? max($p->indent, 0.0) : 0.0;
        $shaped = [];
        if ($text !== []) {
            $width = $p->noWrap ? 0.0 : max($p->width, 0.0);
            $shaped = $this->breakLines($text, $style, $spans, $width, $indent, $rtl, $p->noBreakWords, $hy);
        }
        if ($shaped === []) {
            $shaped = [['start' => 0, 'end' => count($text), 'runs' => []]];
        }
        $truncated = false;
        if ($maxLines > 0 && (count($shaped) > $maxLines || count($shaped) === $maxLines && $continues)) {
            [$from, $width] = [$shaped[$maxLines - 1]['start'], $p->width];
            if ($from === 0 && $width > 0) { $width = max($width - $indent, 1.0); } // the first line, beside its indent
            $last = $this->ellipsize($text, $spans, $from, $p, $width, $style, $rtl);
            $shaped = [...array_slice($shaped, 0, $maxLines - 1), $last];
            $truncated = true;
        }
        if ($hy !== null) {
            foreach ($shaped as $k => $sl) {
                $broken = !$truncated || $k < count($shaped) - 1;
                $shaped[$k] = $hy->place($sl, $text, $broken && Hyphens::softBreak($sl, $text) >= 0, $rtl);
            }
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
     * Breaks $text, a paragraph without newlines, into lines of at most
     * $width points (one line when 0), the first $indent narrower, and
     * shapes them with the engine, which breaks at one width (mygo's
     * breakLines). A line that breaks at a soft hyphen ends with a hyphen,
     * for which an engine may leave no room: one that does not fit with
     * it is broken again, narrower by the hyphen. What follows the
     * indented line, or a line that broke elsewhere then, is broken anew:
     * a paragraph without either is shaped once. $hy is null for a text
     * without soft hyphens.
     * @param list<int> $text
     * @param list<Span> $spans
     */
    private function breakLines(array $text, Style $style, array $spans, float $width, float $indent, bool $rtl, bool $wholeWords, ?Hyphens $hy): array
    {
        $e = $this->engine;
        if ($width <= 0) {
            return $e->shape($text, $style, $spans, 0, $rtl, $wholeWords);
        }
        if ($indent <= 0 && $hy === null) {
            return $e->shape($text, $style, $spans, $width, $rtl, $wholeWords);
        }
        $out = [];
        $n = count($text);
        for ($pos = 0; $pos < $n;) {
            $first = $out === [] && $indent > 0;
            $limit = $first ? max($width - $indent, 1.0) : $width;
            $lines = $e->shape(array_slice($text, $pos), $style, Span::in($spans, $pos, $n), $limit, $rtl, $wholeWords);
            if ($lines === []) {
                break;
            }
            if ($first) {
                $lines = array_slice($lines, 0, 1); // the lines after the first are for the width
            }
            $lines = self::offsetLines($lines, $pos);
            foreach ($lines as $l) {
                if (($c = Hyphens::softBreak($l, $text)) >= 0 && $hy !== null && $hy->lineWidth($l, $text, $c) > $limit + 1e-3) {
                    $narrow = $e->shape(array_slice($text, $l['start'], $l['end'] - $l['start']), $style, Span::in($spans, $l['start'], $l['end']), max($limit - $hy->width($c), 1.0), $rtl, $wholeWords);
                    if ($narrow !== [] && $narrow[0]['end'] !== $l['end'] - $l['start'] && $hy->opportunity($text, $l['start'] + $narrow[0]['end'])) {
                        $out[] = self::offsetLines([$narrow[0]], $l['start'])[0];
                        break;
                    }
                    // Nothing before fits but a part of a word: the hyphen overflows, as a word too long for a line does.
                }
                $out[] = $l;
            }
            $end = $out[count($out) - 1]['end'];
            if ($end <= $pos) {
                break;
            }
            $pos = $end;
        }
        return $out;
    }

    /** $lines an engine shaped from rune $offset of a paragraph, moved to their places in it (mygo's offsetLines). */
    private static function offsetLines(array $lines, int $offset): array
    {
        if ($offset === 0) {
            return $lines;
        }
        foreach ($lines as &$l) {
            $l['start'] += $offset; $l['end'] += $offset;
            foreach ($l['runs'] as &$r) {
                $r['start'] += $offset; $r['end'] += $offset;
                foreach ($r['glyphs'] as $g) {
                    if ($g->cluster >= 0) { $g->cluster += $offset; }
                }
            }
            unset($r);
        }
        unset($l);
        return $lines;
    }

    /**
     * Stretches $line, of a layout of $runes, to $width points, the
     * whitespace ending it left out, widening its spaces between words
     * and the room after its Han characters and kana but the last, by as
     * much each (mygo's justifyLine). Returns the left of what it
     * stretched, relative to the line, which whitespace ending a
     * right-to-left line may be left of; null, leaving the line as it was,
     * when it has nothing to widen or is that wide already.
     * @param list<int> $runes
     */
    private static function justifyLine(Line $line, array $runes, float $width): ?float
    {
        $end = $line->end;
        while ($end > $line->start && self::isSpace($runes[$end - 1])) { $end--; }
        // The extent of each cluster of what stretches, by its first rune, and the glyph of each that widens, its rightmost.
        $clusters = [];
        $content = 0.0; $last = -1; $left = 1e30;
        foreach ($line->glyphs as $i => $g) {
            $c = $g->cluster - $line->start;
            if ($c < 0 || $c >= $end - $line->start) {
                continue;
            }
            $content += $g->advance;
            $left = min($left, $g->x);
            $last = max($last, $c);
            if (!isset($clusters[$c])) {
                $clusters[$c] = [$g->x, $g->x + $g->advance, $i];
                continue;
            }
            $clusters[$c][0] = min($clusters[$c][0], $g->x);
            if ($g->x + $g->advance >= $clusters[$c][1]) {
                [$clusters[$c][1], $clusters[$c][2]] = [$g->x + $g->advance, $i];
            }
        }
        $extra = $width - $content;
        if ($extra <= 1e-3) {
            return null;
        }
        $opps = []; // the middles of the clusters that widen
        foreach ($clusters as $c => $cl) {
            if ($c !== $last && self::stretches($runes[$line->start + $c])) { $opps[] = ($cl[0] + $cl[1]) / 2; }
        }
        if ($opps === []) {
            return null;
        }
        $each = $extra / count($opps);
        // Each glyph moves by the room the clusters left of it gain: a cluster right of the line's content, as whitespace ending it, moves too.
        foreach ($line->glyphs as $g) {
            $c = $g->cluster - $line->start;
            $mid = isset($clusters[$c]) ? ($clusters[$c][0] + $clusters[$c][1]) / 2 : $g->x + $g->advance / 2;
            $k = 0;
            foreach ($opps as $o) { if ($o < $mid) { $k++; } }
            $g->x += $each * $k;
        }
        foreach ($clusters as $c => $cl) {
            if ($c !== $last && self::stretches($runes[$line->start + $c])) { $line->glyphs[$cl[2]]->advance += $each; }
        }
        $line->width += $extra; // no cluster that widens is left of the content's leftmost: it stays
        return $left;
    }

    /** Whether justified lines widen the room after rune $r: a space between words, as CSS's word separators, or a Han character, kana or the punctuation written with them, which lines of Chinese and Japanese text take apart (mygo's stretches). */
    private static function stretches(int $r): bool
    {
        if ($r === 0x20 || $r === 0xA0 || $r === 0x1361 || $r === 0x3000 || $r >= 0x3001 && $r <= 0x303F || $r >= 0xFF01 && $r <= 0xFF60) {
            return true;
        }
        return $r >= 0x2E80 && preg_match('/^[\p{Han}\p{Hiragana}\p{Katakana}\p{Bopomofo}]$/u', (string)mb_chr($r, 'UTF-8')) === 1;
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
                    $sp = self::spanAt($spans, $g->cluster);
                    if ($sp !== null) { // a span shifted off the baseline takes its glyphs and the room they need with it
                        $shift = $sp->baselineShift;
                        if ($sp->objectWidth > 0) { // an inline object stands on the baseline, as high as it is and as deep, whatever font its run took
                            $g->object = true; $g->advance = $sp->objectWidth; $g->id = 0;
                            $line->ascent = max($line->ascent, $sp->objectHeight + $shift);
                            $line->descent = max($line->descent, $sp->objectDescent - $shift);
                        } elseif ($shift != 0 && $g->font !== null) {
                            $line->ascent = max($line->ascent, $g->font->ascent + $shift);
                            $line->descent = max($line->descent, $g->font->descent - $shift);
                        }
                        $g->y -= $shift;
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
     * are left out; an underline skips the ink of the glyphs crossing it,
     * as AppKit's does, in pieces. Each is x0, x1, top and bottom in points.
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
        $style = $l->params->style; $shift = 0.0;
        foreach ($l->params->spans as $sp) {
            if ($gs[$i]->cluster < $sp->end) {
                [$style, $shift] = [$sp->style($style), $sp->baselineShift]; // the line of a span shifted off the baseline goes with it
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
        $pieces = [[$x0, $x1]];
        if ($d === self::UNDERLINE) {
            $cuts = []; // around the ink of each glyph crossing the line, a thickness further on either side
            for ($k = $i; $k < $j; $k++) {
                $g = $gs[$k];
                if ($g->font === null || $g->object || !self::skipsInk($rune($k))) {
                    continue;
                }
                $ink = $this->inkInBand($g->font, $g->id, -($center + $th / 2), -($center - $th / 2));
                if ($ink !== false) {
                    $cuts[] = [$g->x + $ink[0] - $th, $g->x + $ink[1] + $th];
                }
            }
            $pieces = self::cut($x0, $x1, $cuts, 0.75 * $th);
        }
        $base = round(($y + $line->baseline - $shift) * $scale);
        $top = $base + ($center - $th / 2) * $scale; $bottom = $base + ($center + $th / 2) * $scale;
        if ($thick > 0) {
            $h = max(round($thick * $scale), 1.0);
            $top = round(($top + $bottom - $h) / 2); $bottom = $top + $h;
        }
        return array_map(fn(array $p): array => [$x + $p[0], $x + $p[1], $top / $scale, $bottom / $scale], $pieces);
    }

    /** Whether rune $r's glyph cuts the underlines skipping ink: Han, Kana and Hangul do not, as in AppKit (mygo's skipsInk). */
    private static function skipsInk(int $r): bool
    {
        return !self::isSpace($r) && preg_match('/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]/u', (string)mb_chr($r, 'UTF-8')) !== 1;
    }

    /**
     * How far left and right of its origin the outline of glyph $glyph of
     * $f reaches between $lo and $hi points above the baseline, or false
     * where it does not (mygo's inkInBand); kept, 4096 at most.
     *
     * @return array{0: float, 1: float}|false
     */
    private function inkInBand(Font $f, int $glyph, float $lo, float $hi): array|false
    {
        $key = spl_object_id($f) . ":$glyph:$lo:$hi";
        if (array_key_exists($key, $this->bands)) {
            return $this->bands[$key];
        }
        if (count($this->bands) >= 4096) {
            $this->bands = [];
        }
        return $this->bands[$key] = self::bandX($this->engine->outline($f, $glyph), $lo, $hi);
    }

    /**
     * The left and right of the parts of $segments ([x0, y0, x1, y1] each)
     * within $lo ≤ y ≤ $hi, or false where none is (mygo's bandX).
     *
     * @param list<array{0: float, 1: float, 2: float, 3: float}> $segments
     * @return array{0: float, 1: float}|false
     */
    public static function bandX(array $segments, float $lo, float $hi): array|false
    {
        $a = INF; $b = -INF;
        foreach ($segments as [$px, $py, $qx, $qy]) {
            $t0 = 0.0; $t1 = 1.0;
            $dy = $qy - $py;
            if ($dy == 0) {
                if ($py < $lo || $py > $hi) {
                    continue;
                }
            } else {
                $ta = ($lo - $py) / $dy; $tb = ($hi - $py) / $dy;
                if ($ta > $tb) {
                    [$ta, $tb] = [$tb, $ta];
                }
                $t0 = max($t0, $ta); $t1 = min($t1, $tb);
                if ($t0 > $t1) {
                    continue;
                }
            }
            $xa = $px + ($qx - $px) * $t0; $xb = $px + ($qx - $px) * $t1;
            $a = min($a, $xa, $xb); $b = max($b, $xa, $xb);
        }
        return $a <= $b ? [$a, $b] : false;
    }

    /**
     * [$x0, $x1] less the intervals of $cuts, leaving out pieces no longer
     * than $min (mygo's cut).
     *
     * @param list<array{0: float, 1: float}> $cuts
     * @return list<array{0: float, 1: float}>
     */
    public static function cut(float $x0, float $x1, array $cuts, float $min): array
    {
        usort($cuts, fn(array $p, array $q): int => $p[0] <=> $q[0]);
        $out = [];
        $at = $x0;
        foreach ($cuts as [$c0, $c1]) {
            if ($c0 > $at && ($end = min($c0, $x1)) - $at > $min) {
                $out[] = [$at, $end];
            }
            $at = max($at, $c1);
            if ($at >= $x1) {
                break;
            }
        }
        if ($x1 - $at > $min) {
            $out[] = [$at, $x1];
        }
        return $out;
    }

    /** The span of rune $i if it makes it an inline object or shifts it off the baseline, else null (mygo's spanAt). @param list<Span> $spans */
    private static function spanAt(array $spans, int $i): ?Span
    {
        foreach ($spans as $sp) {
            if ($sp->end > $i) {
                return $sp->objectWidth > 0 || $sp->baselineShift != 0 ? $sp : null;
            }
        }
        return null;
    }

    /**
     * The paragraph $text from rune $start on one line ending with an
     * ellipsis: as many of its graphemes as fit $width with it (all of
     * them for 0).
     *
     * @param list<int> $text
     * @param list<Span> $spans
     */
    private function ellipsize(array $text, array $spans, int $start, Params $p, float $width, Style $style, bool $rtl): array
    {
        $rest = array_slice($text, $start);
        $restSpans = Span::in($spans, $start, count($text));
        $mark = System::runes($p->ellipsis !== '' ? $p->ellipsis : '…');
        $cut = count($rest);
        if ($width > 0) {
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
                if ($w + $gw + $ellipsis > $width) {
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
