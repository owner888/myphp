<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/**
 * The hunks between the lines of two buffers, old and new, as either
 * changes (mygo's diffModel). An edit of the new text compares again only
 * the lines around it, from the unchanged lines before the hunks it
 * touches to those after, as each keystroke would otherwise compare the
 * whole texts; a change of the old text compares them anew.
 */
final class DiffModel
{
    /** How many unchanged lines around an edit are compared again with it. */
    private const PAD = 3;
    /** How many lines an edit compares again at most before comparing the whole texts. */
    private const MAX_REGION = 20000;

    public ?Buffer $old = null, $new = null;
    public RopeText $oldText, $newText;
    public int $oldVer = -1, $newVer = -1;
    /** @var list<Hunk> */
    public array $hunks = [];
    /** Counts the changes of hunks, for the caches of what they show. */
    public int $version = 0;

    public function __construct()
    {
        $this->oldText = new RopeText;
        $this->newText = new RopeText;
    }

    public function set(?Buffer $old, ?Buffer $new): void
    {
        if ($this->old !== $old || $this->new !== $new) {
            $this->old = $old; $this->new = $new;
            $this->oldVer = -1; $this->newVer = -1;
        }
    }

    /** Compares the texts again where they changed, and reports whether the hunks changed. */
    public function update(): bool
    {
        if ($this->old === null || $this->new === null) {
            $changed = $this->hunks !== [];
            $this->hunks = [];
            if ($changed) { $this->version++; }
            return $changed;
        }
        $oldText = $this->old->rope(); $oldVer = $this->old->version();
        $newText = $this->new->rope(); $newVer = $this->new->version();
        if ($oldVer === $this->oldVer && $newVer === $this->newVer && $this->old !== $this->new) { return false; }
        if ($this->old === $this->new) {
            $changed = $this->hunks !== [];
            $this->hunks = []; $this->oldVer = $oldVer; $this->newVer = $newVer;
            $this->oldText = $oldText; $this->newText = $newText;
            if ($changed) { $this->version++; }
            return $changed;
        }
        if ($oldVer !== $this->oldVer || $newText->len() === 0 || $this->newText->len() === 0) {
            $this->full($oldText, $newText, $oldVer, $newVer);
            return true;
        }
        $to = $this->new->changesTo($this->newVer);
        if ($to === null || $to[1] !== $newVer) {
            $this->full($oldText, $newText, $oldVer, $newVer);
            return true;
        }
        // The lines the edits touched, in the new text, and the hunks moved with the lines after them. Hunks next to an edit are touched too, as lines it inserts where lines were deleted may be those lines again; they are compared again with the edit.
        $dirtyFrom = -1; $dirtyTo = -1;
        $touched = array_fill(0, count($this->hunks), false);
        foreach ($to[0] as $cs) {
            foreach ($cs as $c) {
                // The lines the change replaced, where the changes before it in its version put them, as the hunks and dirty lines are.
                $lo = $c->newLine; $hi = $c->newLine + $c->oldEndLine - $c->oldLine;
                $delta = ($c->newEndLine - $c->newLine) - ($hi - $lo);
                // The dirty lines after the change move with it.
                if ($dirtyFrom >= 0) {
                    if ($dirtyFrom > $hi) { $dirtyFrom += $delta; }
                    if ($dirtyTo > $hi) {
                        $dirtyTo += $delta;
                    } elseif ($dirtyTo >= $lo) {
                        $dirtyTo = max($dirtyTo + $delta, $c->newEndLine);
                    }
                }
                if ($dirtyFrom < 0) {
                    $dirtyFrom = $c->newLine; $dirtyTo = $c->newEndLine;
                } else {
                    $dirtyFrom = min($dirtyFrom, $c->newLine); $dirtyTo = max($dirtyTo, $c->newEndLine);
                }
                foreach ($this->hunks as $i => $h) {
                    if ($h->b1 < $lo && $h->b0 < $lo) {
                        continue;
                    }
                    if ($h->b0 > $hi + 1) {
                        $h->b0 += $delta; $h->b1 += $delta;
                        continue;
                    }
                    $touched[$i] = true;
                    $h->b0 = min($h->b0, $c->newLine);
                    $h->b1 = max($h->b1 + $delta, $h->b0);
                }
            }
        }
        $this->newText = $newText; $this->newVer = $newVer;
        if ($dirtyFrom < 0) { return false; }
        $this->redo($dirtyFrom, $dirtyTo, $touched);
        return true;
    }

