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

    public static function isWordRune(int $r): bool
    {
        if ($r < 0x80) {
            return $r >= 0x30 && $r <= 0x39 || ($r | 0x20) >= 0x61 && ($r | 0x20) <= 0x7a || $r === 0x5f;
        }
        return preg_match('/^[\p{L}\p{Nd}]$/u', mb_chr($r, 'UTF-8')) === 1;
    }

    /** The end of the word after $i, skipping what is between. */
    public function nextWord(int $i): int
    {
        $n = count($this->runes);
        while ($i < $n && !self::isWordRune($this->runes[$i])) { $i++; }
        while ($i < $n && self::isWordRune($this->runes[$i])) { $i++; }
        return $i;
    }

    /** The start of the word before $i. */
    public function prevWord(int $i): int
    {
        while ($i > 0 && !self::isWordRune($this->runes[$i - 1])) { $i--; }
        while ($i > 0 && self::isWordRune($this->runes[$i - 1])) { $i--; }
        return $i;
    }

    /** The word, or the run of other runes, around $i. @return array{0: int, 1: int} */
    public function wordAt(int $i): array
    {
        $n = count($this->runes);
        if ($n === 0) {
            return [0, 0];
        }
        $i = min($i, $n - 1);
        $word = self::isWordRune($this->runes[$i]);
        $start = $i; $end = $i;
        while ($start > 0 && self::isWordRune($this->runes[$start - 1]) === $word && $this->runes[$start - 1] !== 0x0A) { $start--; }
        while ($end < $n && self::isWordRune($this->runes[$end]) === $word && $this->runes[$end] !== 0x0A) { $end++; }
        return [$start, $end];
    }
}
