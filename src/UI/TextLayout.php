<?php
declare(strict_types=1);

namespace MyPHP\UI;

/**
 * The lines a text breaks into at a width: paragraphs at "\n", then
 * greedy wrapping at the last space that fits, or between characters
 * where there is none (CJK, long words). Each line is a byte range of
 * the text; the break character, if any, belongs to the line before it.
 */
final class TextLayout
{
    /** @var list<array{0: int, 1: int}> byte start and end (exclusive) of each line */
    public array $lines = [];

    public function __construct(public readonly string $text, public readonly float $size, public readonly float $width, public readonly float $lineHeight) {}

    public static function wrap(TextMetrics $m, string $text, float $size, float $width): self
    {
        [, $lh] = $m->measure('X', $size);
        $l = new self($text, $size, $width, $lh);
        $pos = 0; $n = strlen($text);
        while (true) {
            $nl = strpos($text, "\n", $pos);
            $end = $nl === false ? $n : $nl;
            $para = substr($text, $pos, $end - $pos);
            $start = 0; $plen = strlen($para);
            do {
                $fit = self::fit($m, $para, $size, $start, $width);
                // The paragraph's newline belongs to its last line.
                $l->lines[] = [$pos + $start, $pos + $fit + ($fit === $plen && $nl !== false ? 1 : 0)];
                $start = $fit;
            } while ($start < $plen);
            if ($nl === false) {
                break;
            }
            $pos = $nl + 1;
            if ($pos === $n) {
                $l->lines[] = [$n, $n]; // a trailing newline shows an empty last line
                break;
            }
        }
        return $l;
    }

    /** The end of the line starting at $start of $para: as much as fits, broken after a space when one is there. */
    private static function fit(TextMetrics $m, string $para, float $size, int $start, float $width): int
    {
        $plen = strlen($para);
        $x0 = $m->xForIndex($para, $size, $start);
        if ($m->xForIndex($para, $size, $plen) - $x0 <= $width) {
            return $plen;
        }
        $lo = $start; $hi = $plen; // the largest end whose text fits
        while ($hi - $lo > 1) {
            $mid = self::boundary($para, intdiv($lo + $hi, 2));
            if ($mid <= $lo) { $mid = self::nextChar($para, $lo); }
            if ($mid >= $hi) { break; }
            if ($m->xForIndex($para, $size, $mid) - $x0 <= $width) { $lo = $mid; } else { $hi = $mid; }
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

    public function height(): float
    {
        return count($this->lines) * $this->lineHeight;
    }

    /** The line holding byte $byte: a byte at a line's end starts the next line; the text's end is on the last. */
    public function lineOf(int $byte): int
    {
        $last = count($this->lines) - 1;
        foreach ($this->lines as $i => [, $end]) {
            if ($byte < $end) {
                return $i;
            }
        }
        return $last;
    }

    /** Line $i's text without its newline, what is shaped and painted. */
    public function lineText(int $i): string
    {
        [$a, $b] = $this->lines[$i];
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
        $at = max(0, min($byte - $this->lines[$i][0], strlen($line)));
        return [$m->xForIndex($line, $this->size, $at), $i * $this->lineHeight];
    }

    /** The byte nearest to ($x, $y) from the layout's top left. */
    public function hit(TextMetrics $m, float $x, float $y): int
    {
        $i = max(0, min(count($this->lines) - 1, (int)floor($y / $this->lineHeight)));
        $line = $this->lineText($i);
        $byte = $this->lines[$i][0] + $m->indexForX($line, $this->size, max(0.0, $x));
        if ($i < count($this->lines) - 1 && $byte === $this->lines[$i][1] && $line !== '' && $line[-1] === ' ') {
            $byte--; // after the wrapping space would be the next line's start
        }
        return $byte;
    }
}
