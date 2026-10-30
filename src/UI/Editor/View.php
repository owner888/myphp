<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

use MyPHP\UI\{DisplayList, Font, TextPainter};

/**
 * Shows the lines of a Buffer (mygo's editor view): it keeps the display
 * model in step with the text, lays out the lines in view a row at a
 * time, and paints them with the colors of their tokens. An editor has
 * one; a diff one per side. Rows are shaped by the text engine, which
 * keeps each row's layout while frames draw it (Text's line cache, as
 * mygo's shapeCache keeps two frames'); long rows are pieces of at most
 * PIECE_BYTES placed by their widths.
 */
final class View
{
    /** Where tabs stop expanding, which bounds the work of a line, as Zed's MAX_EXPANSION_COLUMN. */
    public const MAX_TAB_COLUMN = 256;
    private const PIECE_BYTES = 2048;
    private const ASCII_PRINTABLE = " !\"#$%&'()*+,-./0123456789:;<=>?@ABCDEFGHIJKLMNOPQRSTUVWXYZ[\\]^_`abcdefghijklmnopqrstuvwxyz{|}~";

    public ?Buffer $buf = null;
    public RopeText $text;
    public int $version = 0;
    /** The buffer's highlighter, as of the last frame, and how many the view had. */
    public ?Highlighter $hl = null;
    public int $hlGen = 0;
    public bool $readOnly = false;

    public ?TextPainter $m = null;
    public float $size = 0.0;
    public int $style = Font::MONO;
    public float $lineHeight = 0.0;
    public float $ascent = 0.0, $descent = 0.0;
    /** The advance of "0", for columns. */
    public float $charWidth = 0.0;
    public int $tabSize = 4;
    public bool $wrap = false;
    public float $wrapWidth = 0.0;
    public float $scale = 0.0;
    public ?Theme $theme = null;
    /** @var array<int, int> the faces of the kinds of tokens by the theme (Font::BOLD, Font::ITALIC bits); whether any is not the regular one */
    private array $faces = [];
    private bool $styled = false;
    /** @var array<int, array<string, float>> the advances of characters in the bold and italic faces, by face, as $ascii and $other are the regular's */
    private array $faceWidths = [];

    public Heights $heights;
    /** @var list<array{at: int, text: string, kind: int}> texts shown in lines that are not part of them, as inlay hints (kind 0) or a suggestion (1), sorted by offset */
    public array $inlays = [];

    /** @var ?array<int, LineLayout> */
    private ?array $lines = null;
    private int $frame = 0;
    /** @var array<int, float> the advances of ASCII characters, and of others as measured */
    private array $ascii = [];
    /** @var array<string, float> */
    private array $other = [];
    /** The advance of every printable ASCII character, if one. */
    public float $mono = -1.0;
    /** The widest line laid out, for scrolling sideways. */
    public float $maxLine = 0.0;
    /** The next line to measure the wraps of. */
    public int $wrapAt = 0;
    /** A frame showed tokens from states the highlighter has not confirmed yet. */
    public bool $approx = false;

    public function __construct()
    {
        $this->text = new RopeText;
        $this->heights = new Heights;
    }

    /** Sets the font size and the line height (a multiple of it), and the scale of the display, which line heights snap to. */
    public function setStyle(TextPainter $m, float $size, float $lineHeight, float $scale, int $tabSize): void
    {
        if ($size <= 0) { $size = 13.0; }
        if ($lineHeight <= 0) { $lineHeight = 1.5; }
        $lh = round($size * $lineHeight * $scale) / $scale;
        $tabSize = max(1, min($tabSize, 16));
        if ($this->m === $m && $this->size === $size && $this->lineHeight === $lh && $this->tabSize === $tabSize && $this->scale === $scale) { return; }
        $fontChanged = $this->m !== $m || $this->size !== $size || $this->scale !== $scale;
        $this->m = $m; $this->size = $size; $this->lineHeight = $lh; $this->tabSize = $tabSize; $this->scale = $scale;
        $this->heights->lh = $lh;
        $this->heights->dirty = true;
        $this->ascent = $m->ascent($size, $this->style);
        $this->descent = $m->measure('X', $size, $this->style)[1] - $this->ascent;
        $this->resetWidths();
        $this->charWidth = $this->widthOf('0');
        if ($fontChanged) { $this->maxLine = 0.0; }
        $this->lines = null;
        if ($this->wrap) {
            $this->heights->wraps->reset($this->text->lineCount(), true);
            $this->wrapAt = 0;
        }
    }

