<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

use MyPHP\Text\Boundaries;

/**
 * Characters as the editor steps over them (the functions of mygo's
 * selection.go): the classes of characters, the words and subwords of
 * code, whole graphemes, and indentation. Runes are UTF-8 strings.
 */
final class Chars
{
    /** Character classes, as Zed's CharClassifier: words, punctuation and whitespace. */
    public const SPACE = 0, PUNCT = 1, WORD = 2;

    /** The class of $r; $extra holds characters other than letters, digits and _ that words of the language hold. */
    public static function classOf(string $r, string $extra): int
    {
        if ($r === '') { return self::SPACE; }
        if (strlen($r) === 1) { // ASCII, as most code is
            $c = ord($r);
            if ($r === '_' || $c >= 0x30 && $c <= 0x39 || $c >= 0x41 && $c <= 0x5A || $c >= 0x61 && $c <= 0x7A || $extra !== '' && str_contains($extra, $r)) { return self::WORD; }
            return $r === ' ' || $c >= 0x09 && $c <= 0x0D ? self::SPACE : self::PUNCT;
        }
        if (preg_match('/^[\p{L}\p{Nd}]$/u', $r) === 1 || $extra !== '' && mb_strpos($extra, $r) !== false) { return self::WORD; }
        return self::isSpace($r) ? self::SPACE : self::PUNCT;
    }

    /** Whether $r is white space (Go's unicode.IsSpace). */
    public static function isSpace(string $r): bool
    {
        if (strlen($r) === 1) { $c = ord($r); return $r === ' ' || $c >= 0x09 && $c <= 0x0D; }
        return $r === "\u{85}" || $r === "\u{A0}" || preg_match('/^[\p{Zs}\x{2028}\x{2029}]$/u', $r) === 1;
    }

    public static function isLower(string $r): bool { return strlen($r) === 1 ? $r >= 'a' && $r <= 'z' : preg_match('/^\p{Ll}$/u', $r) === 1; }
    public static function isUpper(string $r): bool { return strlen($r) === 1 ? $r >= 'A' && $r <= 'Z' : preg_match('/^\p{Lu}$/u', $r) === 1; }

    /** Where the word after $off ends: past spaces, then the run of characters of one class, as alt-right does. $sub stops at the ends of subwords too: at underscores and where lower case turns upper. */
    public static function wordRight(RopeText $r, int $off, string $extra, bool $sub): int
    {
        $n = $r->len();
        // Past whitespace on the line; a newline is a step of its own.
        [$c, $size] = $r->runeAt($off);
        if ($off < $n && $c === "\n") { return $off + 1; }
        while ($off < $n && $c !== "\n" && self::isSpace($c)) {
            $off += $size;
            [$c, $size] = $r->runeAt($off);
        }
        if ($off >= $n || $c === "\n") { return $off; }
        $cls = self::classOf($c, $extra);
        $prev = $c;
        $off += $size;
        while ($off < $n) {
            [$c, $size] = $r->runeAt($off);
            if (self::classOf($c, $extra) !== $cls || $c === "\n" || self::apart($cls, $prev, $c)) { break; }
            if ($sub && $cls === self::WORD && self::subwordBreak($prev, $c, $r, $off + $size)) { break; }
            $prev = $c;
            $off += $size;
        }
        return $off;
    }

    /** Where the word before $off starts. */
    public static function wordLeft(RopeText $r, int $off, string $extra, bool $sub): int
    {
        [$c, $size] = $r->runeBefore($off);
        if ($off > 0 && $c === "\n") { return $off - 1; }
        while ($off > 0 && $c !== "\n" && self::isSpace($c)) {
            $off -= $size;
            [$c, $size] = $r->runeBefore($off);
        }
        if ($off <= 0 || $c === "\n") { return $off; }
        $cls = self::classOf($c, $extra);
        $next = $c;
        $off -= $size;
        while ($off > 0) {
            [$c, $size] = $r->runeBefore($off);
            if (self::classOf($c, $extra) !== $cls || $c === "\n" || self::apart($cls, $c, $next)) { break; }
            if ($sub && $cls === self::WORD && self::subwordBreakBack($c, $next)) { break; }
            $next = $c;
            $off -= $size;
        }
        return $off;
    }

    /** Whether $a and $b, next to each other and both of class $cls, are in different words all the same: each ideograph and kana is one, Chinese and Japanese having no spaces between words (mygo's apart). */
    private static function apart(int $cls, string $a, string $b): bool
    {
        return $cls === self::WORD && (strlen($a) >= 3 && Boundaries::standsAlone(mb_ord($a, 'UTF-8')) || strlen($b) >= 3 && Boundaries::standsAlone(mb_ord($b, 'UTF-8')));
    }

    /** Whether a subword ends between $prev and $c going right: at an underscore, or lower case turning upper. */
    private static function subwordBreak(string $prev, string $c, RopeText $r, int $after): bool
    {
        if ($prev === '_' && $c !== '_' || $c === '_' && $prev !== '_') { return true; }
        if (self::isLower($prev) && self::isUpper($c)) { return true; }
        // "HTTPServer": a capital before a capital and lower case starts a word.
        if (self::isUpper($prev) && self::isUpper($c)) {
            [$n] = $r->runeAt($after);
            return $n !== '' && self::isLower($n);
        }
        return false;
    }

    private static function subwordBreakBack(string $c, string $next): bool
    {
        if ($c === '_' && $next !== '_' || $next === '_' && $c !== '_') { return true; }
        return self::isLower($c) && self::isUpper($next);
    }

