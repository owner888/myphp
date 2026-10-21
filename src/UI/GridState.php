<?php
declare(strict_types=1);

namespace MyPHP\UI;

use Closure;

/**
 * The state of a gridView, as mygo's GridState: the place of its rows (a
 * ListState), how its items are chosen, and what was typed to choose one.
 */
final class GridState
{
    /** The rows' place and virtualization; the grid scrolls by them. */
    public ListState $rows;
    /** Whether a click chooses an item, as the arrows do while the grid has the focus: then $selected is the item chosen, -1 for none. */
    public bool $selects = false;
    public int $selected = -1;
    /** Set, lets the user choose several items, held by their keys, as a ListState's: Cmd-click adds or takes out, Shift-click and Shift with the arrows choose from the item last chosen, Cmd+A chooses all; $selected is then the item last chosen. */
    public ?Selection $selection = null;
    /** An identity for item $i (Closure(int): int|string), for the selection to follow its items; the index without one. */
    public ?Closure $key = null;
    /** The text of item $i (Closure(int): string): typing its first letters while the grid has the focus chooses it. */
    public ?Closure $label = null;
    /** Set, lets the user drag items to another place (mygo's GridState.Reorder): called with the items dragged (those chosen, when the item dragged is one, else that item), in order, and the item they go before (the count for the end); move them there. */
    public ?Closure $reorder = null;
    /** The items being dragged: items, item (the one pressed), ox, oy, dist, far — as ListState::$drag. */
    public ?array $drag = null;
    /** How many columns the items took in the last frame. */
    public int $cols = 0;
    public bool $started = false;
    /** The first letters typed, and when the last came: they add up while typed within a second (mygo's typePause). */
    public string $typed = '';
    public float $typedAt = 0.0;

    public function __construct()
    {
        $this->rows = new ListState;
    }

    /** The items a drag from item $i moves: those chosen when it is one of them, else item $i. @return list<int> */
    public function draggedItems(int $i, int $n): array
    {
        if ($this->selection === null || !$this->chosen($i)) { return [$i]; }
        $items = [];
        for ($j = 0; $j < $n; $j++) { if ($this->chosen($j)) { $items[] = $j; } }
        return $items;
    }

    /** Whether item $i is chosen: in the selection, or the one selected. */
    public function chosen(int $i): bool
    {
        return $this->selection !== null ? $this->selection->has($this->keyOf($i)) : $i === $this->selected;
    }

    public function keyOf(int $i): int|string
    {
        return $this->key === null ? $i : ($this->key)($i);
    }

    /** Scrolls the grid to show item $item at the top. */
    public function scrollTo(int $item): void
    {
        $this->rows->scrollTo(intdiv($item, max($this->cols, 1)), false);
    }

    /** Scrolls the grid as little as shows item $item. */
    public function scrollIntoView(int $item): void
    {
        $this->rows->scrollIntoView(intdiv($item, max($this->cols, 1)));
    }
}