    /** Measures characters for soft wraps and long lines without shaping them, as Zed's LineWrapper sums cached advances. */
    private function resetWidths(): void
    {
        $this->ascii = []; $this->other = []; $this->mono = -1.0; $this->faceWidths = [];
        $mono = -1.0; $ok = true;
        $s = self::ASCII_PRINTABLE;
        for ($i = 0, $n = strlen($s); $i < $n; $i++) {
            $w = $this->m->xForIndex($s, $this->size, $i + 1, $this->style) - $this->m->xForIndex($s, $this->size, $i, $this->style);
            $this->ascii[ord($s[$i])] = $w;
            if ($mono < 0) { $mono = $w; } elseif (abs($mono - $w) > 0.01) { $ok = false; }
        }
        if ($ok) { $this->mono = $mono; }
    }

    /** The advance of rune $r. */
    public function widthOf(string $r): float
    {
        if (strlen($r) === 1) {
            if (isset($this->ascii[ord($r)])) { return $this->ascii[ord($r)]; }
            if (ord($r) < 0x20) { return 0.0; }
        }
        if (isset($this->other[$r])) { return $this->other[$r]; }
        $v = $this->m->measure($r, $this->size, $this->style)[0];
        if (count($this->other) < 4096) { $this->other[$r] = $v; }
        return $v;
    }

    /** The advance of rune $r in $face (Font bits), the regular's for 0. */
    private function faceWidthOf(string $r, int $face): float
    {
        if ($face === 0) { return $this->widthOf($r); }
        if (strlen($r) === 1 && ord($r) < 0x20) { return 0.0; }
        return $this->faceWidths[$face][$r] ??= $this->m->measure($r, $this->size, $this->style | $face)[0];
    }

    /** The width of displayed bytes $a to $b of $l, by the advances of its characters in their faces. */
    public function rangeWidth(LineLayout $l, int $a, int $b): float
    {
        $x = 0.0;
        foreach ($this->runsIn($l, $a, $b) as [$from, $to, $face]) {
            $s = substr($l->disp, $from, $to - $from);
            if ($face === 0) { $x += $this->width($s); continue; }
            foreach (mb_str_split($s, 1, 'UTF-8') as $r) { $x += $this->faceWidthOf($r, $face); }
        }
        return $x;
    }

    /** The runs of displayed bytes $a to $b of $l in one face each: [from, to, face]. @return list<array{0: int, 1: int, 2: int}> */
    public function runsIn(LineLayout $l, int $a, int $b): array
    {
        if ($l->faces === []) { return $a < $b ? [[$a, $b, 0]] : []; }
        $out = []; $prev = 0;
        foreach ($l->faces as [$end, $face]) {
            $from = max($prev, $a); $to = min($end, $b);
            if ($from < $to) { $out[] = [$from, $to, $face]; }
            $prev = $end;
        }
        if (($from = max($prev, $a)) < $b) { $out[] = [$from, $b, 0]; }
        return $out;
    }

    /** Sets the theme, laying out lines anew when the faces of tokens change (mygo's setTheme). */
    public function setTheme(Theme $ct): void
    {
        $this->theme = $ct;
        $faces = [];
        foreach ($ct->tokens as $k => $ts) {
            $f = ($ts['bold'] ? Font::BOLD : 0) | ($ts['italic'] ? Font::ITALIC : 0);
            if ($f !== 0) { $faces[$k] = $f; }
        }
        if ($faces !== $this->faces) {
            [$this->faces, $this->styled] = [$faces, $faces !== []];
            $this->lines = null;
        }
    }

