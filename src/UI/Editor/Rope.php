<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

use RuntimeException;

/**
 * Text as a persistent tree of chunks of UTF-8, as mygo's internal/rope:
 * an AVL tree whose leaves own up to 4 KB of the text each, and whose
 * nodes sum the bytes, runes, UTF-16 units and newlines below them, as
 * Zed's rope sums its chunks. An edit copies the chunks it touches and
 * the path to them and shares the rest of the tree with the text before
 * it, so undo history keeps versions rather than copies. The operations
 * here take and return nodes (null the empty text); RopeText wraps one.
 */
final class Rope
{
    /** The most bytes a leaf holds. */
    public const CHUNK = 4096;

    public static function sum(?RopeNode $n): Summary
    {
        return $n === null ? new Summary : $n->sum;
    }

    private static function height(?RopeNode $n): int
    {
        return $n === null ? 0 : $n->height;
    }

    private static function leaf(string $s): ?RopeNode
    {
        return $s === '' ? null : new RopeNode(null, null, $s, Summary::of($s), 1);
    }

    private static function branch(?RopeNode $a, ?RopeNode $b): ?RopeNode
    {
        if ($a === null) { return $b; }
        if ($b === null) { return $a; }
        return new RopeNode($a, $b, '', $a->sum->add($b->sum), 1 + max($a->height, $b->height));
    }

    private static function balance(RopeNode $n): RopeNode
    {
        if (self::height($n->left) - self::height($n->right) > 1) {
            $a = $n->left;
            if (self::height($a->left) < self::height($a->right)) {
                $b = $a->right;
                $a = self::branch(self::branch($a->left, $b->left), $b->right);
            }
            return self::branch($a->left, self::branch($a->right, $n->right));
        }
        if (self::height($n->right) - self::height($n->left) > 1) {
            $b = $n->right;
            if (self::height($b->right) < self::height($b->left)) {
                $a = $b->left;
                $b = self::branch($a->left, self::branch($a->right, $b->right));
            }
            return self::branch(self::branch($n->left, $b->left), $b->right);
        }
        return $n;
    }

    /** The text of $a followed by that of $b. */
    public static function join(?RopeNode $a, ?RopeNode $b): ?RopeNode
    {
        if ($a === null) { return $b; }
        if ($b === null) { return $a; }
        if ($a->left === null && $b->left === null && $a->sum->bytes + $b->sum->bytes <= self::CHUNK) {
            return self::leaf($a->text . $b->text);
        }
        if ($a->height > $b->height + 1) {
            return self::balance(self::branch($a->left, self::join($a->right, $b)));
        }
        if ($b->height > $a->height + 1) {
            return self::balance(self::branch(self::join($a, $b->left), $b->right));
        }
        return self::branch($a, $b);
    }

    /** A tree of $s, in chunks that end at rune boundaries. */
    public static function build(string $s): ?RopeNode
    {
        $leaves = [];
        $len = strlen($s);
        for ($start = 0; $start < $len;) {
            $end = min($start + self::CHUNK, $len);
            while ($end < $len && (ord($s[$end]) & 0xC0) === 0x80) { $end--; } // not inside a rune
            $leaves[] = self::leaf(substr($s, $start, $end - $start));
            $start = $end;
        }
        $build = function (int $a, int $z) use (&$build, $leaves): ?RopeNode {
            if ($a === $z) { return null; }
            if ($z - $a === 1) { return $leaves[$a]; }
            $m = intdiv($a + $z, 2);
            return self::branch($build($a, $m), $build($m, $z));
        };
        return $build(0, count($leaves));
    }

    /** The text before byte $at and the text from it. @return array{0: ?RopeNode, 1: ?RopeNode} */
    public static function split(?RopeNode $n, int $at): array
    {
        if ($n === null) { return [null, null]; }
        if ($at <= 0) { return [null, $n]; }
        if ($at >= $n->sum->bytes) { return [$n, null]; }
        if ($n->left === null) { return [self::leaf(substr($n->text, 0, $at)), self::leaf(substr($n->text, $at))]; }
        if ($at < $n->left->sum->bytes) {
            [$a, $b] = self::split($n->left, $at);
            return [$a, self::join($b, $n->right)];
        }
        [$a, $b] = self::split($n->right, $at - $n->left->sum->bytes);
        return [self::join($n->left, $a), $b];
    }

    /** The text with bytes $a to $b replaced by $s. */
    public static function replaceBytes(?RopeNode $n, int $a, int $b, string $s): ?RopeNode
    {
        $total = self::sum($n)->bytes;
        $a = max(0, min($a, $total)); $b = max(0, min($b, $total));
        [$left, $rest] = self::split($n, $a);
        [, $right] = self::split($rest, $b - $a);
        return self::join(self::join($left, self::build($s)), $right);
    }

    /** The text with runes $start to $end replaced by $s. */
    public static function replace(?RopeNode $n, int $start, int $end, string $s): ?RopeNode
    {
        $total = self::sum($n)->runes;
        $a = max(0, min($start, $end, $total)); $z = max(0, min(max($start, $end), $total));
        return self::replaceBytes($n, self::byteOfRune($n, $a), self::byteOfRune($n, $z), $s);
    }

    /** The byte rune $index starts at. */
    public static function byteOfRune(?RopeNode $n, int $index): int
    {
        $index = max(0, min($index, self::sum($n)->runes));
        $offset = 0;
        while ($n !== null && $n->left !== null) {
            if ($index < $n->left->sum->runes) {
                $n = $n->left;
            } else {
                $index -= $n->left->sum->runes;
                $offset += $n->left->sum->bytes;
                $n = $n->right;
            }
        }
        if ($n === null) { return $offset; }
        if ($n->sum->bytes === $n->sum->runes) { return $offset + $index; }
        return $offset + strlen(mb_substr($n->text, 0, $index, 'UTF-8'));
    }

