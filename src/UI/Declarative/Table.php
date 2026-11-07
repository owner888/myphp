<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use Closure;
use MyPHP\UI\{Context, ListState, Node};

/**
 * Table, as SwiftUI's on macOS: Table($rows, selection: $this->chosen)(
 * TableColumn('Name', fn($row) => Text(…)), … ). Only the rows in view are
 * built; the user resizes and moves the columns by their headers, which it
 * keeps. selection binds the id of the row chosen, or an array of those
 * chosen; contextMenu(primaryAction:) runs on a double click or Return.
 */
final class Table extends View
{
    /** What a binding of Table() not given holds. @internal */
    public const UNBOUND = "\0unbound";

    /** @var list<TableColumn> */
    private array $columns = [];
    private mixed $selection = null;
    private bool $selects = false;
    private ?Binding $binding = null;

    /** The choice into the Binding given as the selection, when it changed. @internal */
    public function commitBinding(): void
    {
        if ($this->binding !== null && $this->selection !== $this->binding->get()) { $this->binding->set($this->selection); }
    }
    private ?Closure $primaryAction = null;
    private bool $bottom = false;
    private mixed $sortOrder = null;
    private bool $sorts = false;

    /** @param array<mixed>|int $rows an int $n for the rows 0 to $n - 1 */
    public function __construct(public readonly array|int $rows, public readonly string|Closure|null $id = null, mixed &$selection = null, bool $selects = false, mixed &$sortOrder = null, bool $sorts = false)
    {
        if ($selects) { if ($selection instanceof Binding) { $this->binding = $selection; $this->selection = $selection->get(); } else { $this->selection = &$selection; } $this->selects = true; } // a variable, or a Binding(get:set:)
        if ($sorts) { $this->sortOrder = &$sortOrder; $this->sorts = true; }
    }

    public function __invoke(TableColumn ...$columns): static
    {
        $this->columns = array_values($columns);
        return $this;
    }

    /** Where it starts, and stays as rows come: UnitPoint::bottom, a log's, as SwiftUI's defaultScrollAnchor. */
    public function defaultScrollAnchor(\UnitPoint $anchor): static { $this->bottom = $anchor === \UnitPoint::bottom; return $this; }

    /** contextMenu(primaryAction: fn(array $ids) => …), what a double click or Return does to the rows chosen; otherwise the view's menu. */
    public function contextMenu(View|array|null|Closure ...$items): static
    {
        if (isset($items['menu'])) { throw new \LogicException('a Table\'s rows take no menu yet: contextMenu(primaryAction:) only'); }
        if (!isset($items['primaryAction'])) { return parent::contextMenu(...$items); }
        if (!$this->selects) { throw new \LogicException('contextMenu(primaryAction:) acts on the rows chosen of a Table($rows, selection: $this->chosen)'); }
        $this->primaryAction = $items['primaryAction'];
        return $this;
    }

    protected function build(Context $c, Env $env): ?Node
    {
        $wrap = $c->column(0, 0); // its state kept by a node of its own, apart from what the table keeps by its (its column drags)
        $kept = $c->runtime?->local($wrap->id, fn() => new TableKept(), TableKept::class) ?? new TableKept();
        $state = $kept->state;
        foreach ($this->columns as $i => $col) { // the engine's columns kept, the widths the user gave them with them
            $title = Localized::string($col->title, $env);
            $e = $kept->columns[$i] ??= new \MyPHP\UI\TableColumn($title, $col->width ?? 0, $col->minWidth ?? 0, $col->align, $this->sorts && $col->value !== null, $col->sortId(), $col->maxWidth ?? 0, $col->fixed);
            $e->title = $title; $e->align = $col->align;
        }
        $kept->columns = array_slice($kept->columns, 0, count($this->columns));
        $count = is_int($this->rows) ? $this->rows : count($this->rows);
        $items = is_int($this->rows) ? null : array_values($this->rows);
        $keys = is_int($this->rows) ? null : array_keys($this->rows);
        $idOf = fn(int $i): int|string|null => match (true) {
            $this->id instanceof Closure => ($this->id)($items === null ? $i : $items[$i], $keys === null ? $i : $keys[$i]),
            is_string($this->id) => is_array($items[$i]) ? $items[$i][$this->id] : $items[$i]->{$this->id},
            $items !== null && (is_int($items[$i]) || is_string($items[$i])) => $items[$i], // a string or an int its own id, as Each's
            default => $keys === null ? $i : $keys[$i],
        };
        if ($this->selects) { ListView::bind($state, $count, $idOf, $this->selection); }
        if ($this->sorts && $state->sort != $this->sortOrder) { $state->sort = $this->sortOrder; } // set by the app
        if ($this->bottom && !$state->followEnd) { $state->followEnd = true; $state->count = $count; $state->scrollToEnd(); } // from its end, as it first shows
        if ($env->scrollProxy !== null) { $env->scrollProxy->list = ListView::scroller($state, $count, $idOf); }
        $cellEnv = $env->along(Node::ROW);
        $n = $c->table($state, $kept->columns, $count, function (int $r, int $col) use ($c, $cellEnv, $items): void {
            View::renderAll($c, $cellEnv, [$this->columns[$col]->cell($items === null ? $r : $items[$r])]);
        });
        $n->alignSelf(Node::STRETCH);
        $c->end();
        if ($this->selects) { ListView::chose($state, $idOf, $this->selection, $this->primaryAction); $this->commitBinding(); }
        if ($this->sorts && $state->sortChanged()) { $this->sortOrder = $state->sort; } // a header clicked: the app sorts its rows (onChange(of: sortOrder))
        View::takeRoom($wrap, $env->axis, View::FLEX_X | View::FLEX_Y);
        return $wrap;
    }
}