    /** The faces of $l's tokens, by displayed bytes, with inlays regular: [end, face] runs, none when all are regular (mygo's facesOf). @return list<array{0: int, 1: int}> */
    private function facesOf(LineLayout $l): array
    {
        $runs = []; $styled = false;
        foreach ($l->toks as [$end, $kind]) {
            $f = $this->faces[$kind] ?? 0;
            $styled = $styled || $f !== 0;
            $e = $l->toDisp($end, false);
            $n = count($runs);
            if ($n > 0 && ($runs[$n - 1][1] === $f || $runs[$n - 1][0] >= $e)) { $runs[$n - 1][0] = max($runs[$n - 1][0], $e); continue; }
            $runs[] = [$e, $f];
        }
        if (!$styled) { return []; }
        foreach ($l->segs ?? [] as $i => $sg) {
            if ($sg['kind'] === LineLayout::SEG_INLAY) { $runs = self::regular($runs, $sg['disp'], $sg['disp'] + $l->segLen($i)); }
        }
        return $runs;
    }

    /** Runs $runs with displayed bytes $a to $b regular. @param list<array{0: int, 1: int}> $runs @return list<array{0: int, 1: int}> */
    private static function regular(array $runs, int $a, int $b): array
    {
        if ($a >= $b) { return $runs; }
        $out = [];
        $add = function (int $e, int $f) use (&$out): void {
            $n = count($out);
            if ($n > 0 && $out[$n - 1][0] >= $e) { return; }
            if ($n > 0 && $out[$n - 1][1] === $f) { $out[$n - 1][0] = $e; return; }
            $out[] = [$e, $f];
        };
        $prev = 0;
        foreach ($runs as [$e, $f]) {
            if ($prev < $a) { $add(min($e, $a), $f); }
            if ($e > $a && $prev < $b) { $add(min($e, $b), 0); }
            if ($e > $b) { $add($e, $f); }
            $prev = $e;
        }
        return $out;
    }

    /** The width of $s, by the advances of its characters. */
    public function width(string $s): float
    {
        if ($this->mono > 0 && !preg_match('/[\x80-\xFF]/', $s)) { return $this->mono * strlen($s); }
        $x = 0.0;
        foreach (mb_str_split($s, 1, 'UTF-8') as $r) { $x += $this->widthOf($r); }
        return $x;
    }

    /** Turns soft wrapping on at $width, or off. */
    public function setWrap(bool $on, float $width): void
    {
        $width = floor($width);
        if ($on === $this->wrap && (!$on || $width === $this->wrapWidth)) { return; }
        $this->wrap = $on; $this->wrapWidth = $width;
        $this->heights->wraps->reset($this->text->lineCount(), $on);
        $this->heights->dirty = true;
        $this->wrapAt = 0;
        $this->lines = null;
    }

    /** Shows another buffer. */
    public function setBuffer(Buffer $b): void
    {
        if ($this->buf === $b) { return; }
        $this->buf = $b;
        $this->text = $b->rope(); $this->version = $b->version();
        $this->hl = null;
        $this->lines = null;
        $this->maxLine = 0.0;
        $this->heights->setLines($this->text->lineCount());
        $this->heights->wraps->reset($this->text->lineCount(), $this->wrap);
        $this->wrapAt = 0;
    }

    /**
     * Follows the buffer's edits, calling $moved with the changes of each
     * version so the owner moves what it keeps of the text. Returns false
     * when the buffer changed past what its log keeps, and everything was
     * taken anew.
     */
    public function sync(?\Closure $moved): bool
    {
        if ($this->buf === null) { return true; }
        $to = $this->buf->changesTo($this->version);
        if ($to !== null && $to[1] === $this->version) { $this->text = $to[2]; return true; }
        $old = $this->text;
        $text = $this->buf->rope();
        $this->text = $text; $this->version = $this->buf->version();
        $this->lines = null;
        // The highlighter hears of the edits before a line of the text is laid out, as moving the caret into view does between frames.
        $this->followHighlighter();
        if ($to === null) {
            // Too far behind the buffer's log to follow its edits: the changes of the lines between the text it had and the text.
            $cs = Buffer::changesBetween($old, $text);
            $this->heights->setLines($text->lineCount());
            $this->heights->wraps->reset($text->lineCount(), $this->wrap);
            $this->wrapAt = 0;
            foreach ($this->inlays as &$in) { $in['at'] = Change::mapOffset($in['at'], $cs, Change::RIGHT); }
            unset($in);
            if ($moved !== null) { $moved($cs); }
            return false;
        }
        foreach ($to[0] as $cs) {
            foreach ($cs as $c) {
                // Lines from the change's first are measured anew.
                $this->heights->wraps->splice($c->newLine, $c->oldEndLine - $c->oldLine + 1, $c->newEndLine - $c->newLine + 1);
                $this->wrapAt = min($this->wrapAt, $c->newLine);
            }
            foreach ($this->inlays as &$in) { $in['at'] = Change::mapOffset($in['at'], $cs, Change::RIGHT); }
            unset($in);
            if ($moved !== null) { $moved($cs); }
        }
        $this->heights->setLines($text->lineCount());
        $this->heights->dirty = true;
        return true;
    }

