<?php
declare(strict_types=1);

namespace MyPHP\UI;

/** A column of Context::table, as mygo's TableColumn. */
final class TableColumn
{
    /**
     * @param string $title heads the column
     * @param float $width points, or 0 to share the room the fixed columns leave
     * @param float $minWidth the least the user drags it to (and a shared column's least share)
     * @param int $align Node::START, CENTER or END for the cells' content
     * @param bool $sortable a click on the header sorts by it (ListState::$sort), another click reverses
     * @param ?string $id names the column for the widths and the sort; the title when null
     */
    public function __construct(
        public string $title,
        public float $width = 0,
        public float $minWidth = 0,
        public int $align = Node::START,
        public bool $sortable = false,
        public ?string $id = null,
    ) {}

    public function id(): string
    {
        return $this->id ?? $this->title;
    }
}
