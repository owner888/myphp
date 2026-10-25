<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/** Counts a text: its bytes, runes, UTF-16 units and newlines (mygo's rope.Summary). */
final class Summary
{
    public function __construct(public readonly int $bytes = 0, public readonly int $runes = 0, public readonly int $units = 0, public readonly int $lines = 0) {}

    public function add(Summary $b): Summary
    {
        return new Summary($this->bytes + $b->bytes, $this->runes + $b->runes, $this->units + $b->units, $this->lines + $b->lines);
    }

    public function equals(Summary $b): bool
    {
        return $this->bytes === $b->bytes && $this->runes === $b->runes && $this->units === $b->units && $this->lines === $b->lines;
    }

    /** Counts $s. */
    public static function of(string $s): Summary
    {
        $n = self::countRunes($s);
        return new Summary(strlen($s), $n, self::unitsForRunes($s, $n), substr_count($s, "\n"));
    }

    /** The runes of $s: its bytes when all are ASCII, as most code and logs are. */
    public static function countRunes(string $s): int
    {
        return preg_match('/[\x80-\xFF]/', $s) === 1 ? mb_strlen($s, 'UTF-8') : strlen($s);
    }

    /** The UTF-16 units of $s, whose runes number $n: those past the BMP take two. */
    private static function unitsForRunes(string $s, int $n): int
    {
        if ($n === strlen($s)) { return $n; }
        return $n + preg_match_all('/[\x{10000}-\x{10FFFF}]/u', mb_scrub($s, 'UTF-8')); // PCRE matches nothing in malformed UTF-8: its bytes scrubbed, a unit each
    }
}