    /** Has the buffer's highlighter work toward the tokens of the lines up to $upTo, for at most $budget seconds, and reports whether they are ready. */
    public function advanceSyntax(int $upTo, float $budget): bool
    {
        if ($this->buf === null) { return true; }
        $hl = $this->followHighlighter();
        return $hl === null || $hl->highlight($upTo, $budget);
    }

    /** Has the buffer's highlighter follow its edits, and returns it, null for plain text. */
    public function followHighlighter(): ?Highlighter
    {
        $hl = $this->buf->highlighter();
        if ($hl !== $this->hl) {
            // Another language: the lines laid out with the old one's tokens go, and what was found of its brackets.
            $this->hl = $hl;
            $this->lines = null;
            $this->hlGen++;
        }
        return $hl;
    }

    /** Line $n laid out. */
    public function line(int $n): LineLayout
    {
        $this->lines ??= [];
        if (isset($this->lines[$n])) {
            $l = $this->lines[$n];
            $l->used = $this->frame;
            if (!$l->exact) {
                // A guess: the highlighter may know better now. The layout depends on the tokens only through their faces.
                [$l->toks, $l->exact] = $this->tokens($n, $l->text);
                if ($this->styled && $this->facesOf($l) !== $l->faces) {
                    unset($this->lines[$n]);
                    return $this->line($n);
                }
            }
            return $l;
        }
        $start = $this->text->lineStart($n);
        $l = new LineLayout($n, $start, $this->text->slice($start, $this->text->lineEnd($n)));
        $l->used = $this->frame;
        [$l->toks, $l->exact] = $this->tokens($n, $l->text);
        $this->display($l);
        if ($this->styled) { $l->faces = $this->facesOf($l); }
        $this->layoutRows($l);
        $this->lines[$n] = $l;
        if ($l->width > $this->maxLine) { $this->maxLine = $l->width; }
        return $l;
    }

    /** Measures the wraps of line $i, if not yet. */
    public function wrapLineNow(int $i): void
    {
        $w = $this->heights->wraps;
        if (!$w->on || $i < 0 || $i >= count($w->extra) || $w->extra[$i] !== WrapRows::UNKNOWN) { return; }
        $this->line($i);
    }

    /** Forgets every line laid out, as inlays shown anew change them. */
    public function forgetLines(): void
    {
        $this->lines = null;
    }

    /**
     * The tokens of line $i, whose text is $s, without laying it out:
     * those of the line laid out when it is, else the highlighter's. Null
     * means the whole line is code, as a line starting outside strings and
     * comments without the characters that start them is, without
     * tokenizing it. @return ?list<array{0: int, 1: int}>
     */
    public function lineTokens(int $i, string $s): ?array
    {
        if (isset($this->lines[$i])) { return $this->lines[$i]->toks; }
        if ($this->hl === null || strlen($s) > TokenHighlighter::MAX_TOKENIZE) { return null; }
        // The default highlighter's lines starting in code without the characters starting strings and comments are all code.
        if ($this->hl instanceof TokenHighlighter && $this->hl->stateOf($i)[0] === 0 && strpbrk($s, "\"'`/#*-<%;") === false) { return null; }
        return $this->hl->tokens($i, $s, [])[0];
    }

    /** Forgets the lines no recent frame used. */
    public function endFrame(): void
    {
        $this->frame++;
        if ($this->lines !== null && count($this->lines) > 512) {
            foreach ($this->lines as $k => $l) {
                if ($this->frame - $l->used > 2) { unset($this->lines[$k]); }
            }
        }
    }

