<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/** The vertical scroll several editors share, as the sides of a split diff (mygo's sharedScroll). */
final class SharedScroll
{
    public float $y = 0.0;
    /** @var list<Editor> */
    public array $eds = [];

    public function setX(float $x): void
    {
        foreach ($this->eds as $ed) { $ed->scrollX = max(0.0, min($x, $ed->maxScrollX())); }
    }

    public function set(float $y): void
    {
        $m = 0.0;
        foreach ($this->eds as $ed) { $m = max($m, $ed->scrollMax()); }
        $this->y = max(0.0, min($y, $m));
        foreach ($this->eds as $ed) { $ed->scrollY = $this->y; }
    }
}
