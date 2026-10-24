<?php
declare(strict_types=1);

namespace MyPHP\UI;

/** The parts of a tab list without a look (mygo's TabsParts): $list, which holds the tabs tab() builds; end() it after them. */
final class TabsParts
{
    /** @param int $selected the app's, which a choice changes */
    public function __construct(public readonly Node $list, private readonly Context $c, private int &$selected, private readonly int $n) {}

    /** Tab $i: a row taking the focus that chooses $i when clicked, or as the arrows, Home and End reach it; build in it, end() it, and style it from whether $i is the one chosen. */
    public function tab(int $i): Node
    {
        $tab = $this->c->row()->focusable()->shrink(0)->role(Role::TAB);
        if ($this->c->clicked($tab) && $i !== $this->selected) {
            $this->selected = $i;
            $this->list->changed = true;
        }
        $tab->on = $i === $this->selected; // Tab enters the list at the one chosen
        return $tab->checked($tab->on);
    }
}