    /** The tokens of line $n, whose text is $s, and whether they are final. @return array{0: list<array{0: int, 1: int}>, 1: bool} */
    private function tokens(int $n, string $s): array
    {
        if ($this->hl === null) { return [[], true]; }
        [$toks, $ok] = $this->hl->tokens($n, $s, []);
        if (!$ok) { $this->approx = true; }
        return [$toks, $ok];
    }

    /** Makes the displayed text of $l: tabs expanded to the next tab stop, by columns counted in characters, and inlays inserted. */
    public function display(LineLayout $l): void
    {
        $s = $l->text;
        $inl = $this->inlaysIn($l->start, $l->start + strlen($s));
        if (!str_contains($s, "\t") && $inl === []) { $l->disp = $s; $l->segs = null; return; }
        $b = ''; $col = 0; $textFrom = 0; $ii = 0; $segs = [];
        $flush = function (int $to) use (&$b, &$textFrom, &$segs, $s) {
            if ($to > $textFrom) {
                $segs[] = ['disp' => strlen($b), 'from' => $textFrom, 'to' => $to, 'kind' => LineLayout::SEG_TEXT, 'inlay' => 0, 'ghost' => false];
                $b .= substr($s, $textFrom, $to - $textFrom);
            }
            $textFrom = $to;
        };
        $emitInlays = function (int $at) use (&$ii, $inl, $l, $flush, &$segs, &$b, &$col) {
            while ($ii < count($inl) && $inl[$ii]['at'] - $l->start <= $at) {
                $flush($at);
                $in = $inl[$ii];
                $segs[] = ['disp' => strlen($b), 'from' => $at, 'to' => $at, 'kind' => LineLayout::SEG_INLAY, 'inlay' => $ii, 'ghost' => $in['kind'] === 1];
                $b .= $in['text'];
                $col += mb_strlen($in['text'], 'UTF-8');
                $ii++;
            }
        };
        for ($i = 0, $n = strlen($s); $i < $n;) {
            $r = Chars::runeAt($s, $i);
            $emitInlays($i);
            if ($r === "\t") {
                $flush($i);
                $w = $col >= self::MAX_TAB_COLUMN ? 1 : $this->tabSize - $col % $this->tabSize;
                $segs[] = ['disp' => strlen($b), 'from' => $i, 'to' => $i + 1, 'kind' => LineLayout::SEG_TAB, 'inlay' => 0, 'ghost' => false];
                $b .= str_repeat(' ', $w);
                $col += $w;
                $textFrom = $i + 1;
                $i++;
                continue;
            }
            $col++;
            $i += strlen($r);
        }
        $emitInlays(strlen($s));
        $flush(strlen($s));
        $l->disp = $b;
        $l->segs = $segs;
    }

    /** @return list<array{at: int, text: string, kind: int}> */
    public function inlaysIn(int $a, int $b): array
    {
        $out = [];
        foreach ($this->inlays as $in) {
            if ($in['at'] > $b) { break; }
            if ($in['at'] >= $a) { $out[] = $in; }
        }
        return $out;
    }

    /** Wraps the displayed text of $l into rows. */
    private function layoutRows(LineLayout $l): void
    {
        $l->rows = [];
        $breaks = $this->wrapBreaks($l->disp, $l->faces);
        $indent = $breaks !== [] ? $this->wrapIndent($l->disp) : 0.0;
        $from = 0;
        for ($i = 0; $i <= count($breaks); $i++) {
            $to = $i < count($breaks) ? $breaks[$i] : strlen($l->disp);
            $w = $to - $from <= self::PIECE_BYTES && $breaks === [] ? $this->shapedWidth($l, $from, $to) : $this->rangeWidth($l, $from, $to);
            $row = ['from' => $from, 'to' => $to, 'indent' => $i > 0 ? $indent : 0.0, 'width' => $w];
            $l->rows[] = $row;
            $l->width = max($l->width, $row['indent'] + $w);
            $from = $to;
        }
        if ($this->wrap) { $this->heights->wraps->set($l->n, count($l->rows)); }
    }

