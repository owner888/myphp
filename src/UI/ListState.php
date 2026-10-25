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
    /** The row the place was on as Context::list built the rows, from $first: Layout counts the place from $first only while it still is. @internal */
    public int $builtRow = 0;
    public float $viewport = 0;
    public float $gap = 0;
    /** How far rows wider than the list (Node::rowWidth) are scrolled sideways, how far they can go and the list's width: Layout sets the last two. */
    public float $x = 0, $maxX = 0, $viewportW = 0;
    /** Glides a list following its end there as rows come, as $followAnimation says, rather than show it at once: what is added comes up from below; none shows it at once (mygo's FollowAnimation). And how far below its place the end shows on the way. */
    public ?Animation $followAnimation = null;
    /** Rounds a table's chosen and hovered rows at the theme's radius (mygo's RoundRows). */
    public bool $roundRows = false;
    /** Gives a table's header a context menu, built as Node::contextMenu's: fn(Menu $m, int $col), $col the column clicked in the columns given, -1 past them (mygo's HeaderMenu). */
    public ?Closure $headerMenu = null;
    public float $lag = 0.0;
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
    /** The text of row $i (Closure(int): string, mygo's Label): typing the first letters of a row's text while the list has the keyboard focus chooses it, and assistive technology reads it as the row's name. */
    public ?Closure $label = null;
    /** Whether row $i heads a section (Closure(int): bool, mygo's Header): the header of the section at the top of the list stays there while the rows of its section scroll under it, until the next header pushes it away. Give headers a background. Headers are not chosen. */
    public ?Closure $header = null;
    /** What was typed to go to a row by its label, and when, in seconds. */
    public string $typed = '';
    public float $typedAt = 0.0;
    /** That an editable text (Context::editableText) was built in a row the last frame: Enter on the chosen row then edits it rather than submits; and that Enter or F2 asked the chosen row's to edit. @internal */
    public bool $editables = false, $editAsked = false;
    /** For a table: the order and the widths the user gave its columns by dragging their headers (mygo's Columns). */
    public TableLayout $columns;
    /** For a table: the column the rows are sorted by, chosen by a click on a sortable column's header, which reverses it on a second; null for none. The app sorts its rows by it (mygo's Sort). With $sortBy, the first of those. */
    public ?SortOrder $sort = null;
    /**
     * For a table, null or the columns the rows are sorted by, first to
     * last (mygo's SortBy): a Cmd-click or a Shift-click on a sortable
     * column's header adds it after the others, a second makes it
     * descending, a third takes it out; a click sorts by it alone. A click
     * gives a new list, so one the app kept stays as it was.
     * @var ?list<SortOrder>
     */
    public ?array $sortBy = null;
    private bool $didSort = false;

    public function __construct()
    {
        $this->columns = new TableLayout();
    }

    /** The list built with this state in a pass of a context, the focus owner, and the pass (mygo's listFrame owner). */
    private ?Node $owner = null;
    private ?Context $ownerContext = null;
    private int $ownerPass = -1;

    /** Notes the list Context::list builds with this state in this pass of $c. @internal */
    public function own(Context $c, Node $n): void
    {
        if ($this->current($c) !== null) {
            throw new \LogicException('a ListState shown by two lists'); // the place, the heights and the choice are one list's (mygo's buildList)
        }
        [$this->owner, $this->ownerContext] = [$n, $c];
        $this->ownerPass = $c->runtime === null ? -1 : $c->runtime->frameNumber * 8 + $c->runtime->pass;
    }

    /** The list built with this state in the current pass of $c, or null: one hidden, or not built yet, is none (mygo's current). */
    private function current(Context $c): ?Node
    {
        $rt = $c->runtime;
        return $rt !== null && $this->ownerContext === $c && $this->ownerPass === $rt->frameNumber * 8 + $rt->pass ? $this->owner : null;
    }

    /** Whether the list, table or outline using this state has the keyboard focus; false when it was not built in the current pass of $c, as when hidden. Call it after building the list, or while building its rows (mygo's Focused). */
    public function focused(Context $c): bool
    {
        $n = $this->current($c);
        return $n !== null && $c->focused($n);
    }

    /** Whether the list or one inside it has the keyboard focus, as focused() (mygo's FocusWithin): row builders can ask it before the list returns. */
    public function focusWithin(Context $c): bool
    {
        $n = $this->current($c);
        return $n !== null && $c->focusWithin($n);
    }

    /** Gives the list the keyboard focus, and reports whether it was built in the current pass of $c: a focus asked for can wait while the list is hidden (mygo's Focus). */
    public function focus(Context $c): bool
    {
        $n = $this->current($c);
        if ($n === null) {
            return false;
        }
        $c->runtime->focusOn($n->id);
        return true;
    }

    /** Whether $key with $mods was pressed while the list or one inside it had the focus (mygo's Shortcut); a list not built in the current pass of $c takes none. */
    public function shortcut(Context $c, int|string $key, int $mods = 0): bool
    {
        $n = $this->current($c);
        return $n !== null && $c->focusWithin($n) && $c->shortcut($key, $mods);
    }

    /** Whether a table's sort changed since last asked. */
    public function sortChanged(): bool
    {
        $s = $this->didSort;
        $this->didSort = false;
        return $s;
    }

    /** The sort after a click on the header of column $id, a Cmd- or Shift-click when $add (mygo's sortClicked). @internal */
    public function sortClicked(string $id, bool $add): void
    {
        if ($this->sortBy === null) {
            $this->sort = new SortOrder($id, $this->sort !== null && $this->sort->column === $id && !$this->sort->descending);
        } else {
            $by = $this->sortBy;
            $i = null;
            foreach ($by as $k => $o) { if ($o->column === $id) { $i = $k; break; } }
            if (!$add) {
                $by = [new SortOrder($id, $i === 0 && !$by[0]->descending)];
            } elseif ($i === null) {
                $by[] = new SortOrder($id);
            } elseif (!$by[$i]->descending) {
                $by[$i] = new SortOrder($id, true);
            } else {
                array_splice($by, $i, 1);
            }
            $this->sortBy = $by;
            $this->syncSort();
        }
        $this->didSort = true;
    }

    /** Keeps $sort, with $sortBy, the first column $sortBy sorts by, or null when it sorts by none (mygo's syncSort). @internal */
    public function syncSort(): void
    {
        if ($this->sortBy !== null) { $this->sort = $this->sortBy[0] ?? null; }
    }

    /** The place of column $id among those the rows are sorted by, from 0, and whether descending; null when they are not sorted by it (mygo's sortPlace). @return ?array{0: int, 1: bool} */
    public function sortPlace(string $id): ?array
    {
        foreach ($this->sortBy ?? ($this->sort !== null ? [$this->sort] : []) as $i => $o) {
            if ($o->column === $id) { return [$i, $o->descending]; }
        }
        return null;
    }

    /** Gives column $col the width $w the user chose, within its bounds (mygo's setWidth). @internal */
    public function setWidth(TableColumn $col, float $w): void
    {
        $this->columns->widths[$col->id()] = $col->clampWidth($w);
    }
    private bool $didSubmit = false;
    private ?int $target = null;
    /** How far from where it was a list looks for an item's key when its rows changed (mygo's listSearch). */
    private const SEARCH = 1000;
    /** The key of the anchor row last frame, and the row chosen then with its key: rows coming or going above them move them with their items (mygo's anchorKey, selRow, selKey). */
    private int|string|null $anchorKey = null;
    private int $anchorRow = 0;
    private int $selRow = -1;
    private int|string|null $selKey = null;

    /**
     * Follows what changed since the last frame, with $key (mygo's sync):
     * rows came or went above the anchor, which moves with its item, as
     * the heights known do; the chosen row follows its item, and is gone
     * with it. The app choosing another row is not rows moving.
     */
    public function sync(int $count): void
    {
        if ($this->key === null || $count === 0) {
            return;
        }
        if ($this->anchorKey !== null && ($j = $this->find($this->anchorKey, $this->anchorRow, $count)) !== null && $j !== $this->anchorRow) {
            // Rows came or went above it: the heights known move with the rows around it, and the place with its item, by as much as the wheel or the app moved it since.
            $this->shiftHeights($j - $this->anchorRow);
            $this->row = max(0, min($count - 1, $this->row + $j - $this->anchorRow));
        }
        if ($this->selected !== null && $this->selected === $this->selRow && $this->selKey !== null) {
            $this->selected = $this->selRow = $this->find($this->selKey, $this->selected, $count) ?? -1;
        }
    }

    /** Notes the anchor and the choice of this frame, by their keys, for the next sync(). */
    public function remember(): void
    {
        $this->anchorRow = $this->row;
        $this->anchorKey = $this->key !== null && $this->row < $this->count ? $this->keyOf($this->row) : null;
        $this->selRow = $this->selected ?? -1;
        $this->selKey = $this->key !== null && $this->selRow >= 0 && $this->selRow < $this->count ? $this->keyOf($this->selRow) : null;
    }

    /** The row whose key is $k, looking around row $near (mygo's find). */
    private function find(int|string $k, int $near, int $count): ?int
    {
        for ($d = 0; $d <= self::SEARCH; $d++) {
            [$lo, $hi] = [$near - $d, $near + $d];
            if ($lo < 0 && $hi >= $count) {
                break;
            }
            if ($lo >= 0 && $lo < $count && $this->keyOf($lo) === $k) {
                return $lo;
            }
            if ($d > 0 && $hi >= 0 && $hi < $count && $this->keyOf($hi) === $k) {
                return $hi;
            }
        }
        return null;
    }

    /** Moves the heights known by $d rows, as $d rows came above them (or went, below 0). */
    private function shiftHeights(int $d): void
    {
        $heights = [];
        foreach ($this->heights as $row => $h) {
            if ($row + $d >= 0) { $heights[$row + $d] = $h; }
        }
        $this->heights = $heights;
        $known = '';
        for ($byte = 0, $n = strlen($this->known); $byte < $n; $byte++) {
            if (($bits = ord($this->known[$byte])) === 0) { continue; }
            for ($b = 0; $b < 8; $b++) {
                if (($bits & (1 << $b)) === 0 || ($row = ($byte << 3) + $b + $d) < 0) { continue; }
                $at = $row >> 3;
                if ($at >= strlen($known)) { $known .= str_repeat("\0", $at + 1 - strlen($known)); }
                $known[$at] = chr(ord($known[$at]) | (1 << ($row & 7)));
            }
        }
        $this->known = $known;
    }

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
        while ($this->offset < 0 && $this->row > 0 && $this->isMeasured($this->row - 1)) {
            $this->row--;
            $this->offset += $this->height($this->row);
        }
        // Rows above not measured yet: the place stays on the row it knows, the view's top above it, and the list builds the
        // rows there, which Layout measures and counts the place from, so that a step moves the rows by the step (mygo's anchor).
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

    /** Shows the end: the last rows fill the view (mygo's ScrollToEnd). */
    public function scrollToEnd(): void
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

    /** Whether row $row heads a section ($header). */
    public function isHeader(int $row): bool
    {
        return $this->header !== null && $row >= 0 && $row < $this->count && ($this->header)($row);
    }

    /** The header at or above row $row, -1 for none. */
    public function headerAbove(int $row): int
    {
        for ($j = min($row, $this->count - 1); $j >= 0; $j--) {
            if (($this->header)($j)) { return $j; }
        }
        return -1;
    }

    /** $row, or past a header the nearest row that is not one, going $dir (1 down, -1 up), else the other way; -1 for none. */
    private function rowOff(int $row, int $dir): int
    {
        if ($this->header === null || $row < 0) { return $row; }
        foreach ([$dir, -$dir] as $d) {
            for ($j = $row; $j >= 0 && $j < $this->count; $j += $d) {
                if (!($this->header)($j)) { return $j; }
            }
        }
        return -1;
    }

    /** Chooses row $row (clamped; -1 for none) and brings it into view; with a selection, alone. A header is passed over, toward where the choice moves. */
    public function choose(int $row): void
    {
        $row = max(-1, min($this->count - 1, $row));
        $row = $this->rowOff($row, $row >= ($this->selected ?? -1) ? 1 : -1);
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
        $row = $this->rowOff(max(0, min($this->count - 1, $row)), $row >= ($this->selected ?? -1) ? 1 : -1);
        if ($row < 0) { return; }
        if ($this->selection->extend($row, $this->count, $this->keyOf(...))) { $this->didChange = true; }
        $this->selected = $row;
        $this->scrollIntoView($row);
    }

    /** Chooses every row (Cmd+A), with a selection. */
    public function selectAll(): void
    {
        if ($this->selection === null) { return; }
        $changed = false;
        for ($j = 0; $j < $this->count; $j++) {
            if (!$this->isHeader($j)) { $changed = $this->selection->set($this->keyOf($j), true) || $changed; } // headers are not chosen
        }
        if ($changed) { $this->didChange = true; }
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
