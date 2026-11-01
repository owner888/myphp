<?php
declare(strict_types=1);

namespace MyPHP\Platform;

/** A snapshot of a menu tree (mygo's platform.Menu), which a backend builds its native menu of. */
final class Menu
{
    /**
     * @param list<MenuItem> $items
     * @param bool $rightToLeft lays a context menu out right to left, as that
     *     of an interface laid out so: its items from the right, opening to
     *     the left of where it shows, its top right corner
     */
    public function __construct(public array $items = [], public bool $rightToLeft = false) {}

    /**
     * Gives the items of the menu that it is a snapshot of, by id, $it's
     * state: a backend that keeps a snapshot to build menus from later (a
     * tray's) keeps it current with the updates it is given.
     */
    public function apply(MenuItem $it): void
    {
        foreach ($this->items as $i => $x) {
            if ($x->id === $it->id) {
                $this->items[$i] = clone $it;
                continue;
            }
            $x->submenu?->apply($it);
        }
    }
}
