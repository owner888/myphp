<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/**
 * A version of a text, read by bytes and lines, as code editing counts
 * (mygo's rope.Text). A text never changes: replace() returns another
 * version, sharing the tree with this one.
 */
final class RopeText
{
    public function __construct(public readonly ?RopeNode $n = null) {}

    public static function new(string $s): self { return new self(Rope::build($s)); }

    /** Whether $u is the same version, without comparing their bytes. */
    public function same(RopeText $u): bool { return $this->n === $u->n; }

    public function len(): int { return Rope::sum($this->n)->bytes; }
    public function lineCount(): int { return Rope::sum($this->n)->lines + 1; }

    /** The byte $line starts at, clamped. */
    public function lineStart(int $line): int { return Rope::lineStart($this->n, $line); }

    /** The byte $line ends at, before its newline. */
    public function lineEnd(int $line): int
    {
        if ($line + 1 < $this->lineCount()) { return Rope::lineStart($this->n, $line + 1) - 1; }
        return $this->len();
    }

    /** The line holding byte $off. */
    public function lineOf(int $off): int { return Rope::prefix($this->n, $off)->lines; }

    /** Bytes $a to $b. */
    public function slice(int $a, int $b): string
    {
        $len = $this->len();
        $a = max(0, min($a, $len)); $b = max(0, min($b, $len));
        if ($a >= $b) { return ''; }
        $out = '';
        Rope::read($this->n, $a, $b, $out);
        return $out;
    }

    /** Line $i, without its newline. */
    public function line(int $i): string { return $this->slice($this->lineStart($i), $this->lineEnd($i)); }

    public function __toString(): string { return $this->slice(0, $this->len()); }

    /** The byte at $off, or 0 past the end. */
    public function byteAt(int $off): int
    {
        $n = $this->n;
        if ($off < 0 || $off >= Rope::sum($n)->bytes) { return 0; }
        while ($n->left !== null) {
            if ($off < $n->left->sum->bytes) {
                $n = $n->left;
            } else {
                $off -= $n->left->sum->bytes;
                $n = $n->right;
            }
        }
        return ord($n->text[$off]);
    }

    /** The rune starting at $off and its size in bytes; '' and 0 at the end. @return array{0: string, 1: int} */
    public function runeAt(int $off): array
    {
        if ($off < 0 || $off >= $this->len()) { return ['', 0]; }
        $b = $this->byteAt($off);
        if ($b < 0x80) { return [chr($b), 1]; }
        $r = mb_substr($this->slice($off, $off + 4), 0, 1, 'UTF-8');
        return [$r, strlen($r)];
    }

    /** The rune ending at $off and its size. @return array{0: string, 1: int} */
    public function runeBefore(int $off): array
    {
        if ($off <= 0) { return ['', 0]; }
        $b = $this->byteAt($off - 1);
        if ($b < 0x80) { return [chr($b), 1]; }
        $s = $this->slice(max(0, $off - 4), $off);
        $r = mb_substr($s, -1, 1, 'UTF-8');
        return [$r, strlen($r)];
    }

    /** How many UTF-16 units come before byte $off. */
    public function utf16(int $off): int { return Rope::prefix($this->n, $off)->units; }

    /** The byte of UTF-16 unit $u, rounded down to a rune. */
    public function fromUTF16(int $u): int { return Rope::byteOfUnit($this->n, $u); }

    /** Calls $fn with bytes $a to $b, a piece at a time, until $fn returns false. */
    public function chunks(int $a, int $b, callable $fn): void { Rope::chunks($this->n, $a, $b, $fn); }

    /** The text with bytes $a to $b replaced by $s. */
    public function replace(int $a, int $b, string $s): self { return new self(Rope::replaceBytes($this->n, $a, $b, $s)); }

    /** The most bytes eachLine and eachLineBack copy out at a time, but for a line longer. */
    private const BATCH_BYTES = 64 << 10;

    /**
     * Calls $fn($i, $start, $line) with lines $from to $to, exclusive, and
     * the byte each starts at, reading them a batch at a time rather than
     * finding each in the tree, until $fn returns false.
     */
    public function eachLine(int $from, int $to, callable $fn): void
    {
        $to = min($to, $this->lineCount());
        for ($i = max(0, $from); $i < $to;) {
            $a = $this->lineStart($i);
            $end = min($i + 256, $to, max($i + 1, $this->lineOf($a + self::BATCH_BYTES))); // 256 lines, or 64 KB of long ones, but a line at least (mygo's 9965d50): copying 256 lines of 250 KB took 64 MB for a scan that stops at the first
            $text = $this->slice($a, $this->lineEnd($end - 1));
            for ($at = 0; $i < $end; $i++) {
                $n = strpos($text, "\n", $at);
                if ($n === false) { $n = strlen($text); }
                if (!$fn($i, $a + $at, substr($text, $at, $n - $at))) { return; }
                $at = $n + 1;
            }
        }
    }

    /** Calls $fn with lines $from down to $to, inclusive, as eachLine does going up. */
    public function eachLineBack(int $from, int $to, callable $fn): void
    {
        $from = min($from, $this->lineCount() - 1);
        $to = max(0, $to);
        for ($i = $from; $i >= $to;) {
            $first = max($i - 255, $to, min($i, $this->lineOf(max(0, $this->lineEnd($i) - self::BATCH_BYTES)) + 1));
            $a = $this->lineStart($first);
            $text = $this->slice($a, $this->lineEnd($i));
            $starts = []; // where the batch's lines start, to go back through them
            for ($at = 0;;) {
                $starts[] = $at;
                $n = strpos($text, "\n", $at);
                if ($n === false) { break; }
                $at = $n + 1;
            }
            for ($k = count($starts) - 1; $k >= 0 && $i >= $first; $k--) {
                $end = $k + 1 < count($starts) ? $starts[$k + 1] - 1 : strlen($text);
                if (!$fn($i, $a + $starts[$k], substr($text, $starts[$k], $end - $starts[$k]))) { return; }
                $i--;
            }
        }
    }
}
