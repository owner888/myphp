<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/**
 * Slides the groups of changed lines of one side of a diff up and down
 * where the same lines frame them, as git's xdl_change_compact (mygo's
 * diff/compact.go): groups merge when they meet, line up with a group of
 * the other side where they can, and otherwise take the place the indent
 * heuristic scores best.
 */
final class DiffCompact
{
    // The weights of git's indent heuristic (xdiffi.c), tuned on a corpus of real changes.
    private const MAX_INDENT = 200, MAX_BLANKS = 20, START_OF_FILE_PENALTY = 1, END_OF_FILE_PENALTY = 21, TOTAL_BLANK_WEIGHT = -30, POST_BLANK_WEIGHT = 6,
        RELATIVE_INDENT_PENALTY = -4, RELATIVE_INDENT_WITH_BLANK_PENALTY = 10, RELATIVE_OUTDENT_PENALTY = 24, RELATIVE_OUTDENT_WITH_BLANK = 17,
        RELATIVE_DEDENT_PENALTY = 23, RELATIVE_DEDENT_WITH_BLANK = 17, INDENT_WEIGHT = 60, MAX_SLIDING = 100;

    /** A run of changed lines, maybe empty, between unchanged ones: [start, end]. */
    private int $start = 0, $end = 0;

    /**
     * @param list<bool> $r the changed lines of this side, moved as the groups slide
     * @param list<bool> $ro the other side's
     * @param list<int> $ids this side's lines interned
     * @param list<string> $lines this side's lines as text
     */
    public static function compact(array &$r, array &$ro, array $ids, array $lines): void
    {
        $g = self::init($r); $gOther = self::init($ro);
        while (true) {
            if ($g->end !== $g->start) {
                while (true) {
                    $size = $g->end - $g->start;
                    $endMatchingOther = -1;
                    while ($g->slideUp($r, $ids)) { $gOther->previous($ro); }
                    $earliestEnd = $g->end;
                    if ($gOther->end > $gOther->start) { $endMatchingOther = $g->end; }
                    while ($g->slideDown($r, $ids)) {
                        $gOther->next($ro);
                        if ($gOther->end > $gOther->start) { $endMatchingOther = $g->end; }
                    }
                    if ($size === $g->end - $g->start) { break; }
                }
                if ($g->end === $earliestEnd) {
                    // It could not move.
                } elseif ($endMatchingOther !== -1) {
                    // Back to the last place matching a group of the other side.
                    while ($gOther->end === $gOther->start) {
                        if (!$g->slideUp($r, $ids) || !$gOther->previous($ro)) { break; }
                    }
                } else {
                    $shift = max($earliestEnd, $g->end - $size - 1, $g->end - self::MAX_SLIDING);
                    $best = -1; $bestScore = [0, 0];
                    for (; $shift <= $g->end; $shift++) {
                        $s = [0, 0];
                        self::score($s, self::measure($lines, $shift));
                        self::score($s, self::measure($lines, $shift - $size));
                        if ($best === -1 || self::cmp($s, $bestScore) <= 0) { $bestScore = $s; $best = $shift; }
                    }
                    while ($g->end > $best) {
                        if (!$g->slideUp($r, $ids) || !$gOther->previous($ro)) { break; }
                    }
                }
            }
            if (!$g->next($r) || !$gOther->next($ro)) { return; }
        }
    }

    private static function init(array $r): self
    {
        $g = new self;
        while ($g->end < count($r) && $r[$g->end]) { $g->end++; }
        return $g;
    }

    private function next(array $r): bool
    {
        if ($this->end >= count($r)) { return false; }
        $this->start = $this->end + 1;
        for ($this->end = $this->start; $this->end < count($r) && $r[$this->end]; $this->end++) {}
        return true;
    }

    private function previous(array $r): bool
    {
        if ($this->start <= 0) { return false; }
        $this->end = $this->start - 1;
        for ($this->start = $this->end; $this->start > 0 && $r[$this->start - 1]; $this->start--) {}
        return true;
    }

