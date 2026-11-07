<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use Closure;
use MyPHP\UI\{Context, ListState, Node, Selection};

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
    private mixed $selection = null;
    private bool $selects = false;
    private ?Binding $binding = null;

    /** The choice into the Binding given as the selection, when it changed. @internal */
    public function commitBinding(): void
    {
        if ($this->binding !== null && $this->selection !== $this->binding->get()) { $this->binding->set($this->selection); }
    }
    private ?Closure $menu = null, $primaryAction = null, $move = null;

    /**
     * Lets the rows of a ListView($items) be dragged to another place (several chosen with Cmd, together), as SwiftUI's
     * onMove of the ForEach of a List's rows: $perform(array $rows, int $to) moves the items at the offsets $rows before
     * the one at $to, as the app keeps them. The choice follows its rows by their ids.
     */
    public function onMove(Closure $perform): static { $this->move = $perform; return $this; }

    /** $selection: the id of the row chosen, or an array of the ids chosen, bound; the tag chosen in a NavigationSplitView's sidebar. */
    public function __construct(public readonly array|int|null $items = null, public readonly string|Closure|null $id = null, mixed &$selection = null, bool $selects = false)
    {
        if ($selects) { if ($selection instanceof Binding) { $this->binding = $selection; $this->selection = $selection->get(); } else { $this->selection = &$selection; } $this->selects = true; } // a variable, or a Binding(get:set:)
    }

    /** The binding of the row chosen, which a sidebar writes. @internal */
    public function &selection(): mixed
    {
        if (!$this->selects) { throw new \LogicException("a NavigationSplitView's sidebar is a ListView(selection: …): ListView(selection: \$this->page)( Label('Inbox', systemImage: 'tray')->tag('inbox'), … )"); }
        return $this->selection;
    }

    /**
     * Of a ListView(selection:): $menu(array $ids) the menu of the rows a
     * right click is on, those chosen when it is on one of them; and
     * $primaryAction(array $ids) what a double click or Return does to those
     * chosen, as SwiftUI's contextMenu(forSelectionType:menu:primaryAction:).
     * Otherwise, the view's menu of $items.
     */
    public function contextMenu(View|array|null|Closure ...$items): static
    {
        if (!isset($items['menu']) && !isset($items['primaryAction'])) { return parent::contextMenu(...$items); }
        if (!$this->selects || $this->items === null) { throw new \LogicException('contextMenu(menu:, primaryAction:) acts on the rows chosen of a ListView($items, selection: $this->chosen)'); }
        $this->menu = $items['menu'] ?? null;
        $this->primaryAction = $items['primaryAction'] ?? null;
        return $this;
    }

    /** Its views. @internal @return array<mixed> */
    public function content(): array { return $this->content; }

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
        $state = $rt === null ? new ListState() : $rt->local($c->upcomingId(), fn() => new ListState(), ListState::class);
        $count = is_int($this->items) ? $this->items : count($this->items);
        $items = is_int($this->items) ? null : array_values($this->items);
        $keys = is_int($this->items) ? null : array_keys($this->items);
        $idOf = function (int $i) use ($items, $keys): int|string|null {
            $item = $items === null ? $i : $items[$i];
            return match (true) {
                $this->id instanceof Closure => ($this->id)($item, $keys === null ? $i : $keys[$i]),
                is_string($this->id) => is_array($item) ? $item[$this->id] : $item->{$this->id},
                is_int($item) || is_string($item) => $item, // a string or an int its own id, as Each's
                default => $keys === null ? $i : $keys[$i],
            };
        };
        // The page's large title the list's first row, as a SwiftUI List's in a NavigationStack on a phone, scrolling with the
        // rows; a list that chooses or reorders its rows keeps the bar's title, its rows' indices the state's.
        $top = !$this->selects && $this->move === null && $env->stack?->largeTitle($c, $env, $state) !== null ? 1 : 0;
        $title = $top === 1 ? Localized::string($env->title ?? '', $env) : '';
        $inset = !$this->selects && $this->move === null ? $c->takeBottomInset() : 0.0; // a bar floating over the page's bottom: a last row of its height, the rows scrolling under it
        $foot = $inset > 0 ? 1 : 0;
        $itemId = $idOf;
        if ($top + $foot > 0) { $idOf = static fn(int $i): int|string|null => match (true) { $i < $top => "\0largeTitle", $i - $top >= $count => "\0bottomInset", default => $itemId($i - $top) }; }
        if ($this->selects) { self::bind($state, $count, $idOf, $this->selection); }
        if ($this->move !== null) { $state->reorder = $this->move; $state->key = static fn(int $i) => $idOf($i); } // the choice following the rows dragged by their ids
        if ($env->scrollProxy !== null) { $env->scrollProxy->list = self::scroller($state, $count + $top + $foot, $idOf); }
        $row = $this->row;
        $menu = $this->menu;
        $rowEnv = $env->along(Node::COLUMN)->with(['inList' => true]);
        $n = $c->list($state, $count + $top + $foot, function (int $i) use ($c, $env, $rowEnv, $items, $keys, $row, $idOf, $itemId, $state, $menu, $top, $title, $count, $inset): void {
            if ($i < $top) { $c->largeTitle($title); return; }
            if ($i - $top >= $count) { $c->box(0, $inset, 0)->color = null; return; }
            $at = $i - $top; // the item's index; $i the state's row
            $v = $row($items === null ? $at : $items[$at], $keys === null ? $at : $keys[$at]);
            if ($v === null) { return; }
            if ($v instanceof View) { $v->identity ??= $items === null && !$this->id instanceof Closure ? $at : $itemId($at); }
            $r = self::row($c, $rowEnv, $v, $env->listStyle !== \ListStyle::plain || ($c->runtime?->touch ?? false)); // separated, but in a Mac's plain list
            if ($r === null) { return; }
            if ($env->alternatingRows && $at & 1) { $r->background($env->theme->surface); }
            if ($menu !== null) {
                $r->contextMenu(static function (\MyPHP\UI\Menu $m) use ($menu, $state, $i, $idOf, $rowEnv): void {
                    $ids = $state->chosen($i) ? self::chosenIds($state, $idOf) : [$idOf($i)];
                    Menu::items($m, (array)$menu($ids), $rowEnv);
                });
            }
        });
        if ($this->selects) { self::chose($state, $idOf, $this->selection, $this->primaryAction); $this->commitBinding(); }
        $n->alignSelf(Node::STRETCH);
        $n->grow = max($n->grow, 1); // the room it is given, along the stack it is in and across it
        return $n;
    }

    /** What a ScrollViewProxy's scrollTo($id, $anchor) does to the rows of $state. @internal */
    public static function scroller(ListState $state, int $count, Closure $idOf): Closure
    {
        return static function (int|string $id, ?\UnitPoint $anchor) use ($state, $count, $idOf): void {
            for ($i = 0; $i < $count && $idOf($i) !== $id; $i++);
            if ($i === $count) { throw new \OutOfRangeException("scrollTo(): no row of id $id"); }
            $anchor === null ? $state->scrollIntoView($i) : $state->scrollTo($i, true, match ($anchor) { \UnitPoint::top => Node::START, \UnitPoint::center => Node::CENTER, \UnitPoint::bottom => Node::END });
        };
    }

    /** Makes $state's choice the bound $selection, a single id or, an array, several. @internal @param Closure(int): (int|string|null) $idOf */
    public static function bind(ListState $state, int $count, Closure $idOf, mixed &$selection): void
    {
        $several = is_array($selection);
        if ($state->selected === null) { $state->selected = -1; } // rows can be chosen: click, Up/Down; double click or Return is the primary action
        if ($several && $state->selection === null) { $state->selection = new Selection(); }
        $state->key = $idOf; // the selection keeps the rows' ids
        if ($state->changed()) { // chosen by the keys since the last build, before it: the user's choice, not the app's old one to put back
            $selection = $several ? self::chosenIds($state, $idOf) : ($state->selected >= 0 && $state->selected < $count ? $idOf($state->selected) : null);
            return;
        }
        if ($several) {
            $want = array_keys(array_flip($selection)); // as the selection keeps them, keys of an array
            $have = $state->selection->all();
            sort($want); sort($have);
            if ($want !== $have) { // set by the app
                $state->selection->clear();
                foreach ($want as $id) { $state->selection->add($id); }
            }
            return;
        }
        $at = $state->selected >= 0 && $state->selected < $count ? $idOf($state->selected) : null;
        if ($at !== $selection) { // set by the app: its row
            for ($i = 0; $i < $count && $idOf($i) !== $selection; $i++);
            $state->selected = $i < $count ? $i : -1;
        }
    }

    /** After the rows are built: the user's choice into $selection, and $primaryAction run on a double click or Return. @internal */
    public static function chose(ListState $state, Closure $idOf, mixed &$selection, ?Closure $primaryAction): void
    {
        if ($state->changed()) { $selection = is_array($selection) ? self::chosenIds($state, $idOf) : ($state->selected >= 0 ? $idOf($state->selected) : null); }
        if ($state->submitted() && $primaryAction !== null) { $primaryAction(self::chosenIds($state, $idOf)); }
    }

    /** The ids of the rows chosen. @return list<int|string> */
    private static function chosenIds(ListState $state, Closure $idOf): array
    {
        if ($state->selection !== null) { return $state->selection->all(); }
        return $state->selected !== null && $state->selected >= 0 ? [$idOf($state->selected)] : [];
    }

    /**
     * A row of a list holding $v: a NavigationLink a cell with a chevron, a
     * Button a cell in the tint, any other view in the padding of a cell;
     * with $separator a hairline under it from 16 in. @internal
     */
    public static function row(Context $c, Env $env, View|array $v, bool $separator = false): ?Node
    {
        if ($v instanceof NavigationLink || $v instanceof Section || $v instanceof LabeledContent) { // cells of their own
            $n = $v->render($c, $env);
        } elseif ($v instanceof Button && $v->label === null) {
            $n = $c->cell(Localized::string($v->title, $env));
            if ($n !== null && $c->clicked($n)) { $v->run(); }
            if ($v->role !== \ButtonRole::destructive) { $n->kids[0]?->textColor($env->tint?->rgb($env) ?? $env->theme->accent); } else { $n->kids[0]?->textColor($env->theme->danger); }
        } elseif ($env->listStyle === \ListStyle::plain && !($c->runtime?->touch ?? false)) { // a macOS list's row: its content's height, rounded (a phone's plain list's rows are UIKit's cells, as a grouped one's)
            $n = $c->row(0, 8)->alignSelf(Node::STRETCH)->padding(6, 10)->radius(8)->alignItems(Node::CENTER);
              View::renderAll($c, $env->along(Node::ROW)->with(['inList' => false]), is_array($v) ? $v : [$v]);
            $c->end();
        } else {
            $n = $c->row(0, 8)->alignSelf(Node::STRETCH)->minHeight($env->theme->rowHeight)->padding(11, 16)->alignItems(Node::CENTER);
              View::renderAll($c, $env->along(Node::ROW)->with(['inList' => false, 'formRow' => true]), is_array($v) ? $v : [$v]);
              if ($v instanceof View && $v->badgeValue !== null) { $c->spacer(); $c->text((string)$v->badgeValue, color: $env->theme->textMuted); }
            $c->end();
            foreach ($n->kids as $k) { // a switch (31 high) or segments (32) centered in a row 44 high, as UIKit's cells, not padded 11 as a line of text
                if ($k->kind === Node::SWITCH || $k->role === \MyPHP\UI\Role::RADIO_GROUP) { $n->padding(6, 16); break; }
            }
        }
        if ($separator && $n !== null) { // UIKit's separator: a hairline under the row from 16 in to its end
            $n->drawOver(static function (\MyPHP\UI\Painter $p, array $r): void { $hair = 1 / max($p->scale(), 1.0); $p->fill([$r[0] + 16, $r[1] + $r[3] - $hair, $r[2] - 16, $hair], $p->theme()->border); }); // one pixel
        }
        return $n;
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
