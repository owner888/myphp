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
    /** @var array<string, HunkWords> the inline diffs of hunks, by what they compare */
    public array $words = [];

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
            if ($changed) { $this->bump(); }
            return $changed;
        }
        $oldText = $this->old->rope(); $oldVer = $this->old->version();
        $newText = $this->new->rope(); $newVer = $this->new->version();
        if ($oldVer === $this->oldVer && $newVer === $this->newVer && $this->old !== $this->new) { return false; }
        if ($this->old === $this->new) {
            $changed = $this->hunks !== [];
            $this->hunks = []; $this->oldVer = $oldVer; $this->newVer = $newVer;
            $this->oldText = $oldText; $this->newText = $newText;
            if ($changed) { $this->bump(); }
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
        $this->bump();
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
        $this->bump();
    }

    /** The hunks changed: their caches go. */
    private function bump(): void
    {
        $this->version++;
        $this->words = [];
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

    /** The index of the hunk holding or ending at line $n of the old text, or of the new, or -1: hunkAtNew for the new text. */
    public function hunkAt(int $n, bool $old): int
    {
        if (!$old) { return $this->hunkAtNew($n); }
        $i = Finder::lowerBound(count($this->hunks), fn(int $k) => $this->hunks[$k]->a1 > $n || $this->hunks[$k]->a0 >= $n);
        if ($i < count($this->hunks)) {
            $h = $this->hunks[$i];
            if ($h->a0 <= $n && $n < $h->a1 || $h->a0 === $h->a1 && $h->a0 === $n) { return $i; }
        }
        // Lines added after the old text's last line go with it.
        if (($k = count($this->hunks) - 1) >= 0 && $n === $this->oldText->lineCount() - 1) {
            $h = $this->hunks[$k];
            if ($h->a0 === $h->a1 && $h->a0 >= $this->oldText->lineCount()) { return $k; }
        }
        return -1;
    }

    /** How many lines the hunks add and delete. @return array{0: int, 1: int} */
    public function stats(): array
    {
        $added = 0; $deleted = 0;
        foreach ($this->hunks as $h) { $added += $h->b1 - $h->b0; $deleted += $h->a1 - $h->a0; }
        return [$added, $deleted];
    }

    /** The inline diff of hunk $i, made as $mode says. */
    public function wordsOf(int $i, DiffInline $mode): HunkWords
    {
        $h = $this->hunks[$i];
        $key = "{$h->a0},{$h->a1},{$h->b0},{$h->b1},{$mode->value}";
        if (isset($this->words[$key])) { return $this->words[$key]; }
        $w = $this->words[$key] = new HunkWords;
        if ($h->a0 === $h->a1 || $h->b0 === $h->b1 || $mode === DiffInline::DiffInlineNone) { return $w; }
        $a = self::ropeLines($this->oldText, $h->a0, $h->a1);
        $b = self::ropeLines($this->newText, $h->b0, $h->b1);
        $w->offset = self::alignBySimilarity($a, $b);
        foreach ($a as $i => $la) {
            $j = count($a) < count($b) ? $i + $w->offset : $i - $w->offset;
            if ($j < 0 || $j >= count($b)) { continue; }
            if (strlen($la) > 1000 || strlen($b[$j]) > 1000) { continue; }
            [$ra, $rb] = self::inlineDiff($la, $b[$j], $mode);
            if ($ra !== []) { $w->oldWords[$h->a0 + $i] = $ra; }
            if ($rb !== []) { $w->newWords[$h->b0 + $j] = $rb; }
        }
        return $w;
    }

    /**
     * How far to push the lines of the shorter side of a hunk down so that
     * they pair with the most similar lines of the longer, as Pierre's
     * diffs realign change blocks: by the common prefix and suffix of the
     * lines without their whitespace, over their longer length, taking an
     * offset only when it pairs better by half a line.
     * @param list<string> $a @param list<string> $b
     */
    public static function alignBySimilarity(array $a, array $b): int
    {
        [$short, $long] = count($a) > count($b) ? [$b, $a] : [$a, $b];
        if (count($short) === count($long) || $short === [] || count($short) * (count($long) - count($short) + 1) > 4096) { return 0; }
        $score = function (int $off) use ($short, $long): float {
            $s = 0.0;
            foreach ($short as $i => $x) { $s += self::similarity($x, $long[$i + $off]); }
            return $s;
        };
        $best = 0; $bestScore = $score(0);
        for ($off = 1; $off <= count($long) - count($short); $off++) {
            if (($s = $score($off)) > $bestScore + 0.5 * count($short)) { $best = $off; $bestScore = $s; }
        }
        return $best;
    }

    private static function similarity(string $a, string $b): float
    {
        $a = implode('', preg_split('/\s+/u', $a, -1, PREG_SPLIT_NO_EMPTY) ?: []);
        $b = implode('', preg_split('/\s+/u', $b, -1, PREG_SPLIT_NO_EMPTY) ?: []);
        if ($a === $b) { return 1.0; }
        $n = max(strlen($a), strlen($b));
        if ($n === 0) { return 1.0; }
        $p = 0;
        while ($p < strlen($a) && $p < strlen($b) && $a[$p] === $b[$p]) { $p++; }
        $s = 0;
        while ($s < strlen($a) - $p && $s < strlen($b) - $p && $a[strlen($a) - 1 - $s] === $b[strlen($b) - 1 - $s]) { $s++; }
        return ($p + $s) / $n;
    }

    /** The ranges of $a and $b that changed, as $mode says. @return array{0: list<array{0: int, 1: int}>, 1: list<array{0: int, 1: int}>} */
    private static function inlineDiff(string $a, string $b, DiffInline $mode): array
    {
        [$xa, $xb] = $mode === DiffInline::DiffInlineChar ? Diff::chars($a, $b) : Diff::words($a, $b);
        $conv = function (array $rs, string $s) use ($mode): array {
            if ($mode === DiffInline::DiffInlineWordAlt) { $rs = self::joinWordAlt($rs, $s); }
            if ($mode === DiffInline::DiffInlineWordLine && $rs !== []) { $rs = [[$rs[0][0], $rs[count($rs) - 1][1]]]; }
            return $rs;
        };
        return [$conv($xa, $a), $conv($xb, $b)];
    }

    /** Joins ranges one character apart, as Pierre's word-alt does: "a b c" changed reads as one highlight rather than three. */
    private static function joinWordAlt(array $rs, string $s): array
    {
        if (count($rs) < 2) { return $rs; }
        $out = [$rs[0]];
        foreach (array_slice($rs, 1) as $r) {
            $n = count($out) - 1;
            if (mb_strlen(substr($s, $out[$n][1], $r[0] - $out[$n][1]), 'UTF-8') <= 1) { $out[$n][1] = $r[1]; continue; }
            $out[] = $r;
        }
        return $out;
    }

    /** Puts the old lines of hunk $i back in the new buffer. */
    public function revert(int $i): void
    {
        if ($i >= 0 && $i < count($this->hunks)) { $this->new->applyEdits(self::hunkEdit($this->hunks[$i], $this->oldText, $this->newText)); }
    }

    /** Makes the new lines of hunk $i the old ones, so the hunk goes: the same edit, the other way. */
    public function accept(int $i): void
    {
        if ($i < 0 || $i >= count($this->hunks)) { return; }
        $h = $this->hunks[$i];
        $this->old->applyEdits(self::hunkEdit(new Hunk($h->b0, $h->b1, $h->a0, $h->a1), $this->newText, $this->oldText));
    }

    /** The new line an unchanged old line is, or null for a line a hunk changes. */
    public function newLineOf(int $old): ?int
    {
        $delta = 0;
        foreach ($this->hunks as $h) {
            if ($old < $h->a0) { break; }
            if ($old < $h->a1) { return null; }
            $delta = $h->b1 - $h->a1;
        }
        return $old + $delta;
    }
}