    private function slideDown(array &$r, array $ids): bool
    {
        if ($this->start < $this->end && $this->end < count($r) && $ids[$this->start] === $ids[$this->end]) {
            $r[$this->start] = false; $this->start++;
            $r[$this->end] = true; $this->end++;
            while ($this->end < count($r) && $r[$this->end]) { $this->end++; }
            return true;
        }
        return false;
    }

    private function slideUp(array &$r, array $ids): bool
    {
        if ($this->start < $this->end && $this->start > 0 && $ids[$this->start - 1] === $ids[$this->end - 1]) {
            $this->start--; $r[$this->start] = true;
            $this->end--; $r[$this->end] = false;
            while ($this->start > 0 && $r[$this->start - 1]) { $this->start--; }
            return true;
        }
        return false;
    }

    /** How far a line is indented, tabs to multiples of 8, or -1 for a blank line. */
    private static function indent(string $s): int
    {
        $n = 0;
        for ($i = 0, $len = strlen($s); $i < $len; $i++) {
            switch ($s[$i]) {
                case ' ': $n++; break;
                case "\t": $n += 8 - $n % 8; break;
                case "\r": case "\n": case "\f": case "\v": break;
                default: return $n;
            }
            if ($n >= self::MAX_INDENT) { return self::MAX_INDENT; }
        }
        return -1;
    }

    /** What a split at $at sits between: [endOfFile, indent, preBlank, preIndent, postBlank, postIndent]. @return array{0: bool, 1: int, 2: int, 3: int, 4: int, 5: int} */
    private static function measure(array $lines, int $at): array
    {
        $n = count($lines);
        $endOfFile = $at >= $n;
        $indent = $endOfFile ? -1 : self::indent($lines[$at]);
        $preBlank = 0; $preIndent = -1;
        for ($i = $at - 1; $i >= 0; $i--) {
            if (($preIndent = self::indent($lines[$i])) !== -1) { break; }
            if (++$preBlank === self::MAX_BLANKS) { $preIndent = 0; break; }
        }
        $postBlank = 0; $postIndent = -1;
        for ($i = $at + 1; $i < $n; $i++) {
            if (($postIndent = self::indent($lines[$i])) !== -1) { break; }
            if (++$postBlank === self::MAX_BLANKS) { $postIndent = 0; break; }
        }
        return [$endOfFile, $indent, $preBlank, $preIndent, $postBlank, $postIndent];
    }

    /** Adds a split's measure to a score [effectiveIndent, penalty]. */
    private static function score(array &$s, array $m): void
    {
        [$endOfFile, $indent, $preBlank, $preIndent, $postBlank, $postIndent] = $m;
        if ($preIndent === -1 && $preBlank === 0) { $s[1] += self::START_OF_FILE_PENALTY; }
        if ($endOfFile) { $s[1] += self::END_OF_FILE_PENALTY; }
        $post = $indent === -1 ? 1 + $postBlank : 0;
        $totalBlank = $preBlank + $post;
        $s[1] += self::TOTAL_BLANK_WEIGHT * $totalBlank + self::POST_BLANK_WEIGHT * $post;
        $in = $indent === -1 ? $postIndent : $indent;
        $blanks = $totalBlank !== 0;
        $s[0] += $in;
        if ($in === -1 || $preIndent === -1 || $in === $preIndent) {
        } elseif ($in > $preIndent) {
            $s[1] += $blanks ? self::RELATIVE_INDENT_WITH_BLANK_PENALTY : self::RELATIVE_INDENT_PENALTY;
        } elseif ($postIndent !== -1 && $postIndent > $in) {
            $s[1] += $blanks ? self::RELATIVE_OUTDENT_WITH_BLANK : self::RELATIVE_OUTDENT_PENALTY;
        } else {
            $s[1] += $blanks ? self::RELATIVE_DEDENT_WITH_BLANK : self::RELATIVE_DEDENT_PENALTY;
        }
    }

    private static function cmp(array $s, array $o): int
    {
        return self::INDENT_WEIGHT * ($s[0] <=> $o[0]) + $s[1] - $o[1];
    }
}
