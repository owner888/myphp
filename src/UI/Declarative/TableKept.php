<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use MyPHP\UI\ListState;

/** What a Table keeps between frames: its rows' state and the engine's columns, with the widths the user gave them. @internal */
final class TableKept
{
    public ListState $state;
    /** @var list<\MyPHP\UI\TableColumn> */
    public array $columns = [];

    public function __construct() { $this->state = new ListState(); }
}
