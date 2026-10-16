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
    /** The text of item $i (Closure(int): string): typing its first letters while the grid has the focus chooses it. */
    public ?Closure $label = null;
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

    /** Scrolls the grid to show item $item at the top. */
    public function scrollTo(int $item): void
    {
        $this->rows->scrollTo(intdiv($item, max($this->cols, 1)), false);
    }

    /** Scrolls the grid as little as shows item $item. */
    public function reveal(int $item): void
    {
        $this->rows->reveal(intdiv($item, max($this->cols, 1)));
    }
}
