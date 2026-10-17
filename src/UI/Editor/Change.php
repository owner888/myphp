<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/**
 * A range of a text an edit replaced, in bytes and lines, before (old)
 * and after (new) the edit (mygo's editor.change, and its public Change
 * as the first four). The changes of a version are in order and do not
 * overlap; newLine is oldLine moved by the lines the changes before it
 * added. An incremental parser takes them from the last: each starts at
 * oldStart, and ends at oldEnd before and at oldStart+newEnd-newStart
 * after, as the changes before it have not moved it yet.
 */
final class Change
{
    public function __construct(
        public int $oldStart = 0, public int $oldEnd = 0, public int $newStart = 0, public int $newEnd = 0,
        public int $oldLine = 0, public int $oldEndLine = 0, public int $newLine = 0, public int $newEndLine = 0,
    ) {}

    /** The change replacing all of $old with $new. */
    public static function whole(RopeText $old, RopeText $new): self
    {
        return new self(0, $old->len(), 0, $new->len(), 0, $old->lineCount() - 1, 0, $new->lineCount() - 1);
    }

    /** @param list<Change> $cs @return list<Change> */
    public static function invert(array $cs): array
    {
        $out = [];
        foreach ($cs as $c) {
            $out[] = new self($c->newStart, $c->newEnd, $c->oldStart, $c->oldEnd, $c->newLine, $c->newEndLine, $c->oldLine, $c->oldEndLine);
        }
        return $out;
    }

    public const LEFT = 0, RIGHT = 1; // bias: which side of an insertion at an offset the offset moves to

    /** Moves an offset of the text before $changes to the text after them. An offset inside a replaced range goes to its start (LEFT) or end (RIGHT). @param list<Change> $changes */
    public static function mapOffset(int $off, array $changes, int $bias): int
    {
        // The last change starting at or before off.
        $lo = 0; $hi = count($changes);
        while ($lo < $hi) { $m = ($lo + $hi) >> 1; if ($changes[$m]->oldStart <= $off) { $lo = $m + 1; } else { $hi = $m; } }
        $i = $lo;
        if ($i === 0) { return $off; }
        if ($bias === self::LEFT) {
            // Before every insertion at off, not only the last.
            while ($i > 1 && $changes[$i - 2]->oldStart === $off) { $i--; }
        }
        $c = $changes[$i - 1];
        if ($off > $c->oldEnd || $off === $c->oldEnd && $c->oldEnd > $c->oldStart) { return $off + $c->newEnd - $c->oldEnd; }
        if ($off === $c->oldStart && $bias === self::LEFT) { return $c->newStart; }
        if ($off === $c->oldStart && $c->oldStart === $c->oldEnd) { return $c->newEnd; }
        if ($bias === self::LEFT) { return $c->newStart; }
        return $c->newEnd;
    }

    /** Moves a line of the text before $changes to the text after them; a line the changes replaced goes to where they start. @param list<Change> $changes */
    public static function mapLine(int $line, array $changes): int
    {
        $delta = 0;
        foreach ($changes as $c) {
            if ($line < $c->oldLine) { break; }
            if ($line <= $c->oldEndLine) {
                if ($line === $c->oldLine) { return $c->newLine; }
                return min($c->newLine + ($line - $c->oldLine), $c->newEndLine);
            }
            $delta = $c->newEndLine - $c->oldEndLine;
        }
        return $line + $delta;
    }
}
