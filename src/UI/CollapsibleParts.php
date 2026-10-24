<?php
declare(strict_types=1);

namespace MyPHP\UI;

/** The parts of a collapsible without a look (mygo's CollapsibleParts): $trigger, built in until end(), and the panel panel() builds below it. */
final class CollapsibleParts
{
    public function __construct(public readonly Node $trigger, private readonly Context $c, private bool &$open, private readonly float $progress) {}

    /** How far the collapsible is open, from 0 to 1, moving as it opens and closes, at once where the desktop asks for less motion: draw its arrow from it. */
    public function progress(): float
    {
        return $this->progress;
    }

    /** Builds $fn in the panel while it is open, and as it opens and closes, growing with progress() and cutting what $fn built; the column holding it, to style, or null while closed. */
    public function panel(\Closure $fn): ?Node
    {
        return $this->c->collapsiblePanelBase($this->open, $this->progress, $fn);
    }
}