    /** The pieces of row $r of $l, shaped apart: [from, to, x], of at most PIECE_BYTES each, placed by their widths. @return list<array{0: int, 1: int, 2: float}> */
    public function pieces(LineLayout $l, array $r): array
    {
        if ($r['to'] - $r['from'] <= self::PIECE_BYTES) { return [[$r['from'], $r['to'], 0.0]]; }
        $out = []; $x = 0.0;
        for ($a = $r['from']; $a < $r['to'];) {
            $b = min($a + self::PIECE_BYTES, $r['to']);
            while ($b < $r['to'] && (ord($l->disp[$b]) & 0xC0) === 0x80) { $b++; }
            $out[] = [$a, $b, $x];
            $x += $this->rangeWidth($l, $a, $b);
            $a = $b;
        }
        return $out;
    }

    /** The width of displayed bytes $a to $b of $l as shaped, a run in each face. */
    private function shapedWidth(LineLayout $l, int $a, int $b): float
    {
        $w = 0.0;
        foreach ($this->runsIn($l, $a, $b) as [$from, $to, $face]) { $w += $this->m->measure(substr($l->disp, $from, $to - $from), $this->size, $this->style | $face)[0]; }
        return $w;
    }

    /**
     * Where the displayed text $s breaks into rows when wrapping, as Zed's
     * LineWrapper: summing the advances of characters, breaking after
     * whitespace or between a word and what is not, anywhere in CJK, and
     * inside a word too long for a row; $faces are those of $s ([end,
     * face] runs), if known. @param list<array{0: int, 1: int}> $faces @return list<int>
     */
    public function wrapBreaks(string $s, array $faces = []): array
    {
        if (!$this->wrap || $this->wrapWidth <= 0) { return []; }
        $width = $this->wrapWidth;
        if ($faces === [] && $this->mono > 0 && strlen($s) * $this->mono <= $width) { return []; }
        $l = null;
        if ($faces !== []) { $l = new LineLayout(0, 0, $s); [$l->disp, $l->faces] = [$s, $faces]; }
        $breaks = []; $indent = 0.0; $x = 0.0; $start = 0; $last = -1; $prev = ''; $fi = 0;
        for ($i = 0, $n = strlen($s); $i < $n;) {
            $r = Chars::runeAt($s, $i);
            if ($i > $start && self::canBreak($prev, $r)) { $last = $i; }
            while ($fi < count($faces) && $faces[$fi][0] <= $i) { $fi++; }
            $w = $this->faceWidthOf($r, $faces[$fi][1] ?? 0);
            if ($x + $w > $width && $i > $start) {
                $at = $last > $start ? $last : $i;
                $breaks[] = $at;
                if ($indent == 0) { $indent = $this->wrapIndent($s); }
                // Measure the new row from its start, after the indent.
                $x = $indent + ($l === null ? $this->width(substr($s, $at, $i - $at)) : $this->rangeWidth($l, $at, $i));
                $start = $at; $last = -1;
            }
            $x += $w;
            $prev = $r;
            $i += strlen($r);
        }
        return $breaks;
    }

    /** How far rows after the first are indented: as far as the line, but no more than half the wrap width. */
    public function wrapIndent(string $s): float
    {
        $n = strlen($s) - strlen(ltrim($s, ' '));
        return min($this->width(substr($s, 0, $n)), $this->wrapWidth / 2);
    }

    private static function canBreak(string $prev, string $r): bool
    {
        if ($prev === ' ' || $prev === "\t") { return $r !== ' ' && $r !== "\t"; }
        if (self::isCJK($r) || self::isCJK($prev)) { return true; }
        return self::isWordRune($prev) !== self::isWordRune($r) && $r !== ' ' && $r !== "\t" && !str_contains(".,;:)]}>'\"", $r);
    }

    private static function isCJK(string $r): bool
    {
        if (strlen($r) < 3) { return false; }
        $c = mb_ord($r, 'UTF-8');
        return $c >= 0x2e80 && $c <= 0x9fff || $c >= 0xac00 && $c <= 0xd7af || $c >= 0xf900 && $c <= 0xfaff || $c >= 0xff00 && $c <= 0xffef;
    }

    public static function isWordRune(string $r): bool
    {
        return $r === '_' || $r !== '' && (strlen($r) === 1 ? ctype_alnum($r) : preg_match('/^[\p{L}\p{Nd}]$/u', $r) === 1);
    }

