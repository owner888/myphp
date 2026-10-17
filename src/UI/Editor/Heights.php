<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/**
 * The display model of a code view, as Zed's display map (mygo's
 * heights): every line of the text is a row, or several when it wraps,
 * or none when hidden (a fold, or unchanged lines a diff collapses);
 * blocks are rows between lines that are not text. Rows are as high as
 * the view's line height and blocks as high as they ask, so the top of a
 * line is arithmetic on a few sparse sums: the extra rows of wrapped
 * lines, kept in a Fenwick tree, and the heights blocks add and hidden
 * lines take away, kept sorted with their prefix sums. Lines are found at
 * a height by bisection.
 */
final class Heights
{
    public float $lh = 0.0;
    /** Lines. */
    public int $n = 0;
    /** @var list<array{0: int, 1: int}> the hidden spans, [from, to] inclusive, in order */
    public array $hidden = [];
    /** @var list<Block> sorted by line, above before below */
    public array $blocks = [];
    /** @var list<array{0: int, 1: float}> [line, the height of its blocks] */
    private array $entries = [];
    /** @var list<float> prefix[i] is the extra of entries[:i] */
    private array $prefix = [0.0];
    /** @var list<int> hiddenRows[i] is how many rows the hidden spans before i hide */
    private array $hiddenRows = [0];
    public WrapRows $wraps;
    /** The version of wraps the spans' rows count. */
    private int $wrapsSeen = 0;
    public bool $dirty = false;
    /** @var ?list<Block> the last list of blocks given, which the next is compared with by identity */
    private ?array $src = null;

    public function __construct() { $this->wraps = new WrapRows; }

    public function setLines(int $n): void
    {
        if ($this->n !== $n) { $this->n = $n; $this->dirty = true; }
    }

    /** @param list<array{0: int, 1: int}> $spans */
    public function setHidden(array $spans): void
    {
        if ($this->hidden !== $spans) { $this->hidden = $spans; $this->dirty = true; }
    }

    /** @param list<Block> $blocks */
    public function setBlocks(array $blocks): void
    {
        if ($this->src !== null && $this->src === $blocks) { return; }
        $this->src = $blocks;
        $sorted = $blocks;
        usort($sorted, fn(Block $a, Block $b) => $a->line !== $b->line ? $a->line <=> $b->line : (int)$a->below <=> (int)$b->below);
        $same = count($sorted) === count($this->blocks);
        if ($same) { foreach ($sorted as $i => $b) { if (!$b->equals($this->blocks[$i])) { $same = false; break; } } }
        if (!$same) { $this->blocks = $sorted; $this->dirty = true; }
    }

    /** Whether $line is hidden. */
    public function isHidden(int $line): bool
    {
        $i = $this->hiddenIndex($line);
        return $i < count($this->hidden) && $this->hidden[$i][0] <= $line;
    }

    /** The first hidden span ending at or after $line. */
    private function hiddenIndex(int $line): int
    {
        $lo = 0; $hi = count($this->hidden);
        while ($lo < $hi) { $m = ($lo + $hi) >> 1; if ($this->hidden[$m][1] >= $line) { $hi = $m; } else { $lo = $m + 1; } }
        return $lo;
    }

    /** Makes the entries again after the hidden lines or the blocks changed. */
    private function update(): void
    {
        if ($this->wraps->on && $this->hidden !== [] && $this->wrapsSeen !== $this->wraps->version) { $this->dirty = true; }
        if (!$this->dirty) { return; }
        $this->dirty = false;
        $this->wrapsSeen = $this->wraps->version;
        $this->entries = [];
        foreach ($this->blocks as $b) {
            if ($b->line >= $this->n || !$b->sticky && $this->isHidden($b->line)) { continue; }
            $n = count($this->entries);
            if ($n > 0 && $this->entries[$n - 1][0] === $b->line) { $this->entries[$n - 1][1] += $b->height; continue; }
            $this->entries[] = [$b->line, $b->height];
        }
        $this->prefix = [0.0];
        foreach ($this->entries as $i => $e) { $this->prefix[$i + 1] = $this->prefix[$i] + $e[1]; }
        $this->hiddenRows = [0];
        foreach ($this->hidden as $i => $s) { $this->hiddenRows[$i + 1] = $this->hiddenRows[$i] + $this->rowsOf($s[0], min($s[1] + 1, $this->n)); }
    }

