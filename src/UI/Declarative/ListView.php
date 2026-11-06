<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use Closure;
use MyPHP\UI\{Context, ListState, Node};

/**
 * ListView, SwiftUI's List (list is PHP's): rows one under the other,
 * scrolled, filling the room it is given. Of data —
 * ListView($items, id: 'id')(fn($item) => …) — only the rows in view are
 * built, so it holds a hundred thousand, each a row with a separator under
 * it, keeping its identity by its id. Of views — ListView()(Section(…)(…),
 * …) — the sections of an inset grouped list, as iOS's Settings; rows
 * outside a section go in one of their own. NavigationLinks in it are rows
 * with a chevron.
 */
final class ListView extends View
{
    /** @var list<mixed> */
    private array $content = [];
    private ?Closure $row = null;

    /** @param array<mixed>|int|null $items an int $n for the rows 0 to $n - 1, as SwiftUI's List(0..<n) */
    public function __construct(public readonly array|int|null $items = null, public readonly string|Closure|null $id = null) {}

    /** The sections and rows; or, of data, the row of an item: fn($item) => …. */
    public function __invoke(View|array|Closure|null ...$content): static
    {
        if ($this->items !== null) {
            $this->row = $content[0] instanceof Closure ? $content[0] : throw new \LogicException('ListView($items) takes the row of an item: ListView($items, id: \'id\')(fn($item) => …)');
        } else {
            $this->content = $content;
        }
        return $this;
    }

    protected function build(Context $c, Env $env): ?Node
    {
        return $this->items !== null ? $this->dataRows($c, $env) : self::grouped($c, $env, $this->content);
    }

    /** The rows of $items, those in view built. */
    private function dataRows(Context $c, Env $env): Node
    {
        $rt = $c->runtime;
        $state = $rt === null ? new ListState() : $rt->local($c->upcomingId(), fn() => new ListState());
        $count = is_int($this->items) ? $this->items : count($this->items);
        $items = is_int($this->items) ? null : array_values($this->items);
        $keys = is_int($this->items) ? null : array_keys($this->items);
        $row = $this->row;
        $rowEnv = $env->along(Node::COLUMN)->with(['inList' => true]);
        $n = $c->list($state, $count, function (int $i) use ($c, $rowEnv, $items, $keys, $row): void {
            $item = $items === null ? $i : $items[$i];
            $v = $row($item, $keys === null ? $i : $keys[$i]);
            if ($v === null) { return; }
            if ($v instanceof View) {
                $v->identity ??= match (true) {
                    $this->id instanceof Closure => ($this->id)($item),
                    is_string($this->id) => is_array($item) ? $item[$this->id] : $item->{$this->id},
                    default => $items === null ? $i : null,
                };
            }
            self::row($c, $rowEnv, $v, true);
        });
        $n->alignSelf(Node::STRETCH);
        $n->grow = max($n->grow, 1); // the room it is given, along the stack it is in and across it
        return $n;
    }

    /**
     * A row of a list holding $v: a NavigationLink a cell with a chevron, a
     * Button a cell in the tint, any other view in the padding of a cell;
     * with $separator a hairline under it from 16 in. @internal
     */
    public static function row(Context $c, Env $env, View|array $v, bool $separator = false): void
    {
        if ($v instanceof NavigationLink || $v instanceof Section || $v instanceof LabeledContent) { // cells of their own
            $n = $v->render($c, $env);
        } elseif ($v instanceof Button && $v->label === null) {
            $n = $c->cell($v->title);
            if ($n !== null && $c->clicked($n)) { $v->run(); }
            if ($v->role !== \ButtonRole::destructive) { $n->kids[0]?->textColor($env->tint?->rgb($env) ?? $env->theme->accent); } else { $n->kids[0]?->textColor($env->theme->danger); }
        } else {
            $n = $c->row(0, 8)->alignSelf(Node::STRETCH)->minHeight(44)->padding(11, 16)->alignItems(Node::CENTER);
              View::renderAll($c, $env->along(Node::ROW)->with(['inList' => false]), is_array($v) ? $v : [$v]);
            $c->end();
        }
        if ($separator && $n !== null) { $n->borderWidth(0, 0, 1, 0)->borderColor($env->theme->border); }
    }

    /** An inset grouped list of $content: sections, and rows outside one in a section of their own. @internal @param array<mixed> $content */
    public static function grouped(Context $c, Env $env, array $content): Node
    {
        $t = $env->theme;
        $sections = [];
        $loose = [];
        foreach (self::flat($content) as $v) {
            if ($v instanceof Section) {
                if ($loose !== []) { $sections[] = Section()(...$loose); $loose = []; }
                $sections[] = $v;
            } else {
                $loose[] = $v;
            }
        }
        if ($loose !== []) { $sections[] = Section()(...$loose); }
        $n = ScrollView::scroller($c, $env, function (Env $e) use ($c, $sections): void {
            foreach ($sections as $s) { $s->render($c, $e->along(Node::COLUMN)); }
            $c->column()->size(0, 24); $c->end(); // room under the last section
        }, false, 0, $t->groupBackground ?? $t->background);
        return $n;
    }

    /** @param array<mixed> $content @return list<View> */
    public static function flat(array $content): array
    {
        $out = [];
        foreach ($content as $v) {
            if ($v instanceof Each) { array_push($out, ...$v->views()); } // each item a row
            elseif ($v instanceof View) { $out[] = $v; } elseif (is_array($v)) { array_push($out, ...self::flat($v)); }
        }
        return $out;
    }
}