    /** The rune holding UTF-16 unit $index, or after it. */
    public static function runeOfUnit(?RopeNode $n, int $index): int
    {
        $offset = 0;
        while ($n !== null && $n->left !== null) {
            if ($index < $n->left->sum->units) {
                $n = $n->left;
            } else {
                $index -= $n->left->sum->units;
                $offset += $n->left->sum->runes;
                $n = $n->right;
            }
        }
        if ($n === null) { return $offset; }
        if ($n->sum->runes === $n->sum->units) { return $offset + min($index, $n->sum->runes); }
        return $offset + Summary::countRunes(self::unitsPrefix($n->text, $index));
    }

    /** The byte of UTF-16 unit $index, rounded down to a rune. */
    public static function byteOfUnit(?RopeNode $n, int $index): int
    {
        $off = 0;
        while ($n !== null && $n->left !== null) {
            if ($index < $n->left->sum->units) {
                $n = $n->left;
            } else {
                $index -= $n->left->sum->units;
                $off += $n->left->sum->bytes;
                $n = $n->right;
            }
        }
        if ($n === null) { return $off; }
        if ($n->sum->bytes === $n->sum->units) { return $off + min($index, $n->sum->bytes); }
        return $off + strlen(self::unitsPrefix($n->text, $index));
    }

    /** The text of a leaf before UTF-16 unit $index, rounded down to a rune: through UTF-16, which the C library converts faster than a loop here walks the runes. */
    private static function unitsPrefix(string $text, int $index): string
    {
        if ($index <= 0) { return ''; }
        $u = mb_convert_encoding($text, 'UTF-16BE', 'UTF-8');
        if ($index * 2 >= strlen($u)) { return $text; }
        $hi = ord($u[$index * 2 - 2]);
        if ($hi >= 0xD8 && $hi <= 0xDB) { $index--; } // the unit before is a high surrogate: its rune is not before $index
        return mb_convert_encoding(substr($u, 0, $index * 2), 'UTF-8', 'UTF-16BE');
    }

    /** The summary of the text before byte $at. */
    public static function prefix(?RopeNode $n, int $at): Summary
    {
        if ($n === null || $at <= 0) { return new Summary; }
        if ($at >= $n->sum->bytes) { return $n->sum; }
        if ($n->left === null) { return Summary::of(substr($n->text, 0, $at)); }
        if ($at <= $n->left->sum->bytes) { return self::prefix($n->left, $at); }
        return $n->left->sum->add(self::prefix($n->right, $at - $n->left->sum->bytes));
    }

    /** The byte $line starts at, the end of the text past the last line. */
    public static function lineStart(?RopeNode $n, int $line): int
    {
        if ($line <= 0) { return 0; }
        if ($line > self::sum($n)->lines) { return self::sum($n)->bytes; }
        $offset = 0;
        while ($n->left !== null) {
            if ($line <= $n->left->sum->lines) {
                $n = $n->left;
            } else {
                $line -= $n->left->sum->lines;
                $offset += $n->left->sum->bytes;
                $n = $n->right;
            }
        }
        $from = 0;
        for ($i = 0; $i < $line; $i++) {
            $from = strpos($n->text, "\n", $from) + 1;
        }
        return $offset + $from;
    }

    /** Appends bytes $start to $end to $out. */
    public static function read(?RopeNode $n, int $start, int $end, string &$out): void
    {
        if ($n === null || $start >= $end) { return; }
        if ($n->left === null) {
            $out .= substr($n->text, $start, $end - $start);
            return;
        }
        $split = $n->left->sum->bytes;
        if ($start < $split) { self::read($n->left, $start, min($end, $split), $out); }
        if ($end > $split) { self::read($n->right, max(0, $start - $split), $end - $split, $out); }
    }

    /** Calls $fn with the text from $a to $b, a piece at a time, until $fn returns false. */
    public static function chunks(?RopeNode $n, int $a, int $b, callable $fn): void
    {
        $a = max(0, $a); $b = min($b, self::sum($n)->bytes);
        $walk = function (?RopeNode $n, int $off) use (&$walk, $a, $b, $fn): bool {
            if ($n === null || $off >= $b || $off + $n->sum->bytes <= $a) { return true; }
            if ($n->left === null) {
                $lo = max($a - $off, 0); $hi = min($b - $off, strlen($n->text));
                return (bool)$fn(substr($n->text, $lo, $hi - $lo));
            }
            return $walk($n->left, $off) && $walk($n->right, $off + $n->left->sum->bytes);
        };
        $walk($n, 0);
    }

    /** Throws on what is wrong with the tree, for tests: an oversized or miscounted leaf, or an unbalanced or miscounted branch. */
    public static function check(?RopeNode $n): Summary
    {
        if ($n === null) { return new Summary; }
        if ($n->left === null) {
            if (strlen($n->text) > self::CHUNK + 3 || !$n->sum->equals(Summary::of($n->text)) || $n->height !== 1) {
                throw new RuntimeException('rope: invalid leaf ' . json_encode($n->text));
            }
            return $n->sum;
        }
        $a = self::check($n->left);
        $b = self::check($n->right);
        $d = $n->left->height - $n->right->height;
        if ($d < -1 || $d > 1 || $n->height !== 1 + max($n->left->height, $n->right->height) || !$n->sum->equals($a->add($b))) {
            throw new RuntimeException("rope: invalid branch of height {$n->height}");
        }
        return $n->sum;
    }
}
