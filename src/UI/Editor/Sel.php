<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/**
 * A selection as the editor keeps it (mygo's editor.sel): with the x the
 * caret keeps going up and down, and an id ordering selections by when
 * they were made: the newest is the primary one, which input methods and
 * scrolling follow.
 */
final class Sel
{
    /** @param float $goal -1 when none */
    public function __construct(public int $anchor = 0, public int $head = 0, public float $goal = -1.0, public int $id = 0) {}

    public function start(): int { return min($this->anchor, $this->head); }
    public function end(): int { return max($this->anchor, $this->head); }
    public function empty(): bool { return $this->anchor === $this->head; }

    public function collapse(int $at): void { $this->anchor = $at; $this->head = $at; $this->goal = -1.0; }

    public function toSelection(): Selection { return new Selection($this->anchor, $this->head); }

    /**
     * Sorts selections and merges those that overlap, as Zed's selections
     * collection does: overlapping ones merge, as does a caret touching a
     * selection, while two selections that only touch stay apart.
     * @param list<Sel> $sels @return list<Sel>
     */
    public static function merge(array $sels): array
    {
        if (count($sels) < 2) { return $sels; }
        usort($sels, fn(Sel $a, Sel $b) => $a->start() !== $b->start() ? $a->start() <=> $b->start() : $a->end() <=> $b->end());
        $out = [$sels[0]];
        for ($i = 1; $i < count($sels); $i++) {
            $s = $sels[$i];
            $last = $out[count($out) - 1];
            $touch = $s->start() === $last->end() && ($s->empty() || $last->empty());
            if ($s->start() < $last->end() || $touch || $s->start() === $last->start()) {
                // Keep the newer's identity and direction.
                $start = $last->start(); $end = max($last->end(), $s->end());
                $reversed = $last->head < $last->anchor;
                $id = $last->id;
                if ($s->id > $id) { $id = $s->id; $reversed = $s->head < $s->anchor; }
                $out[count($out) - 1] = $reversed ? new Sel($end, $start, -1.0, $id) : new Sel($start, $end, -1.0, $id);
                continue;
            }
            $out[] = $s;
        }
        return $out;
    }

    /** The index of the newest selection. @param list<Sel> $sels */
    public static function newest(array $sels): int
    {
        $best = 0;
        foreach ($sels as $i => $s) {
            if ($s->id > $sels[$best]->id) { $best = $i; }
        }
        return $best;
    }
}