    /** The word around $off, or the run of its class: what a double click selects. @return array{0: int, 1: int} */
    public static function wordAt(RopeText $r, int $off, string $extra): array
    {
        $line = $r->lineOf($off);
        $a = $r->lineStart($line); $z = $r->lineEnd($line);
        $s = $r->slice($a, $z);
        $i = min($off - $a, strlen($s));
        // The class of the character at off, or before it at the end of a word.
        if ($i < strlen($s)) {
            $cls = self::classOf(self::runeAt($s, $i), $extra);
            if ($cls !== self::WORD && $i > 0 && self::classOf(self::runeBefore($s, $i), $extra) === self::WORD) { $cls = self::WORD; }
        } elseif ($i > 0) {
            $cls = self::classOf(self::runeBefore($s, $i), $extra);
        } else {
            return [$off, $off];
        }
        // An ideograph or a kana is a word alone.
        $alone = fn(string $c): bool => $cls === self::WORD && strlen($c) >= 3 && Boundaries::standsAlone(mb_ord($c, 'UTF-8'));
        if ($cls === self::WORD) {
            $forward = $i < strlen($s) && self::classOf(self::runeAt($s, $i), $extra) === self::WORD;
            $c = $forward ? self::runeAt($s, $i) : self::runeBefore($s, $i);
            if ($alone($c)) { return $forward ? [$a + $i, $a + $i + strlen($c)] : [$a + $i - strlen($c), $a + $i]; }
        }
        $start = $i; $end = $i;
        while ($start > 0) {
            $c = self::runeBefore($s, $start);
            if (self::classOf($c, $extra) !== $cls || $alone($c)) { break; }
            $start -= strlen($c);
        }
        while ($end < strlen($s)) {
            $c = self::runeAt($s, $end);
            if (self::classOf($c, $extra) !== $cls || $alone($c)) { break; }
            $end += strlen($c);
        }
        return [$a + $start, $a + $end];
    }

    /** The rune starting at byte $i of $s. */
    public static function runeAt(string $s, int $i): string
    {
        $c = ord($s[$i]);
        $n = $c < 0x80 ? 1 : ($c < 0xE0 ? 2 : ($c < 0xF0 ? 3 : 4));
        return substr($s, $i, $n);
    }

    /** The rune ending at byte $i of $s. */
    public static function runeBefore(string $s, int $i): string
    {
        $j = $i - 1;
        while ($j > 0 && (ord($s[$j]) & 0xC0) === 0x80) { $j--; }
        return substr($s, $j, $i - $j);
    }

    /** Steps right over a whole character with its combining marks, joiners and variation selectors, and over a CRLF. */
    public static function graphemeRight(RopeText $r, int $off): int
    {
        $n = $r->len();
        if ($off >= $n) { return $n; }
        [$c, $size] = $r->runeAt($off);
        if (self::isRegional($c) && self::regionalBefore($r, $off) % 2 === 0) {
            // A flag: two regional indicators.
            [$d, $dsize] = $r->runeAt($off + $size);
            if (self::isRegional($d)) { $size += $dsize; }
        }
        $off += $size;
        if ($c === "\r") {
            [$d] = $r->runeAt($off);
            if ($d === "\n") { return $off + 1; }
        }
        while ($off < $n) {
            [$c, $size] = $r->runeAt($off);
            if ($c === "\u{200D}") { // a joiner and what it joins
                $off += $size;
                if ($off < $n) { [, $size] = $r->runeAt($off); $off += $size; }
                continue;
            }
            if (!self::extends($c)) { break; }
            $off += $size;
        }
        return $off;
    }

    public static function graphemeLeft(RopeText $r, int $off): int
    {
        if ($off <= 0) { return 0; }
        while ($off > 0) {
            [$c, $size] = $r->runeBefore($off);
            $off -= $size;
            if ($c === "\n") {
                [$d] = $r->runeBefore($off);
                if ($d === "\r") { $off--; }
                return $off;
            }
            if (self::extends($c)) { continue; }
            [$p, $size] = $r->runeBefore($off);
            if ($p === "\u{200D}") { $off -= $size; continue; }
            if (self::isRegional($c) && self::regionalBefore($r, $off) % 2 === 1) {
                // The second of a flag's regional indicators.
                [, $size] = $r->runeBefore($off);
                $off -= $size;
            }
            return $off;
        }
        return 0;
    }

    /** Whether $c is a regional indicator, two of which make a flag. */
    private static function isRegional(string $c): bool
    {
        return strlen($c) === 4 && $c >= "\u{1F1E6}" && $c <= "\u{1F1FF}";
    }

    /** Counts the regional indicators right before $off, which pair up from the first. */
    private static function regionalBefore(RopeText $r, int $off): int
    {
        $n = 0;
        while ($off > 0) {
            [$c, $size] = $r->runeBefore($off);
            if (!self::isRegional($c)) { break; }
            $n++;
            $off -= $size;
        }
        return $n;
    }

    /** Whether $c goes with the character before it: a mark, a variation selector, a skin tone or a tag. */
    private static function extends(string $c): bool
    {
        if (strlen($c) === 1) { return false; }
        return preg_match('/^[\p{Mn}\p{Me}\p{Mc}\x{FE00}-\x{FE0F}\x{1F3FB}-\x{1F3FF}\x{E0020}-\x{E007F}]$/u', $c) === 1;
    }

    /** The leading whitespace of $s. */
    public static function indentOf(string $s): string
    {
        return substr($s, 0, strlen($s) - strlen(ltrim($s, " \t")));
    }

    /** The columns of an indentation, tabs to tab stops. */
    public static function indentWidth(string $s, int $tab): int
    {
        $col = 0;
        for ($i = 0, $n = strlen($s); $i < $n; $i++) {
            if ($s[$i] === "\t") { $col += $tab - $col % $tab; }
            elseif ($s[$i] === ' ') { $col++; }
            else { return $col; }
        }
        return $col;
    }
}
