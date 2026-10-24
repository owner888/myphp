<?php
declare(strict_types=1);

namespace MyPHP\UI;

/**
 * The state of an outline or an outline table (Context::outline,
 * outlineTable): its rows, as a list's, and the items open, showing their
 * children (mygo's OutlineState). The rows' keys are the items, ints or
 * strings, so that their place, choice and state follow them as items
 * open and close above them.
 */
final class OutlineState
{
    /** The rows' place and how they are chosen, as a list's; its key is the rows' items. */
    public ListState $list;
    /** The items showing their children. */
    public Selection $open;
    /** The width each level adds before a row's arrow, the theme's Space(4) when 0. */
    public float $indent = 0;
    /** @var list<array{item: int|string, depth: int, parent: int, branch: bool}> the rows the last build showed */
    private array $rows = [];

    public function __construct()
    {
        $this->list = new ListState;
        $this->open = new Selection;
        $this->list->key = fn(int $i): int|string => $this->rows[$i]['item'];
    }

    /** How many rows the outline shows: its items at the top and the children of those open, in the last build. */
    public function rows(): int
    {
        return count($this->rows);
    }

    /** The item row $i shows. */
    public function item(int $row): int|string
    {
        return $this->rows[$row]['item'];
    }

    /** How deep the item of row $i is: 0 for the items at the top. */
    public function depth(int $row): int
    {
        return $this->rows[$row]['depth'];
    }

    /** Whether row $i's item has children, and whether it shows them. @internal @return array{0: bool, 1: bool} */
    public function branch(int $row): array
    {
        $r = $this->rows[$row];
        return [$r['branch'], $r['branch'] && $this->open->has($r['item'])];
    }

    /** Lists the rows shown: the roots, and the children of the items open. @param list<int|string> $roots @param \Closure(int|string): ?list<int|string> $children @internal */
    public function flatten(array $roots, \Closure $children): void
    {
        if ($this->indent < 0) {
            throw new \InvalidArgumentException("an OutlineState's indent must not be negative");
        }
        $this->rows = [];
        $walk = function (array $items, int $depth, int $parent) use (&$walk, $children): void {
            foreach ($items as $item) {
                $kids = $children($item);
                $this->rows[] = ['item' => $item, 'depth' => $depth, 'parent' => $parent, 'branch' => $kids !== null];
                if ($kids !== null && $this->open->has($item)) {
                    $walk($kids, $depth + 1, count($this->rows) - 1);
                }
            }
        };
        $walk($roots, 0, -1);
    }

    /** Opens or closes $item, with $all every item inside it too. @internal */
    public function setOpen(int|string $item, bool $open, bool $all, \Closure $children): void
    {
        $open ? $this->open->add($item) : $this->open->remove($item);
        if ($all) {
            foreach ($children($item) ?? [] as $kid) {
                if ($children($kid) !== null) { $this->setOpen($kid, $open, true, $children); }
            }
        }
    }

    /** Row $i closed: the choice inside it, which no longer shows, moves to it. @internal */
    public function closed(int $i): void
    {
        $sel = $this->list->selected;
        if ($sel === null || $sel <= $i) {
            return;
        }
        for ($p = $sel; $p >= 0 && isset($this->rows[$p]); $p = $this->rows[$p]['parent']) {
            if ($p === $i) {
                $this->list->choose($i);
                return;
            }
        }
    }

    /** The keys while the outline has the focus: Right ($forth) opens the item chosen or goes to its first child, Left closes it or goes to its parent; with $all, all inside opens or closes. @internal */
    public function keys(bool $forth, bool $back, bool $all, \Closure $children): void
    {
        $i = $this->list->selected;
        if ($i === null || $i < 0 || $i >= count($this->rows)) {
            return;
        }
        $r = $this->rows[$i];
        $open = $r['branch'] && $this->open->has($r['item']);
        if ($forth && $r['branch'] && (!$open || $all)) {
            $this->setOpen($r['item'], true, $all, $children);
        } elseif ($forth && $open && $i + 1 < count($this->rows) && $this->rows[$i + 1]['parent'] === $i) {
            $this->list->choose($i + 1);
        } elseif ($back && $open) {
            $this->setOpen($r['item'], false, $all, $children);
            $this->closed($i);
        } elseif ($back && $r['parent'] >= 0) {
            $this->list->choose($r['parent']);
        }
    }
}