    /** Compares the whole texts. */
    private function full(RopeText $oldText, RopeText $newText, int $oldVer, int $newVer): void
    {
        $this->oldText = $oldText; $this->newText = $newText; $this->oldVer = $oldVer; $this->newVer = $newVer;
        $this->hunks = Diff::lines(self::ropeLines($oldText, 0, $oldText->lineCount()), self::ropeLines($newText, 0, $newText->lineCount()));
        $this->version++;
    }

    /** Lines $from to $to of $r: none of an empty text, which a diff takes as no lines rather than one empty line, as git does. @return list<string> */
    public static function ropeLines(RopeText $r, int $from, int $to): array
    {
        if ($from >= $to || $r->len() === 0) { return []; }
        return explode("\n", $r->slice($r->lineStart($from), $r->lineEnd($to - 1)));
    }

    /**
     * Compares again the new lines $from to $to, inclusive, with a few
     * unchanged lines around them and the hunks they touch: the range
     * grows until no hunk crosses its edges, so that the lines before and
     * after it pair up in both texts.
     * @param list<bool> $touched
     */
    private function redo(int $from, int $to, array $touched): void
    {
        $nNew = $this->newText->lineCount();
        $nOld = $this->oldText->lineCount();
        $newFrom = max(0, $from - self::PAD);
        $newTo = min($nNew, $to + 1 + self::PAD);
        foreach ($this->hunks as $i => $h) {
            if ($touched[$i]) {
                $newFrom = max(0, min($newFrom, $h->b0 - 1));
                $newTo = min($nNew, max($newTo, $h->b1 + 1));
            }
        }
        for ($changed = true; $changed;) {
            $changed = false;
            foreach ($this->hunks as $i => $h) {
                if ($touched[$i]) { continue; }
                if ($h->b0 < $newFrom && $h->b1 > $newFrom) { $newFrom = $h->b0; $changed = true; }
                if ($h->b0 < $newTo && $h->b1 > $newTo) { $newTo = $h->b1; $changed = true; }
            }
        }
        if ($newTo - $newFrom > self::MAX_REGION) {
            $this->full($this->oldText, $this->newText, $this->oldVer, $this->newVer);
            return;
        }
        // The hunks before and after the range stay; those in it go.
        $before = []; $after = [];
        $beforeDelta = 0; $afterDelta = 0;
        foreach ($this->hunks as $i => $h) {
            if ($touched[$i]) { continue; }
            if ($h->b1 <= $newFrom && ($h->b0 < $newFrom || $h->b0 === $h->b1)) {
                $before[] = $h;
                $beforeDelta += ($h->a1 - $h->a0) - ($h->b1 - $h->b0);
            } elseif ($h->b0 >= $newTo && ($h->b1 > $newTo || $h->b0 === $h->b1)) {
                $after[] = $h;
                $afterDelta += ($h->a1 - $h->a0) - ($h->b1 - $h->b0);
            }
        }
        $oldFrom = $newFrom + $beforeDelta;
        $oldTo = $nOld - ($nNew - $newTo) - $afterDelta;
        if ($oldFrom < 0 || $oldTo < $oldFrom || $oldTo > $nOld) {
            $this->full($this->oldText, $this->newText, $this->oldVer, $this->newVer);
            return;
        }
        $mid = Diff::lines(self::ropeLines($this->oldText, $oldFrom, $oldTo), self::ropeLines($this->newText, $newFrom, $newTo));
        foreach ($mid as $h) { $h->a0 += $oldFrom; $h->a1 += $oldFrom; $h->b0 += $newFrom; $h->b1 += $newFrom; }
        // Lines inserted that could show in more than one place, as a blank line among others, show where the edit was: the line Enter opened is the one added.
        foreach ($mid as $i => $h) {
            if ($h->a0 !== $h->a1) { continue; }
            $len = $h->b1 - $h->b0;
            $lo = $h->b0; $hi = $h->b0;
            $limLo = $newFrom; $limHi = $newTo - $len;
            if ($i > 0) { $limLo = max($limLo, $mid[$i - 1]->b1 + 1); }
            if ($i + 1 < count($mid)) { $limHi = min($limHi, $mid[$i + 1]->b0 - 1 - $len); }
            while ($lo > $limLo && $this->newText->line($lo - 1) === $this->newText->line($lo - 1 + $len)) { $lo--; }
            while ($hi < $limHi && $this->newText->line($hi) === $this->newText->line($hi + $len)) { $hi++; }
            $best = $h->b0; $bestOver = self::overlap($h->b0, $h->b1, $from, $to + 1);
            for ($p = $lo; $p <= $hi; $p++) {
                if (($o = self::overlap($p, $p + $len, $from, $to + 1)) > $bestOver) { $best = $p; $bestOver = $o; }
            }
            $shift = $best - $h->b0;
            $h->a0 += $shift; $h->a1 += $shift; $h->b0 += $shift; $h->b1 += $shift;
        }
        $this->hunks = self::mergeHunks([...$before, ...$mid, ...$after]);
        $this->version++;
    }

