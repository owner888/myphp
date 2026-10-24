<?php
declare(strict_types=1);

namespace MyPHP\UI;

use Closure;

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
    /** @var array<int, float> the measured heights that differ from the first one measured ($default), by row: a list of rows all one height stores none; forgetHeights() clears them */
    private array $heights = [];
    /** The first height measured, the height of every measured row not in $heights; the rows measured, a bit each (a million rows: 125 KB). */
    private ?float $default = null;
    private string $known = '';
    private int $measuredCount = 0;
    /** Average of measured heights, the estimate for the rest. */
    public float $estimate = 40;
    /** Set by Context::list and Layout each frame. */
    public int $count = 0;
    public int $first = 0;
    public float $viewport = 0;
    public float $gap = 0;
    /** How far rows wider than the list (Node::rowWidth) are scrolled sideways, how far they can go and the list's width: Layout sets the last two. */
    public float $x = 0, $maxX = 0, $viewportW = 0;
    /** The chosen row, -1 for none; null leaves rows unchosen by clicks and keys. */
    public ?int $selected = null;
    /** Set, lets the user choose several rows, held by their keys (mygo's ListState.Selection): a click chooses one, Cmd-click adds or takes out, Shift-click and Shift with the keys extend, Cmd+A chooses all; $selected is then the row the keys move from. */
    public ?Selection $selection = null;
    /** Set, lets the user drag rows to another place (mygo's ListState.Reorder): called with the rows dragged (those chosen, when the row dragged is one, else that row), in order, and the row they go before (the count for the end); move them there. The list shows where they would go and scrolls near its edges. */
    public ?Closure $reorder = null;
    /** The rows being dragged: rows, row (the one pressed), ox, oy (the press in it), dist (how far the pointer went), far (past 4 points: a drag, not a click). */
    public ?array $drag = null;
    /** An identity for row $i (Closure(int): int|string), for the selection to follow its items as rows come, go and move; the index without one. */
    public ?Closure $key = null;
    /** A list showing its end keeps showing it as rows are appended (a chat, a log), until the user scrolls up. */
    public bool $followEnd = false;
    /** Set by Layout each frame: the last row's bottom is in view. */
    public bool $atEnd = false;
    private bool $didChange = false;
    /** For a table: the widths the user dragged its columns to, by column id. */
    public array $columnWidths = [];
    /** For a table: [column id, descending] the rows are sorted by, chosen by header clicks, or null; the app sorts its rows by it. */
    public ?array $sort = null;
    private bool $didSort = false;

    /** Whether a table's sort changed since last asked. */
    public function sortChanged(): bool
    {
        $s = $this->didSort;
        $this->didSort = false;
        return $s;
    }

    /** Sorts by column $id, or reverses when already by it. */
    public function sortBy(string $id): void
    {
        $this->sort = [$id, $this->sort !== null && $this->sort[0] === $id && !$this->sort[1]];
        $this->didSort = true;
    }
    private bool $didSubmit = false;
    private ?int $target = null;

    public function height(int $row): float
    {
        return $this->heights[$row] ?? ($this->isMeasured($row) ? $this->default : $this->estimate);
    }

    /** Row $row was laid out $h high: known from now on; the estimate for the rest is the average of what was measured. */
    public function measured(int $row, float $h): void
    {
        if (!$this->isMeasured($row)) {
            $this->estimate = ($this->estimate * $this->measuredCount + $h) / ($this->measuredCount + 1);
            $this->measuredCount++;
            $byte = $row >> 3;
            if ($byte >= strlen($this->known)) { $this->known .= str_repeat("\0", $byte + 1 - strlen($this->known)); }
            $this->known[$byte] = chr(ord($this->known[$byte]) | (1 << ($row & 7)));
        }
        $this->default ??= $h;
        if ($h === $this->default) { unset($this->heights[$row]); } else { $this->heights[$row] = $h; }
    }

    /** Forgets every height measured: the rows changed under the list (a log reopened). */
    public function forgetHeights(): void
    {
        $this->heights = []; $this->known = ''; $this->default = null; $this->measuredCount = 0;
    }

    private function isMeasured(int $row): bool
    {
        $byte = $row >> 3;
        return $byte < strlen($this->known) && (ord($this->known[$byte]) & (1 << ($row & 7))) !== 0;
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

    /** Shows the end: the last rows fill the view. */
    public function end(): void
    {
        $this->target = null;
        $this->row = max(0, $this->count - 1);
        $this->offset = $this->height($this->row) - $this->viewport;
        $this->scrollBy(0);
    }

    /** Scrolls as little as needed to bring row $row into view. */
    public function scrollIntoView(int $row): void
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

    /** Chooses row $row (clamped; -1 for none) and brings it into view; with a selection, alone. */
    public function choose(int $row): void
    {
        $row = max(-1, min($this->count - 1, $row));
        if ($this->selection !== null && $row >= 0 && $this->selection->choose($row, $this->keyOf(...))) {
            $this->didChange = true;
        }
        if ($row !== $this->selected) {
            $this->selected = $row;
            $this->didChange = true;
        }
        if ($row >= 0) {
            $this->scrollIntoView($row);
        }
    }

    /** Chooses row $row as a click with $mods (Key::SHIFT, Key::COMMAND) does: choose() without a selection. */
    public function click(int $row, int $mods): void
    {
        if ($this->selection === null) { $this->choose($row); return; }
        $row = max(0, min($this->count - 1, $row));
        if ($this->selection->click($row, $mods, $this->count, $this->keyOf(...), $this->selected)) { $this->didChange = true; }
        $this->scrollIntoView($row);
    }

    /** Extends the choice from the pivot to $row with Shift and the keys; choose() without a selection. */
    public function extendTo(int $row): void
    {
        if ($this->selection === null) { $this->choose($row); return; }
        $row = max(0, min($this->count - 1, $row));
        if ($this->selection->extend($row, $this->count, $this->keyOf(...))) { $this->didChange = true; }
        $this->selected = $row;
        $this->scrollIntoView($row);
    }

    /** Chooses every row (Cmd+A), with a selection. */
    public function selectAll(): void
    {
        if ($this->selection !== null && $this->selection->selectAll($this->count, $this->keyOf(...))) { $this->didChange = true; }
    }

    /** The rows a drag from row $i moves: those chosen when it is one of them, else row $i (mygo's listFrame.dragged). @return list<int> */
    public function draggedRows(int $i): array
    {
        if ($this->selection === null || !$this->chosen($i)) { return [$i]; }
        $rows = [];
        for ($j = 0; $j < $this->count; $j++) { if ($this->chosen($j)) { $rows[] = $j; } }
        return $rows;
    }

    /** Whether row $row is chosen: in the selection, or the one selected. */
    public function chosen(int $row): bool
    {
        return $this->selection !== null ? $this->selection->has($this->keyOf($row)) : $row === $this->selected;
    }

    /** The key of row $row: $key's, or the index. */
    public function keyOf(int $row): int|string
    {
        return $this->key === null ? $row : ($this->key)($row);
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

    /** Scrolls rows wider than the list sideways by $dx points. */
    public function scrollByX(float $dx): void
    {
        $this->x = max(0.0, min($this->maxX, $this->x + $dx));
    }

    /** How far it is scrolled right, how far it can go and the view's width. @return array{0: float, 1: float, 2: float} */
    public function extentX(): array
    {
        return [$this->x, $this->maxX, $this->viewportW];
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
