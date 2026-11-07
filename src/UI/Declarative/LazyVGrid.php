<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use Closure;
use MyPHP\UI\{Context, Node, Painter, Selection, Track};

/**
 * LazyVGrid, as SwiftUI's with an adaptive column (GridItem::adaptive):
 * as many columns as fit cells $minimum wide at least in the width it is
 * given (as it was laid out the frame before), sharing the width left over,
 * its views laid out in them row by row, $spacing apart. Of one Each, with
 * selection: — as myphp adds, after a List's and Finder's icon view — only
 * the rows in view are built, scrolling, a click choosing an item (Cmd- and
 * Shift-click several, into an array), the arrows moving the choice, and
 * contextMenu(primaryAction:) running on a double click or Return.
 */
final class LazyVGrid extends View
{
    /** What a binding of LazyVGrid() not given holds. @internal */
    public const UNBOUND = "\0unbound";

    /** @var list<mixed> */
    private array $content = [];
    private mixed $selection = null;
    private bool $selects = false;
    private ?Binding $binding = null;

    /** The choice into the Binding given as the selection, when it changed. @internal */
    public function commitBinding(): void
    {
        if ($this->binding !== null && $this->selection !== $this->binding->get()) { $this->binding->set($this->selection); }
    }
    private ?Closure $primaryAction = null;

    /** @param list<GridItem> $columns */
    public function __construct(public readonly array $columns, public readonly float $spacing = 8, mixed &$selection = null, bool $selects = false)
    {
        if ($selects) { if ($selection instanceof Binding) { $this->binding = $selection; $this->selection = $selection->get(); } else { $this->selection = &$selection; } $this->selects = true; } // a variable, or a Binding(get:set:)
    }

    public function __invoke(View|array|null ...$content): static
    {
        $this->content = $content;
        return $this;
    }

    /** contextMenu(primaryAction: fn(array $ids) => …), what a double click or Return does to the items chosen; otherwise the view's menu. */
    public function contextMenu(View|array|null|Closure ...$items): static
    {
        if (!isset($items['primaryAction'])) { return parent::contextMenu(...$items); }
        if (!$this->selects) { throw new \LogicException('contextMenu(primaryAction:) acts on the items chosen of a LazyVGrid(columns: …, selection: $this->chosen)'); }
        $this->primaryAction = $items['primaryAction'];
        return $this;
    }

    protected function build(Context $c, Env $env): ?Node
    {
        $min = $this->columns[0]->minimum ?? throw new \LogicException('LazyVGrid takes an adaptive column: LazyVGrid(columns: [GridItem::adaptive(minimum: 132)])');
        if ($this->selects) { return $this->chooser($c, $env, $min); }
        $width = $c->runtime?->rectOf($c->upcomingId())[2] ?? $c->width;
        $columns = max(1, (int)floor(($width + $this->spacing) / ($min + $this->spacing)));
        $n = $c->grid(array_fill(0, $columns, Track::fr()), $this->spacing);
          foreach (ListView::flat($this->content) as $v) {
              $v->render($c, $env->along(Node::ROW))?->justifySelf(Node::STRETCH);
          }
        $c->end();
        View::takeRoom($n, $env->axis, View::FLEX_X);
        return $n;
    }

    /** The grid of one Each's items, those in view built, chosen as the binding says. */
    private function chooser(Context $c, Env $env, float $min): Node
    {
        $each = count($this->content) === 1 && $this->content[0] instanceof Each ? $this->content[0] : throw new \LogicException('LazyVGrid(selection:) takes one Each of its items: LazyVGrid(columns: …, selection: $this->chosen)( Each($items, id: \'id\')(fn($item) => …) )');
        $items = is_array($each->items) ? $each->items : iterator_to_array($each->items);
        $keys = array_keys($items); $items = array_values($items);
        $count = count($items);
        $idOf = fn(int $i) => $each->idOf($items[$i], $keys[$i]);
        $wrap = $c->column(0, 0); // its state kept by a node of its own, apart from the grid's
        $kept = $c->runtime?->local($wrap->id, fn() => new GridKept($min), GridKept::class) ?? new GridKept($min);
        $g = $kept->grid;
        $g->selects = true;
        $g->key = $idOf;
        $several = is_array($this->selection);
        if ($several) {
            $g->selection ??= new Selection();
            $want = array_keys(array_flip($this->selection)); $have = $g->selection->all();
            sort($want); sort($have);
            if ($want !== $have) { $g->selection->clear(); foreach ($want as $id) { $g->selection->add($id); } } // set by the app
        } else {
            $at = $g->selected >= 0 && $g->selected < $count ? $idOf($g->selected) : null;
            if ($at !== $this->selection) { for ($i = 0; $i < $count && $idOf($i) !== $this->selection; $i++); $g->selected = $i < $count ? $i : -1; }
        }
        // Rows as high as the first item laid out the frame before (the first frame, as wide as the columns' least).
        $h = $c->runtime?->rectOf($kept->first)[3] ?? null;
        if ($h !== null && $h > 0) { $kept->height = $h + 4; } // the item inside its cell's padding of 2, the border chosen items show
        $cellEnv = $env->along(Node::COLUMN);
        $n = $c->gridView($g, $count, $min, $kept->height, function (int $i) use ($c, $cellEnv, $each, $items, $keys, $kept): void {
            $node = $each->viewOf($items[$i], $keys[$i])?->render($c, $cellEnv);
            if ($i === 0 && $node !== null) { $kept->first = $node->id; }
        });
        $n->alignSelf(Node::STRETCH)->grow = 1;
        $c->end();
        $height = $kept->height;
        $wrap->draw(static function (Painter $p) use ($kept, $height): void { if ($kept->height !== $height) { $p->animationFrame(); } }); // a row height found: built again with it
        if ($c->changed($n)) { $this->selection = $several ? $g->selection->all() : ($g->selected >= 0 ? $idOf($g->selected) : null); $this->commitBinding(); }
        if ($g->rows->submitted() && $this->primaryAction !== null) {
            ($this->primaryAction)($several ? $g->selection->all() : ($g->selected >= 0 ? [$idOf($g->selected)] : []));
        }
        View::takeRoom($wrap, $env->axis, View::FLEX_X | View::FLEX_Y);
        return $wrap;
    }
}
