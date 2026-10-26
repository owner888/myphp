<?php
declare(strict_types=1);

namespace MyPHP\UI;

/**
 * The lines a text breaks into at a width: paragraphs at "\n", then
 * greedy wrapping at the last space that fits, or between characters
 * where there is none (CJK, long words). Each line is a byte range of
 * the text; the break character, if any, belongs to the line before it.
 *
 * A paragraph is wrapped when something asks for its lines, as mygo's
 * text area lays out only the paragraphs in view: until then it counts as
 * one line, so a document of a hundred thousand lines shows at once, and
 * what is below the view moves down as the paragraphs above it are
 * wrapped. A layout made from another of the same size and width (an
 * edit's) keeps the lines of the paragraphs whose text it shares.
 */
final class FieldLayout
{
    /** @var list<int> the byte each paragraph starts at */
    private array $starts = [];
    /** @var array<int, list<array{0: int, 1: int}>> the lines of each paragraph wrapped so far, byte start and end (exclusive) */
    private array $wrapped = [];
    /** @var list<int> the lines of the paragraphs, a Fenwick tree (1-based) of their counts, one for each not wrapped yet */
    private array $tree = [];
    /** @var array<string, list<array{0: int, 1: int}>> the lines of the paragraphs wrapped, by their text and newline (key()), from the paragraph's start */
    private array $known = [];

    private function __construct(private readonly TextMetrics $m, public readonly string $text, public readonly float $size, public readonly float $width, public readonly float $lineHeight, public readonly int $style) {}

    /** The layout of $text at $width in the Font bits $style; $before, a layout of the same size, width and style, lends the lines of the paragraphs the texts share. */
    public static function wrap(TextMetrics $m, string $text, float $size, float $width, ?self $before = null, int $style = 0): self
    {
        [, $lh] = $m->measure('X', $size, $style);
        $l = new self($m, $text, $size, $width, $lh, $style);
        $l->starts[] = 0;
        for ($pos = strpos($text, "\n"); $pos !== false; $pos = strpos($text, "\n", $pos + 1)) {
            $l->starts[] = $pos + 1;
        }
        $n = count($l->starts);
        for ($i = 1; $i <= $n; $i++) {
            $l->tree[$i] = $i & -$i; // a line each
        }
        if ($before !== null && $before->size === $size && $before->width === $width && $before->style === $style) {
            // A paragraph wrapped before is where it was, or as many paragraphs on as the edit added: it keeps its lines, and those below their place.
            $shift = $n - count($before->starts);
            foreach ($before->wrapped as $p => $_) {
                $key = $before->key($p);
                $l->known[$key] = $before->known[$key];
                foreach ($shift === 0 ? [$p] : [$p, $p + $shift] as $q) {
                    if ($q >= 0 && $q < $n && $l->key($q) === $key) { $l->lines($q); break; }
                }
            }
        }
        return $l;
    }

    /** How many lines the text has: those of the paragraphs wrapped, and one for each other. */
    public function count(): int
    {
        return $this->prefix(count($this->starts));
    }

    public function height(): float
    {
        return $this->count() * $this->lineHeight;
    }

    /** Wraps the paragraphs from the first until they make $rows lines, or all of them: for a text area as high as its lines. */
    public function measureFirst(int $rows): void
    {
        for ($p = 0, $n = count($this->starts); $p < $n && $this->prefix($p) < $rows; $p++) {
            $this->lines($p);
        }
    }

    /** Wraps the paragraphs above the one holding byte $byte until they make $rows lines, or all of them: those a view ending at it shows. */
    public function measureAbove(int $byte, int $rows): void
    {
        $p = $this->paragraphAt($this->lineOf($byte));
        for ($q = $p - 1; $q >= 0 && $this->prefix($p) - $this->prefix($q) < $rows; $q--) { // $q's lines counted once wrapped: the loop looks again
            $this->lines($q);
        }
    }

    /** Line $i: its byte start and end. @return array{0: int, 1: int} */
    public function line(int $i): array
    {
        $p = $this->paragraphAt($i);
        $lines = $this->lines($p);
        return $lines[max(0, min($i - $this->prefix($p), count($lines) - 1))];
    }

