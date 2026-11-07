<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use Closure;
use MyPHP\UI\Node;

/** A column of a Table, as SwiftUI's TableColumn: its title in the header and the view $content makes of each row. */
final class TableColumn
{
    public ?float $width = null, $minWidth = null, $maxWidth = null;
    public bool $fixed = false;
    public int $align = Node::START;

    /**
     * $value, a key of the rows or fn($row), makes it sortable, its header a
     * SortOrder of it, as SwiftUI's TableColumn(_:value:); without $content
     * its cells are the value's Text.
     * @param (Closure(mixed $row): (View|array|null))|null $content
     */
    public function __construct(public readonly string $title, public readonly ?Closure $content = null, public readonly string|Closure|null $value = null)
    {
        if ($content === null && $value === null) { throw new \LogicException("TableColumn('$title') shows a value or a view: TableColumn('$title', value: 'name'), or TableColumn('$title', fn(\$row) => Text(…))"); }
    }

    /** The value of $row the column shows and sorts by. */
    public function valueOf(mixed $row): mixed
    {
        return $this->value instanceof Closure ? ($this->value)($row) : (is_array($row) ? $row[$this->value] : $row->{$this->value});
    }

    /** The view of $row's cell. @internal */
    public function cell(mixed $row): View|array|null
    {
        return $this->content !== null ? ($this->content)($row) : new Text((string)$this->valueOf($row), true);
    }

    /** The id its sort goes by: the key of its value, or its title. */
    public function sortId(): string { return is_string($this->value) ? $this->value : $this->title; }

    /** Its width: $fixed, the user not resizing it; or from $min to $max, $ideal at first, as SwiftUI's width(min:ideal:max:). */
    public function width(?float $fixed = null, ?float $min = null, ?float $ideal = null, ?float $max = null): static
    {
        [$this->width, $this->minWidth, $this->maxWidth, $this->fixed] = [$fixed ?? $ideal, $min, $max, $fixed !== null];
        return $this;
    }

    /** Where its cells and title go across it, as SwiftUI's alignment(.trailing) of a column. */
    public function alignment(\HorizontalAlignment $a): static
    {
        $this->align = match ($a) { \HorizontalAlignment::leading => Node::START, \HorizontalAlignment::center => Node::CENTER, \HorizontalAlignment::trailing => Node::END };
        return $this;
    }
}
