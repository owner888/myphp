<?php
declare(strict_types=1);

namespace MyPHP\Text;

/** Grapheme and word boundaries in a text (mygo's text.Boundaries), in runes. */
final class Boundaries
{
    /** @var list<int> the runes */
    private array $runes = [];
    /** @var list<int> the start of every grapheme, then the count of runes */
    private array $graphemes = [];

    /** Prepares for $runes, $text their UTF-8. @param list<int> $runes */
    public function reset(array $runes, string $text): void
    {
        $this->runes = $runes;
        $this->graphemes = [];
        $at = 0;
        foreach (preg_split('/(\X)/u', $text, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE) ?: [] as $g) {
            $this->graphemes[] = $at;
            $at += mb_strlen($g, 'UTF-8');
        }
        $this->graphemes[] = count($runes);
    }

    /** The grapheme starts and the text's end. @return list<int> */
    public function graphemeOffsets(): array { return $this->graphemes; }

    /** The end of the grapheme starting at or containing $i. */
    public function nextGrapheme(int $i): int
    {
        foreach ($this->graphemes as $g) {
            if ($g > $i) {
                return $g;
            }
        }
        return count($this->runes);
    }

    /** The start of the grapheme before $i. */
    public function prevGrapheme(int $i): int
    {
        $prev = 0;
        foreach ($this->graphemes as $g) {
            if ($g >= $i) {
                return $prev;
            }
            $prev = $g;
        }
        return $prev;
    }

    /**
     * Word boundaries without a dictionary (mygo's internal/text): a word is a run of letters, digits and underscores, as
     * in the scripts written with spaces between words (Latin, Cyrillic, Greek, Hangul…), except that each Han ideograph,
     * Hiragana and Katakana is a word of its own: Chinese and Japanese have no spaces between words, and only a
     * dictionary finds them (UAX #29 leaves them to one); a character at a time is what platforms do without one. Thai
     * keeps its runs: its vowels are combining marks.
     */
    public static function isWordRune(int $r): bool
    {
        if ($r < 0x80) {
            return $r >= 0x30 && $r <= 0x39 || ($r | 0x20) >= 0x61 && ($r | 0x20) <= 0x7a || $r === 0x5f;
        }
        return preg_match('/^[\p{L}\p{Nd}]$/u', mb_chr($r, 'UTF-8')) === 1;
    }

    /** Whether $r is a word on its own: a Han ideograph (with the iteration mark 々), a Hiragana or a Katakana (with the prolonged sound mark ー) (mygo's StandsAlone). */
    public static function standsAlone(int $r): bool
    {
        if ($r < 0x2e80) {
            return false;
        }
        if ($r === 0x30fc || $r === 0xff70) { // ー and its halfwidth form, of the Common script
            return true;
        }
        return preg_match('/^[\p{Han}\p{Hiragana}\p{Katakana}]$/u', mb_chr($r, 'UTF-8')) === 1 && self::isWordRune($r);
    }

    /** Whether $a and $b, next to each other, are in different words or runs of other runes: one is part of a word and the other is not, or either is a word on its own (mygo's WordBoundary). */
    public static function wordBoundary(int $a, int $b): bool
    {
        return self::isWordRune($a) !== self::isWordRune($b) || self::standsAlone($a) || self::standsAlone($b);
    }

    /** The end of the word after $i, skipping what is between: an ideograph or a kana is a word alone. */
    public function nextWord(int $i): int
    {
        return self::wordEnd($this->runes, $i);
    }

    /** The start of the word before $i. */
    public function prevWord(int $i): int
    {
        return self::wordStart($this->runes, $i);
    }

    /** The end of the word after rune $i of $runes, skipping what is between. @param list<int> $runes */
    public static function wordEnd(array $runes, int $i): int
    {
        $n = count($runes);
        while ($i < $n && !self::isWordRune($runes[$i])) { $i++; }
        if ($i < $n) {
            for ($i++; $i < $n && !self::wordBoundary($runes[$i - 1], $runes[$i]); $i++);
        }
        return $i;
    }

    /** The start of the word before rune $i of $runes. @param list<int> $runes */
    public static function wordStart(array $runes, int $i): int
    {
        while ($i > 0 && !self::isWordRune($runes[$i - 1])) { $i--; }
        if ($i > 0) {
            for ($i--; $i > 0 && !self::wordBoundary($runes[$i - 1], $runes[$i]); $i--);
        }
        return $i;
    }

    /** The word, or the run of other runes, around $i, within its line: an ideograph or a kana is a word alone. @return array{0: int, 1: int} */
    public function wordAt(int $i): array
    {
        return self::wordAround($this->runes, $i);
    }

    /** wordAt() in $runes. @param list<int> $runes @return array{0: int, 1: int} */
    public static function wordAround(array $runes, int $i): array
    {
        $n = count($runes);
        if ($n === 0) {
            return [0, 0];
        }
        $i = min($i, $n - 1);
        $start = $i; $end = $i;
        while ($start > 0 && $runes[$start - 1] !== 0x0A && !self::wordBoundary($runes[$start - 1], $runes[$start])) { $start--; }
        if ($runes[$i] !== 0x0A) {
            for ($end = $i + 1; $end < $n && $runes[$end] !== 0x0A && !self::wordBoundary($runes[$end - 1], $runes[$end]); $end++);
        }
        return [$start, $end];
    }
}
