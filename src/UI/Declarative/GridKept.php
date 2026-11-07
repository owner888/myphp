<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use MyPHP\UI\GridState;

/** What a LazyVGrid(selection:) keeps between frames: its grid's state, its rows' height and the id of its first item, which measures it. @internal */
final class GridKept
{
    public GridState $grid;
    public int $first = 0;

    public function __construct(public float $height) { $this->grid = new GridState(); }
}
