<?php
declare(strict_types=1);

namespace MyPHP\UI\RichText;

/**
 * A change of a document (mygo's richtext change): the runes from $start
 * to $oldEnd became those from $start to $newEnd, at $version.
 *
 * @internal
 */
final class Change
{
    public function __construct(
        public int $version = 0,
        public int $start = 0,
        public int $oldEnd = 0,
        public int $newEnd = 0,
    ) {}

    /** The change $a then $b make together (mygo's composeChanges). */
    public static function compose(Change $a, Change $b): Change
    {
        // Map a's new end through b, and b's old end back through a.
        $end = $a->newEnd;
        if ($end > $b->start && $end < $b->oldEnd) {
            $end = $b->newEnd;
        } elseif ($end >= $b->oldEnd) {
            $end += $b->newEnd - $b->oldEnd;
        }
        $old = $b->oldEnd;
        if ($old > $a->start && $old < $a->newEnd) {
            $old = $a->oldEnd;
        } elseif ($old >= $a->newEnd) {
            $old -= $a->newEnd - $a->oldEnd;
        }
        return new Change($b->version, min($a->start, $b->start), max($a->oldEnd, $old), max($end, $b->newEnd));
    }

    /**
     * Where $p goes as the runes from $start to $start + $removed become
     * $inserted others (mygo's mapPosition), as AttributedString tracks
     * indices: a caret or the start of a range where text is inserted goes
     * after it, the end of a range does not grow, and what was replaced
     * collapses to its edges.
     */
    public static function mapPosition(int $p, int $start, int $removed, int $inserted, bool $upper): int
    {
        $end = $start + $removed;
        if ($p < $start) { return $p; }
        if ($removed === 0 && $p === $start) { return $upper ? $p : $p + $inserted; }
        if ($p < $end) { return $upper ? $start + $inserted : $start; }
        return $p + $inserted - $removed;
    }
}