    /** Measures the wraps of lines not measured yet, from those around $near, for at most $budget seconds, and reports whether all are measured. */
    public function measureWraps(int $near, float $budget): bool
    {
        $w = $this->heights->wraps;
        if (!$w->on) { return true; }
        $n = $this->text->lineCount();
        if ($w->known >= $n) { return true; }
        $start = hrtime(true);
        $measure = function (int $i, int $at, string $s) use ($w): bool {
            if ($w->extra[$i] !== WrapRows::UNKNOWN) { return true; }
            $disp = $s;
            if (str_contains($s, "\t") || $this->inlaysIn($at, $at + strlen($s)) !== []) {
                $l = new LineLayout($i, $at, $s);
                $this->display($l);
                $disp = $l->disp;
            }
            $w->set($i, count($this->wrapBreaks($disp)) + 1);
            return true;
        };
        // Those around near first, so what shows is right at once.
        // Within 64 KB each way, and the clock read every 64 KB as well as every 256 lines (mygo's 9965d50): lines of 250 KB were measured in one frame.
        $a = $this->text->lineStart(max(0, min($near, $n - 1)));
        $this->text->eachLine(max($near - 200, $this->text->lineOf(max(0, $a - (64 << 10)))), min($near + 200, $this->text->lineOf(min($a + (64 << 10), $this->text->len())) + 1), $measure);
        $count = 0; $bytes = 0;
        $this->text->eachLine($this->wrapAt, $n, function (int $i, int $at, string $s) use ($measure, &$count, &$bytes, $start, $budget): bool {
            $measure($i, $at, $s);
            $this->wrapAt = $i + 1;
            $count++;
            $bytes += strlen($s);
            if (($count & 255) !== 0 && $bytes < 64 << 10) { return true; }
            $bytes = 0;
            return (hrtime(true) - $start) / 1e9 <= $budget;
        });
        return $w->known >= $n;
    }

    /** The x of displayed byte $d in row $r, from the row's start. */
    public function xOf(LineLayout $l, array $r, int $d): float
    {
        $d = max($r['from'], min($d, $r['to']));
        $pieces = $this->pieces($l, $r);
        foreach ($pieces as $i => [$a, $b, $px]) {
            if ($d < $b || $i === count($pieces) - 1) {
                $runs = $this->runsIn($l, $a, $b);
                foreach ($runs as $k => [$from, $to, $face]) {
                    $t = substr($l->disp, $from, $to - $from);
                    if ($d < $to || $k === count($runs) - 1) { return $r['indent'] + $px + $this->m->xForIndex($t, $this->size, $d - $from, $this->style | $face); }
                    $px += $this->m->measure($t, $this->size, $this->style | $face)[0];
                }
                return $r['indent'] + $px;
            }
        }
        return $r['indent'];
    }

    /** The displayed byte in row $r nearest to $x, from the row's start. */
    public function dispAt(LineLayout $l, array $r, float $x): int
    {
        $x -= $r['indent'];
        $d = $r['from'];
        $pieces = $this->pieces($l, $r);
        foreach ($pieces as $i => [$a, $b, $px]) {
            $pw = $i + 1 < count($pieces) ? $pieces[$i + 1][2] - $px : PHP_FLOAT_MAX;
            if ($x < $px + $pw || $i === count($pieces) - 1) {
                $runs = $this->runsIn($l, $a, $b);
                $d = $a;
                foreach ($runs as $k => [$from, $to, $face]) {
                    $t = substr($l->disp, $from, $to - $from);
                    $w = $this->m->measure($t, $this->size, $this->style | $face)[0];
                    if ($x < $px + $w || $k === count($runs) - 1) { $d = min($from + $this->m->indexForX($t, $this->size, $x - $px, $this->style | $face), $to); break; }
                    $px += $w;
                }
                break;
            }
        }
        // The end of a row a line wraps after is the start of the next, where a caret shows: on this row, it is before the row's last character, as Zed clips there.
        if ($d >= $r['to'] && $r['to'] < strlen($l->disp) && $r['to'] > $r['from']) {
            $d = $r['to'] - strlen(Chars::runeBefore($l->disp, $r['to']));
        }
        return $d;
    }