    /** The rows of the lines $from to $to, exclusive. */
    public function rowsOf(int $from, int $to): int
    {
        if ($to <= $from) { return 0; }
        return $to - $from + $this->wraps->before($to) - $this->wraps->before($from);
    }

    /** The height of the blocks of the lines before $line. */
    private function extraBefore(int $line): float
    {
        $lo = 0; $hi = count($this->entries);
        while ($lo < $hi) { $m = ($lo + $hi) >> 1; if ($this->entries[$m][0] >= $line) { $hi = $m; } else { $lo = $m + 1; } }
        return $this->prefix[$lo];
    }

    /** The rows of the hidden lines before $line. */
    private function hiddenBefore(int $line): int
    {
        $i = $this->hiddenIndex($line);
        $rows = $this->hiddenRows[$i];
        if ($i < count($this->hidden) && $this->hidden[$i][0] < $line) { $rows += $this->rowsOf($this->hidden[$i][0], $line); }
        return $rows;
    }

    /** The top of $line, above its blocks; $n gives the bottom of the last. */
    public function top(int $line): float
    {
        $this->update();
        $line = max(0, min($line, $this->n));
        return $this->lh * ($line + $this->wraps->before($line) - $this->hiddenBefore($line)) + $this->extraBefore($line);
    }

    public function total(): float { return $this->top($this->n); }

    /** The line whose rows or blocks hold height $y: the last line that is not hidden starting at or above it. */
    public function lineAt(float $y): int
    {
        $this->update();
        $lo = 0; $hi = max(0, $this->n - 1);
        while ($lo < $hi) {
            $m = intdiv($lo + $hi + 1, 2);
            if ($this->top($m) <= $y) { $lo = $m; } else { $hi = $m - 1; }
        }
        // A hidden line shares its top with the lines after it, up to the next shown.
        while ($lo > 0 && $this->isHidden($lo)) {
            $s = $this->hidden[$this->hiddenIndex($lo)];
            if ($s[1] + 1 < $this->n && $this->top($s[1] + 1) <= $y) { $lo = $s[1] + 1; break; }
            $lo = $s[0] - 1;
        }
        return max(0, $lo);
    }

    /** The blocks of $line, those above it and those below, leaving out those of a hidden line but sticky ones. @return array{0: list<Block>, 1: list<Block>} */
    public function blocksOf(int $line): array
    {
        $lo = 0; $hi = count($this->blocks);
        while ($lo < $hi) { $m = ($lo + $hi) >> 1; if ($this->blocks[$m]->line >= $line) { $hi = $m; } else { $lo = $m + 1; } }
        $above = []; $below = [];
        $hidden = $this->isHidden($line);
        for ($j = $lo; $j < count($this->blocks) && $this->blocks[$j]->line === $line; $j++) {
            $b = $this->blocks[$j];
            if ($hidden && !$b->sticky) { continue; }
            if ($b->below) { $below[] = $b; } else { $above[] = $b; }
        }
        return [$above, $below];
    }

    public function geometry(int $line): LineGeometry
    {
        $top = $this->top($line);
        [$above, $below] = $this->blocksOf($line);
        $rows = $top;
        foreach ($above as $b) { $rows += $b->height; }
        $hidden = $this->isHidden($line);
        $belowY = $rows;
        if (!$hidden) { $belowY += $this->lh * $this->wraps->rows($line); }
        $bottom = $belowY;
        foreach ($below as $b) { $bottom += $b->height; }
        return new LineGeometry($top, $rows, $belowY, $bottom, $hidden);
    }
}
