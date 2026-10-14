<?php
declare(strict_types=1);

namespace MyPHP\UI;

/**
 * A virtualized list's place among its rows, kept by the app: the anchor
 * row at the top of the view and how far the view's top is below the
 * anchor's top. Heights of rows seen so far are remembered; the rest are
 * taken as their average, so a million rows scroll by estimate until they
 * are measured. Only the rows in view (plus a margin) are built.
 */
final class ListState implements Scrollable
{
    public int $row = 0;
    public float $offset = 0;
    /** @var array<int, float> measured heights by row */
    public array $heights = [];
    /** Average of measured heights, the estimate for the rest. */
    public float $estimate = 40;
    /** Set by Context::list and Layout each frame. */
    public int $count = 0;
    public int $first = 0;
    public float $viewport = 0;
    public float $gap = 0;

    public function height(int $row): float
    {
        return $this->heights[$row] ?? $this->estimate;
    }

    public function measured(int $row, float $h): void
    {
        if (!isset($this->heights[$row])) {
            $n = count($this->heights);
            $this->estimate = ($this->estimate * $n + $h) / ($n + 1);
        }
        $this->heights[$row] = $h;
    }

    public function scrollBy(float $dy): void
    {
        $this->offset += $dy;
        while ($this->offset < 0 && $this->row > 0) {
            $this->row--;
            $this->offset += $this->height($this->row);
        }
        while ($this->row < $this->count - 1 && $this->offset >= $this->height($this->row)) {
            $this->offset -= $this->height($this->row);
            $this->row++;
        }
        if ($this->row === 0 && $this->offset < 0) {
            $this->offset = 0;
        }
        // Do not leave empty room below the last row: pull back so that the
        // rows from the anchor fill the view, as far as their heights are known.
        $below = -$this->offset;
        for ($i = $this->row; $i < $this->count && $below < $this->viewport; $i++) {
            $below += $this->height($i);
        }
        if ($below < $this->viewport) {
            $this->scrollBack($this->viewport - $below);
        }
    }

    private function scrollBack(float $dy): void
    {
        $this->offset -= $dy;
        while ($this->offset < 0 && $this->row > 0) {
            $this->row--;
            $this->offset += $this->height($this->row);
        }
        if ($this->offset < 0) {
            $this->offset = 0;
        }
    }
}