    /** The colors of $l's tokens, by displayed bytes, with inlays muted: [ends, colors]. @return array{0: list<int>, 1: list<?array{0: int, 1: float}>} */
    public function colorsOf(LineLayout $l): array
    {
        $ends = []; $colors = [];
        foreach ($l->toks as [$end, $kind]) {
            $ends[] = $l->toDisp($end, false);
            $colors[] = $this->theme->tokenColor($kind);
        }
        $cc = [$ends, $colors];
        foreach ($l->segs ?? [] as $i => $sg) {
            if ($sg['kind'] === LineLayout::SEG_INLAY) { $cc = self::overlay($cc, $sg['disp'], $sg['disp'] + $l->segLen($i), $this->theme->c['lineNumber']); }
        }
        return $cc;
    }

    /** Colors displayed bytes $a to $b $c in runs $cc. */
    public static function overlay(array $cc, int $a, int $b, array $c): array
    {
        if ($a >= $b) { return $cc; }
        $ends = []; $colors = [];
        $add = function (int $e, ?array $col) use (&$ends, &$colors) {
            $n = count($ends);
            if ($n > 0 && $ends[$n - 1] >= $e) { return; }
            $ends[] = $e; $colors[] = $col;
        };
        $prev = 0;
        foreach ($cc[0] as $i => $e) {
            $col = $cc[1][$i];
            if ($e <= $a || $prev >= $b) {
                $add($e, $col);
            } else {
                if ($prev < $a) { $add($a, $col); }
                $add(min($e, $b), $c);
                if ($e > $b) { $add($e, $col); }
            }
            $prev = $e;
        }
        if ($prev < $b) {
            if ($prev < $a) { $add($a, null); }
            $add($b, $c);
        }
        return [$ends, $colors];
    }

    /** The color of displayed byte $d in runs $cc, else $def. */
    public static function colorAt(array $cc, int $d, array $def): array
    {
        $lo = 0; $hi = count($cc[0]);
        while ($lo < $hi) { $m = ($lo + $hi) >> 1; if ($cc[0][$m] >= $d + 1) { $hi = $m; } else { $lo = $m + 1; } }
        return $lo < count($cc[1]) && $cc[1][$lo] !== null && $cc[1][$lo][1] > 0 ? $cc[1][$lo] : $def;
    }

    /** Paints the glyphs of row $r of $l, its start at $x and its top at $y, colored by $cc, or by $override($d, $color) when given; only the pieces within $clipX..$clipX+$clipW. */
    public function paintRow(DisplayList $list, LineLayout $l, array $r, float $x, float $y, array $cc, ?\Closure $override, float $clipX, float $clipW): void
    {
        $lineTop = $y + ($this->lineHeight - ($this->ascent + $this->descent)) / 2;
        $def = $this->theme->c['text'];
        foreach ($this->pieces($l, $r) as $i => [$a, $b, $px0]) {
            $px = $x + $r['indent'] + $px0;
            $pw = $this->rangeWidth($l, $a, $b);
            if ($px + $pw < $clipX - 64 || $px > $clipX + $clipW + 64) { continue; }
            $runs = $this->runsIn($l, $a, $b);
            foreach ($runs as [$from, $to, $face]) {
                $t = substr($l->disp, $from, $to - $from);
                $this->m->paintRuns($list, $t, $this->size, $px, $lineTop, $this->style | $face, function (int $byte) use ($from, $cc, $def, $override): array {
                    $d = $from + $byte;
                    $c = self::colorAt($cc, $d, $def);
                    return $override !== null ? $override($d, $c) : $c;
                });
                if (count($runs) > 1) { $px += $this->m->measure($t, $this->size, $this->style | $face)[0]; }
            }
        }
    }

    /** The rectangles [x, w], relative to row $r's start, covering displayed bytes $a to $b of the row. @return list<array{0: float, 1: float}> */
    public function rangeRects(LineLayout $l, array $r, int $a, int $b): array
    {
        $a = max($a, $r['from']); $b = min($b, $r['to']);
        if ($a >= $b) { return []; }
        $x0 = $this->xOf($l, $r, $a); $x1 = $this->xOf($l, $r, $b);
        return [[min($x0, $x1), abs($x1 - $x0)]];
    }
}