    /** The line holding byte $byte: a byte at a line's end starts the next line; the text's end is on the last. */
    public function lineOf(int $byte): int
    {
        $lo = 0; $hi = count($this->starts) - 1; // the last paragraph starting at or before $byte
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi + 1, 2);
            if ($this->starts[$mid] <= $byte) { $lo = $mid; } else { $hi = $mid - 1; }
        }
        $lines = $this->lines($lo);
        foreach ($lines as $k => [, $end]) {
            if ($byte < $end) {
                return $this->prefix($lo) + $k;
            }
        }
        return $this->prefix($lo) + count($lines) - 1;
    }

    /** Line $i's text without its newline, what is shaped and painted. */
    public function lineText(int $i): string
    {
        [$a, $b] = $this->line($i);
        if ($b > $a && $this->text[$b - 1] === "\n") {
            $b--;
        }
        return substr($this->text, $a, $b - $a);
    }

    /** The caret's x and the line's top for byte $byte, in points from the layout's top left. */
    public function caret(TextMetrics $m, int $byte): array
    {
        $i = $this->lineOf($byte);
        $line = $this->lineText($i);
        $at = max(0, min($byte - $this->line($i)[0], strlen($line)));
        return [$m->xForIndex($line, $this->size, $at, $this->style), $i * $this->lineHeight];
    }

    /** The byte nearest to ($x, $y) from the layout's top left. */
    public function hit(TextMetrics $m, float $x, float $y): int
    {
        $i = max(0, min($this->count() - 1, (int)floor($y / $this->lineHeight)));
        $line = $this->lineText($i);
        [$start, $end] = $this->line($i);
        $byte = $start + $m->indexForX($line, $this->size, max(0.0, $x), $this->style);
        if ($i < $this->count() - 1 && $byte === $end && $line !== '' && $line[-1] === ' ') {
            $byte--; // after the wrapping space would be the next line's start
        }
        return $byte;
    }

    /** The paragraph line $i is in, as the lines are counted now. */
    public function paragraphAt(int $i): int
    {
        $n = count($this->starts);
        $p = 0; $left = max(0, $i);
        for ($step = 1 << (int)floor(log(max($n, 1), 2)); $step > 0; $step >>= 1) { // the most paragraphs whose lines are no more than $i
            if ($p + $step <= $n && $this->tree[$p + $step] <= $left) {
                $p += $step;
                $left -= $this->tree[$p];
            }
        }
        return min($p, $n - 1);
    }

    /** The first line of paragraph $p: the lines of those before it. */
    public function firstLineOf(int $p): int
    {
        return $this->prefix(max(0, min($p, count($this->starts))));
    }

    /** The lines of paragraph $p, wrapped now if they were not. @return list<array{0: int, 1: int}> */
    public function lines(int $p): array
    {
        if (isset($this->wrapped[$p])) {
            return $this->wrapped[$p];
        }
        $pos = $this->starts[$p];
        $rel = $this->known[$this->key($p)] ??= $this->breaks($this->paragraph($p), $p < count($this->starts) - 1);
        $lines = [];
        foreach ($rel as [$a, $b]) { $lines[] = [$pos + $a, $pos + $b]; }
        $this->wrapped[$p] = $lines;
        for ($i = $p + 1, $n = count($this->starts), $d = count($lines) - 1; $d !== 0 && $i <= $n; $i += $i & -$i) {
            $this->tree[$i] += $d;
        }
        return $lines;
    }

    /** What the lines of paragraph $p are known by: its text with its newline, which its last line holds. */
    private function key(int $p): string
    {
        return $p + 1 < count($this->starts) ? $this->paragraph($p) . "\n" : $this->paragraph($p);
    }

    /** The text of paragraph $p, without its newline. */
    private function paragraph(int $p): string
    {
        $a = $this->starts[$p];
        $b = $p + 1 < count($this->starts) ? $this->starts[$p + 1] - 1 : strlen($this->text);
        return substr($this->text, $a, $b - $a);
    }

    /** The lines of $para from its start; the newline after it, when $newline, belongs to the last. @return list<array{0: int, 1: int}> */
    private function breaks(string $para, bool $newline): array
    {
        $out = []; $start = 0; $plen = strlen($para);
        do {
            $fit = $plen === 0 ? 0 : self::fit($this->m, $para, $this->size, $this->style, $start, $this->width);
            $out[] = [$start, $fit + ($fit === $plen && $newline ? 1 : 0)];
            $start = $fit;
        } while ($start < $plen);
        return $out;
    }

    /** The lines of the paragraphs before $p. */
    private function prefix(int $p): int
    {
        $sum = 0;
        for ($i = $p; $i > 0; $i -= $i & -$i) {
            $sum += $this->tree[$i];
        }
        return $sum;
    }

    /** The end of the line starting at $start of $para: as much as fits, broken after a space when one is there. */
    private static function fit(TextMetrics $m, string $para, float $size, int $style, int $start, float $width): int
    {
        $plen = strlen($para);
        $x0 = $m->xForIndex($para, $size, $start, $style);
        if ($m->xForIndex($para, $size, $plen, $style) - $x0 <= $width) {
            return $plen;
        }
        $lo = $start; $hi = $plen; // the largest end whose text fits
        while ($hi - $lo > 1) {
            $mid = self::boundary($para, intdiv($lo + $hi, 2));
            if ($mid <= $lo) { $mid = self::nextChar($para, $lo); }
            if ($mid >= $hi) { break; }
            if ($m->xForIndex($para, $size, $mid, $style) - $x0 <= $width) { $lo = $mid; } else { $hi = $mid; }
        }
        $end = $lo;
        $space = strrpos(substr($para, $start, $end - $start), ' ');
        if ($space !== false && $start + $space + 1 < $plen) {
            $end = $start + $space + 1; // the space ends the line
        }
        if ($end <= $start) {
            $end = self::nextChar($para, $start); // one character at least
        }
        return $end;
    }

    /** Backs $i up to a UTF-8 character boundary. */
    private static function boundary(string $s, int $i): int
    {
        while ($i > 0 && $i < strlen($s) && (ord($s[$i]) & 0xC0) === 0x80) { $i--; }
        return $i;
    }

    private static function nextChar(string $s, int $i): int
    {
        $n = strlen($s);
        if ($i >= $n) { return $n; }
        $i++;
        while ($i < $n && (ord($s[$i]) & 0xC0) === 0x80) { $i++; }
        return $i;
    }
}
