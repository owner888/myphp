<?php
declare(strict_types=1);

namespace MyPHP\UI;

/** A column of Context::table, as mygo's TableColumn. */
final class TableColumn
{
    /**
     * @param string $title heads the column
     * @param float $width points, or 0 to share the room the others leave; the user resizes the column by dragging the end edge of its header, between $minWidth and $maxWidth, and fits it to its cells with a double click there
     * @param float $minWidth the least the user drags it to (and a shared column's least share)
     * @param int $align Node::START, CENTER or END for the cells' content
     * @param bool $sortable a click on the header sorts by it (ListState::$sort or $sortBy)
     * @param ?string $id names the column for its order, width and sort (ListState::$columns, $sort, $sortBy); the title when null
     * @param float $maxWidth the most the user drags it to, 0 for no limit
     * @param bool $fixed keeps the column where it is and as wide as it is: the user neither moves nor resizes it
     * @param bool $rowHeader marks a column of row headers, as a spreadsheet's row numbers: in a table choosing cells (ListState::$cellSelect), a click or a drag on its cells chooses whole rows, with every column, and a click on its header chooses every row; it does not sort, and is never the cursor's column
     */
    public function __construct(
        public string $title,
        public float $width = 0,
        public float $minWidth = 0,
        public int $align = Node::START,
        public bool $sortable = false,
        public ?string $id = null,
        public float $maxWidth = 0,
        public bool $fixed = false,
        public bool $rowHeader = false,
    ) {}

    /** Whether a click on its header sorts by it: a row header's does not (mygo's sortable). */
    public function sorts(): bool
    {
        return $this->sortable && !$this->rowHeader;
    }

    public function id(): string
    {
        return $this->id ?? $this->title;
    }

    /** $w between the column's least and largest widths, and at least 16 (mygo's clampWidth). */
    public function clampWidth(float $w): float
    {
        if ($this->maxWidth > 0) { $w = min($w, $this->maxWidth); }
        return max($w, $this->minWidth, 16.0);
    }
}
