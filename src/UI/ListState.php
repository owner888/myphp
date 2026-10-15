<?php
declare(strict_types=1);

namespace MyPHP\UI;

/**
 * A virtualized list's place among its rows, kept by the app: the anchor
 * row at the top of the view and how far the view's top is below the
 * anchor's top. Heights of rows seen so far are remembered; the rest are
 * taken as their average, so a million rows scroll by estimate until they
 * are measured. Only the rows in view (plus a margin) are built.
 *
 * With $selected set (-1 for none), a click chooses a row, and Up, Down,
 * Home and End move the choice while the list has the keyboard focus;
 * changed() reports a new choice and submitted() a double click or Enter.
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
    /** The chosen row, -1 for none; null leaves rows unchosen by clicks and keys. */
    public ?int $selected = null;
    private bool $didChange = false;
    private bool $didSubmit = false;
    private ?int $target = null;

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

    /** Puts row $row at the top of the view, gliding there over the next frames unless $smooth is false. */
    public function scrollTo(int $row, bool $smooth = true): void
    {
        $row = max(0, min($this->count - 1, $row));
        if ($smooth) {
            $this->target = $row;
            return;
        }
        $this->target = null;
        $this->row = $row;
        $this->offset = 0;
        $this->scrollBy(0);
    }

    /** Scrolls as little as needed to bring row $row into view. */
    public function reveal(int $row): void
    {
        $this->target = null;
        $row = max(0, min($this->count - 1, $row));
        if ($row < $this->row || ($row === $this->row && $this->offset > 0)) {
            $this->row = $row;
            $this->offset = 0;
            $this->scrollBy(0);
            return;
        }
        if ($this->viewport > 0 && ($row - $this->row) * $this->estimate > 50 * $this->viewport) {
            $this->row = $row; // far below: do not walk every row in between
            $this->offset = $this->height($row) - $this->viewport;
            $this->scrollBy(0);
            return;
        }
        $bottom = -$this->offset; // of row $row, from the view's top
        for ($i = $this->row; $i <= $row; $i++) {
            $bottom += $this->height($i);
        }
        if ($bottom > $this->viewport) {
            $this->scrollBy($bottom - $this->viewport);
        }
    }

    /** Chooses row $row (clamped; -1 for none) and brings it into view. */
    public function choose(int $row): void
    {
        $row = max(-1, min($this->count - 1, $row));
        if ($row !== $this->selected) {
            $this->selected = $row;
            $this->didChange = true;
        }
        if ($row >= 0) {
            $this->reveal($row);
        }
    }

    /** Whether the choice changed since last asked. */
    public function changed(): bool
    {
        $c = $this->didChange;
        $this->didChange = false;
        return $c;
    }

    /** Whether a row was double-clicked or Enter pressed since last asked. */
    public function submitted(): bool
    {
        $s = $this->didSubmit;
        $this->didSubmit = false;
        return $s;
    }

    public function submit(): void
    {
        $this->didSubmit = true;
    }

    public function extent(): array
    {
        $content = $this->count * $this->estimate;
        return [$this->row * $this->estimate + $this->offset, max(0.0, $content - $this->viewport), $this->viewport];
    }

    public function glide(float $dt): bool
    {
        if ($this->target === null) {
            return false;
        }
        $to = min($this->target, $this->count - 1);
        if ($this->viewport > 0 && abs($to - $this->row) * $this->estimate > 4 * $this->viewport) {
            // Far away: jump to three views short of the target and glide the rest.
            $views = (int)(3 * $this->viewport / max(1.0, $this->estimate));
            $this->row = $to > $this->row ? $to - $views : $to + $views;
            $this->offset = 0;
        }
        $d = -$this->offset;
        for ($i = min($this->row, $to); $i < max($this->row, $to); $i++) {
            $d += $this->height($i) * ($to > $this->row ? 1 : -1);
        }
        if (abs($d) < 0.5) {
            $this->scrollTo($to, false);
            return false;
        }
        [$row, $offset] = [$this->row, $this->offset];
        $this->scrollBy($d * Glide::step($dt));
        if ($row === $this->row && $offset === $this->offset) {
            $this->target = null; // the end pulled back: as far as it goes
            return false;
        }
        return true;
    }

    public function moving(): bool
    {
        return $this->target !== null;
    }

    public function stop(): void
    {
        $this->target = null;
    }
}
