<?php
declare(strict_types=1);

namespace MyPHP\Text;

/**
 * Where lines may break in a paragraph, the rough cut of UAX #14 that keeps
 * a word whole: after whitespace and hyphens, between ideographs. Core Text
 * breaks a word that does not fit a line; whole-word wrapping carries on to
 * the next of these (Native\TextEngine), and a text's min-content width is
 * its widest piece between them.
 */
final class LineBreaks
{
    /** The rune indexes a line may break before. @param list<int> $runes @return array<int, true> */
    public static function at(array $runes): array
    {
        $out = [];
        $n = count($runes);
        for ($i = 1; $i <= $n; $i++) {
            $p = $runes[$i - 1];
            $q = $runes[$i] ?? 0x20;
            if ($p === 0x20 || $p === 0x09 || $p === 0x2D || self::isIdeograph($p) || $i < $n && self::isIdeograph($q)) {
                $out[$i] = true;
            }
        }
        return $out;
    }

    /**
     * The width of the widest piece of $runes no line breaks in, whitespace
     * ending it left out (CSS's min-content), $x[$i] being the pen before
     * rune $i and $x[count($runes)] the line's end.
     * @param list<int> $runes @param array<int, float> $x
     */
    public static function widest(array $runes, array $x): float
    {
        $breaks = self::at($runes);
        $n = count($runes);
        $widest = 0.0;
        for ($start = 0, $i = 1; $i <= $n; $i++) {
            if ($i < $n && !isset($breaks[$i])) {
                continue;
            }
            $end = $i;
            while ($end > $start && ($runes[$end - 1] === 0x20 || $runes[$end - 1] === 0x09)) { $end--; }
            $widest = max($widest, abs($x[$end] - $x[$start]));
            $start = $i;
        }
        return $widest;
    }

    private static function isIdeograph(int $r): bool
    {
        return $r >= 0x2E80 && $r <= 0x9FFF || $r >= 0xAC00 && $r <= 0xD7AF || $r >= 0xF900 && $r <= 0xFAFF || $r >= 0xFF00 && $r <= 0xFFEF || $r >= 0x20000 && $r <= 0x2FFFF;
    }
}