    private static function overlap(int $a0, int $a1, int $b0, int $b1): int { return max(0, min($a1, $b1) - max($a0, $b0)); }

    /** Joins hunks that touch. @param list<Hunk> $hs @return list<Hunk> */
    public static function mergeHunks(array $hs): array
    {
        usort($hs, fn(Hunk $x, Hunk $y) => $x->b0 !== $y->b0 ? $x->b0 <=> $y->b0 : $x->a0 <=> $y->a0);
        $out = [];
        foreach ($hs as $h) {
            if ($h->a0 === $h->a1 && $h->b0 === $h->b1) { continue; }
            $n = count($out);
            if ($n > 0 && $out[$n - 1]->a1 === $h->a0 && $out[$n - 1]->b1 === $h->b0) {
                $out[$n - 1] = new Hunk($out[$n - 1]->a0, $h->a1, $out[$n - 1]->b0, $h->b1);
                continue;
            }
            $out[] = $h;
        }
        return $out;
    }

    /** The index of the hunk holding or ending at new line $n, or -1. */
    public function hunkAtNew(int $n): int
    {
        $i = Finder::lowerBound(count($this->hunks), fn(int $k) => $this->hunks[$k]->b1 > $n || $this->hunks[$k]->b0 >= $n);
        if ($i < count($this->hunks)) {
            $h = $this->hunks[$i];
            if ($h->b0 <= $n && $n < $h->b1 || $h->b0 === $h->b1 && $h->b0 === $n) { return $i; }
        }
        if (($k = $this->pastEnd()) >= 0 && $n === $this->newText->lineCount() - 1) { return $k; }
        return -1;
    }

    /**
     * The hunk deleting lines after the new text's last line, as when a
     * text without a final newline loses its last lines, which goes with
     * the last line, as no caret nor gutter row is past it; or -1.
     */
    public function pastEnd(): int
    {
        $k = count($this->hunks) - 1;
        if ($k < 0) { return -1; }
        $h = $this->hunks[$k];
        return $h->b0 === $h->b1 && $h->b0 >= $this->newText->lineCount() ? $k : -1;
    }

    /** The edit of the new text that puts the old lines of $h back. */
    public static function hunkEdit(Hunk $h, RopeText $old, RopeText $nw): Edit
    {
        $text = $h->a1 > $h->a0 ? $old->slice($old->lineStart($h->a0), $old->lineEnd($h->a1 - 1)) : '';
        $n = $nw->lineCount();
        if ($h->b1 > $h->b0 && $h->a1 > $h->a0) { return new Edit($nw->lineStart($h->b0), $nw->lineEnd($h->b1 - 1), $text); }
        if ($h->b1 > $h->b0) {
            // Lines added go with a newline: theirs, or the one before them at the end.
            if ($h->b1 < $n) { return new Edit($nw->lineStart($h->b0), $nw->lineStart($h->b1)); }
            if ($h->b0 > 0) { return new Edit($nw->lineEnd($h->b0 - 1), $nw->len()); }
            return new Edit(0, $nw->len());
        }
        if ($h->b0 < $n) {
            $at = $nw->lineStart($h->b0);
            return new Edit($at, $at, $text . "\n");
        }
        return new Edit($nw->len(), $nw->len(), "\n" . $text);
    }

    /** The hunks whose new lines meet $from to $to. @return list<int> */
    public function hunksTouching(int $from, int $to): array
    {
        $out = [];
        foreach ($this->hunks as $i => $h) {
            if ($h->b0 <= $to && ($h->b1 > $from || $h->b0 === $h->b1 && $h->b0 >= $from)) { $out[] = $i; }
        }
        if (($k = $this->pastEnd()) >= 0 && $to >= $this->newText->lineCount() - 1 && !in_array($k, $out, true)) { $out[] = $k; }
        return $out;
    }
}
