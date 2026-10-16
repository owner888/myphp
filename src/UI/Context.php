<?php
declare(strict_types=1);

namespace MyPHP\UI;

use Closure;
use MyPHP\Menu;
use MyPHP\MenuItem;
use MyPHP\Window;

/** Builds a frame's tree. The view function calls these in order, as code. */
final class Context
{
    public Node $root;
    public int $count = 0;
    /** @var Node[] */
    private array $stack;
    /** Identity for the next node, from key(); and the key path of the innermost keyed container with its node count. */
    private string|int|null $nextKey = null;
    private string $scope = '';
    private int $ordinal = 0;
    /** @var list<array{0: string, 1: int}|null> per open container: the scope it replaced, or null */
    private array $scopes = [null];
    /** The sidebar being built, innermost last: its node, the chosen id (by reference), the items built so far. */
    private array $sidebars = [];
    /** How many toolbars enclose what is being built: their buttons take no face until hovered. */
    private int $toolbars = 0;
    /** The tree being built, innermost last: its local state (last frame's item ids and their parents), this frame's, the open items enclosing the one being built. */
    private array $trees = [];

    /** $width and $height are the room the tree will be laid out in. */
    public function __construct(private TextMetrics $metrics, public ?Runtime $runtime = null, public float $width = 0, public float $height = 0, public ?Theme $theme = null, public float $scale = 1.0)
    {
        $this->theme ??= Theme::light();
        $this->root = new Node(Node::CONTAINER, Node::COLUMN, 0, 0, 0, 0, 0, null, null);
        $this->stack = [$this->root];
    }

    private function add(Node $n): Node
    {
        $this->stack[count($this->stack) - 1]->kids[] = $n;
        if ($this->nextKey !== null) {
            $n->id = self::hash($this->scope . '/' . $this->nextKey);
        } elseif ($this->scope !== '') {
            $n->id = self::hash($this->scope . '#' . ++$this->ordinal);
        } else {
            $n->id = ++$this->count;
        }
        return $n;
    }

    /**
     * Gives the next node an identity that holds across frames whatever
     * is built before it, so conditional UI keeps its clicks, hover and
     * focus straight; the nodes inside a keyed container are keyed by
     * their order within it. A list keys each row by its index.
     */
    public function key(string|int $key): static
    {
        $this->nextKey = $key;
        return $this;
    }

    private static function hash(string $path): int
    {
        return unpack('q', hash('xxh64', $path, true))[1];
    }

    private function open(Node $n): Node
    {
        $this->add($n);
        $this->stack[] = $n;
        if ($this->nextKey !== null) {
            $this->scopes[] = [$this->scope, $this->ordinal];
            $this->scope .= '/' . $this->nextKey;
            $this->ordinal = 0;
            $this->nextKey = null;
        } else {
            $this->scopes[] = null;
        }
        return $n;
    }

    /** Whether the node just built was clicked (a mouse-up on where it was last frame). */
    public function clicked(Node $n): bool
    {
        return $this->runtime?->clicked($n->id) ?? false;
    }

    /** Whether a double click's second press landed on $n this frame (its last-frame rect); the click reports too. */
    public function doubleClicked(Node $n): bool
    {
        return $this->runtime?->doubleClicked($n->id) ?? false;
    }

    public function rightClicked(Node $n): bool
    {
        return $this->runtime?->rightClicked($n->id) ?? false;
    }

    public function hovered(Node $n): bool
    {
        return $this->runtime?->hovered($n->id) ?? false;
    }

    public function column(float $pad = 0, float $gap = 0, int $grow = 0, ?int $color = null): Node
    {
        return $this->open(new Node(Node::CONTAINER, Node::COLUMN, $pad, $gap, 0, 0, $grow, null, $color));
    }

    public function row(float $pad = 0, float $gap = 0, int $grow = 0, ?int $color = null): Node
    {
        return $this->open(new Node(Node::CONTAINER, Node::ROW, $pad, $gap, 0, 0, $grow, null, $color));
    }

    public function end(): void
    {
        array_pop($this->stack);
        $saved = array_pop($this->scopes);
        if ($saved !== null) {
            [$this->scope, $this->ordinal] = $saved;
        }
    }

    /**
     * A virtualized list of $count rows: $row($i) builds row $i, and only
     * the rows in view are built, from the anchor $state keeps. Row heights
     * come from what layout measured last frame, so the list builds rows
     * for 1.5 views' worth of estimated height, and asks for another frame
     * if that fell short.
     */
    public function list(ListState $state, int $count, Closure $row): Node
    {
        if ($state->followEnd && $state->atEnd && $count > $state->count && !$state->moving()) {
            $state->count = $count;
            $state->end(); // rows came: stay at the end
        }
        $state->count = $count;
        $state->row = min($state->row, max(0, $count - 1));
        // The place may have been set before the view's size was known (end() at
        // startup, say) or the rows changed under it: keep it within the rows.
        $state->scrollBy(0);
        $node = $this->column(0, 0, 1)->scroll($state);
        $node->focused = $state->selected !== null && ($this->runtime?->focused($node->id) ?? false);
        $state->first = $state->row;
        $y = -$state->offset;
        $limit = max($state->viewport, $this->height) * 1.5;
        [$scope, $ordinal] = [$this->scope, $this->ordinal];
        for ($i = $state->row; $i < $count && $y < $limit; $i++) {
            $this->scope = "$scope/L{$node->id}/$i"; // the row's nodes are keyed by its index
            $this->ordinal = 0;
            $k = count($node->kids);
            $row($i);
            $y += $state->height($i);
            if ($state->selected === null || !isset($node->kids[$k])) {
                continue;
            }
            $first = $node->kids[$k];
            $first->selected = $i === $state->selected;
            if ($this->runtime !== null && $this->runtime->doubleClicked($first->id)) {
                $state->choose($i);
                $state->submit();
            } elseif ($this->runtime !== null && $this->runtime->clicked($first->id)) {
                $state->choose($i);
            }
        }
        [$this->scope, $this->ordinal] = [$scope, $ordinal];
        $this->end();
        return $node;
    }

    /** Text in the theme's text color, or $color; $style is Font bits (BOLD, ITALIC, MONO). */
    public function text(string $s, ?float $size = null, ?int $color = null, int $style = 0): Node
    {
        $size ??= $this->theme->fontSize;
        [$w, $h] = $this->metrics->measure($s, $size, $style);
        $n = $this->add(new Node(Node::TEXT, 0, 0, 0, $w, $h, 0, $s, null, $size, $color ?? $this->theme->text));
        $n->style = $style;
        return $n;
    }

    /**
     * Spans that differ in style over $size and $color (the theme's by
     * default), baselines aligned per line, as mygo's RichText. With a
     * $width the text wraps there (at spaces, between CJK characters, by
     * character when a word is wider) and at newlines; with $wrap it
     * wraps at the width its container had last frame, inside its padding
     * (the window's width the first time, and a frame follows when it differs);
     * otherwise it is one line. The node is as wide as its widest line.
     * @param Span[] $spans
     */
    public function rich(array $spans, ?float $size = null, ?int $color = null, ?float $width = null, bool $wrap = false): Node
    {
        $size ??= $this->theme->fontSize;
        $n = $this->add(new Node(Node::RICH, 0, 0, 0, 0, 0, 0, null, null, $size, $color ?? $this->theme->text));
        if ($wrap && $width === null) { // the room its container had last frame, inside the padding
            $parent = $this->stack[count($this->stack) - 1];
            $pw = $this->runtime?->widthOf($parent->id);
            $width = $pw === null ? $this->width : max(1.0, $pw - 2 * $parent->padX());
            $n->wrapWidth = $width;
        }
        $layout = RichLayout::wrap($this->metrics, $spans, $size, $width ?? INF);
        $n->fw = $layout->w; $n->fh = $layout->h;
        $n->spans = $spans;
        $n->rich = $layout;
        return $n;
    }

    /** Whether the node just built has the keyboard focus (buttons, fields, lists and nodes made focusable()). */
    public function focused(Node $n): bool
    {
        return $this->runtime?->focused($n->id) ?? false;
    }

    /** A link: accent text, underlined, a hand pointer; clicking opens $url with the system (App::openURL). */
    public function link(string $s, string $url, ?float $size = null): Node
    {
        $n = $this->text($s, $size, $this->theme->accent);
        $n->underline = $this->hovered($n); // underlined under the pointer, as mygo's
        $n->url = $url;
        if ($this->runtime?->clicked($n->id)) {
            ($this->runtime->openURL)($url);
        }
        return $n;
    }

    /** A progress bar: $fraction 0..1 of its width filled; below 0 it is indeterminate and animates. */
    public function progress(float $fraction, float $width = 160): Node
    {
        $n = $this->add(new Node(Node::PROGRESS, 0, 0, 0, $width, $this->theme->space(1.5), 0, null, null));
        $n->fraction = $fraction;
        return $n;
    }

    /**
     * A bar showing $value between $lo and $hi, as mygo's Meter (AppKit's
     * level indicator): the success color, or warning from $warning and
     * danger from $critical (null for neither); a $critical below $warning
     * makes low values the bad ones, as a battery's.
     */
    public function meter(float $value, float $lo, float $hi, ?float $warning = null, ?float $critical = null, float $width = 160): Node
    {
        $t = $this->theme;
        $n = $this->add(new Node(Node::PROGRESS, 0, 0, 0, $width, $t->space(2), 0, null, $t->success));
        $n->fraction = $hi > $lo ? max(0.0, min(1.0, ($value - $lo) / ($hi - $lo))) : 0.0;
        if ($warning !== null && $critical !== null && $warning !== $critical) {
            $low = $critical < $warning;
            if ($low ? $value <= $critical : $value >= $critical) { $n->color = $t->danger; }
            elseif ($low ? $value <= $warning : $value >= $warning) { $n->color = $t->warning; }
        }
        return $n;
    }

    /**
     * A field holding a number between $lo and $hi, as mygo's NumberInput:
     * the arrows Up and Down, and the − and + beside it, step it by $step;
     * what is typed applies as soon as it is a number in range, and shows
     * rounded to the decimals of $step once the field loses the focus.
     * changed() reports a new value.
     */
    public function numberInput(int|float &$value, float $lo, float $hi, float $step = 1): Node
    {
        $t = $this->theme;
        $rt = $this->runtime;
        $decimals = ($s = rtrim(rtrim(sprintf('%.10F', $step), '0'), '.')) !== '' && str_contains($s, '.') ? strlen($s) - strpos($s, '.') - 1 : 0;
        $format = fn(float $v) => number_format($v, $decimals, '.', '');
        $n = $this->row(0, $t->space(1))->align(Node::CENTER);
        $text = $rt?->local($n->id, fn() => new TextState($format($value))) ?? new TextState($format($value));
        $set = function (float $v) use (&$value, $lo, $hi, $decimals, $n) {
            $v = round(max($lo, min($hi, $v)), $decimals);
            if ($v != $value) { $value = is_int($value) ? (int)$v : $v; $n->changed = true; }
        };
          $in = $this->input($text, '', $t->space(20));
          if ($rt !== null && $rt->focus === $in->id) {
              if (is_numeric(trim($text->text)) && (float)$text->text >= $lo && (float)$text->text <= $hi) { $set((float)$text->text); }
              if ($this->shortcut(Key::UP)) { $set($value + $step); $text->text = $format($value); $text->setCursor(strlen($text->text)); }
              if ($this->shortcut(Key::DOWN)) { $set($value - $step); $text->text = $format($value); $text->setCursor(strlen($text->text)); }
          } elseif ($text->text !== $format($value)) {
              $text->text = $format($value); $text->setCursor(strlen($text->text)); // what the app set, or what was typed, shown in full
          }
          $minus = $this->button('−'); $plus = $this->button('+');
        $this->end();
        if ($this->clicked($minus) && $value > $lo) { $set($value - $step); }
        if ($this->clicked($plus) && $value < $hi) { $set($value + $step); }
        return $n;
    }

    /** Secondary text, in the theme's muted color. */
    public function muted(string $s, ?float $size = null): Node
    {
        return $this->text($s, $size, $this->theme->textMuted);
    }

    /** A primary button: accent face, lighter hovered, darker pressed. */
    public function button(string $s, ?float $size = null): Node
    {
        return $this->makeButton($s, $size, false);
    }

    /** A button in the accent color, for the main action (mygo's PrimaryButton). */
    public function primaryButton(string $s, ?float $size = null): Node
    {
        return $this->makeButton($s, $size, true);
    }

    /**
     * As mygo's styleButton: padding Space(1.5) by Space(3.5), a bordered
     * surface face, or the accent without a border when primary, or in a
     * toolbar no face until hovered (padding Space(1.5) by Space(2.5));
     * hover and press shade it, and $on (a toggle) keeps it pressed.
     */
    private function makeButton(string $s, ?float $size, bool $primary, bool $on = false): Node
    {
        $t = $this->theme;
        $size ??= $t->fontSize;
        [$w, $h] = $this->metrics->measure($s, $size);
        $toolbar = $this->toolbars > 0 && !$primary;
        [$base, $hover, $pressed, $fg] = $primary ? [$t->accent, $t->accentHover, $t->accentPressed, $t->accentText] : [$toolbar ? null : $t->surface, $t->surfaceHover, $t->surfacePressed, $t->text];
        $n = $this->add(new Node(Node::BUTTON, 0, 0, 0, $w + $t->space($toolbar ? 5 : 7), $h + $t->space(3), 0, $s, $on ? $pressed : $base, $size, $fg));
        $n->bordered = !$primary && !$toolbar;
        $n->focused = $this->runtime?->focused($n->id) ?? false;
        if (!$on && $this->runtime?->hovered($n->id)) {
            $n->color = $this->runtime->input->mouseDown ? $pressed : $hover;
        }
        return $n;
    }

    /** A button that stays pressed while $on, as Bold in an editor's toolbar (mygo's Toggle): a click presses it or lets it go, as does Space while it has the focus; changed() reports it. */
    public function toggle(bool &$on, string $label, ?float $size = null): Node
    {
        $n = $this->makeButton($label, $size, false, $on);
        if ($this->clicked($n)) {
            $on = !$on;
            $n->changed = true;
        }
        return $n;
    }

    /**
     * A row of controls across the top of a window, as mygo's Toolbar:
     * buttons, toggles, segmented controls and menu buttons built by $fn,
     * which take no face of their own until hovered.
     */
    public function toolbar(Closure $fn): Node
    {
        $t = $this->theme;
        $rt = $this->runtime;
        $n = $this->row($t->space(1), $t->space(1))->align(Node::CENTER)->focusGroup(Node::HORIZONTAL); // one stop of Tab; Left and Right move inside
        $this->toolbars++;
          $fn();
          // Controls that do not fit go, from the last, into a menu at the end (mygo's layoutToolbar): the room is the toolbar's last-frame width.
          $cw = ($rt?->rectOf($n->id)[2] ?? INF) - 2 * $t->space(1);
          $items = $n->kids; $gap = $t->space(1); $moreW = $t->space(3) + 2 * $t->space(2.5);
          $total = 0.0;
          foreach ($items as $i => $k) { Layout::measure($k); $total += $k->mw + ($i > 0 ? $gap : 0); }
          $fits = count($items);
          if ($total > $cw + 0.5) {
              $room = $cw - $moreW - $gap; $used = 0.0;
              for ($fits = 0; $fits < count($items); $fits++) {
                  $w = $items[$fits]->mw + ($fits > 0 ? $gap : 0);
                  if ($used + $w > $room + 0.5) { break; }
                  $used += $w;
              }
          }
          if ($fits < count($items)) {
              $n->kids = array_slice($items, 0, $fits);
              $overflow = array_slice($items, $fits);
              $more = $this->key('more')->column(0, 0)->padding($t->space(1.5), $t->space(2.5))->round($t->radius)->focusable()->center()
                  ->draw(function (DisplayList $list, Node $b, Theme $t) { // mygo's chevrons: »
                      $d = $t->space(3); $x = $b->x + ($b->w - $d) / 2; $y = $b->y + ($b->h - $d) / 2;
                      foreach ([0.2, 0.5] as $k) {
                          $list->line($x + $d * $k, $y + $d * 0.25, $x + $d * ($k + 0.25), $y + $d * 0.5, $t->text, 1.0, 1.5);
                          $list->line($x + $d * ($k + 0.25), $y + $d * 0.5, $x + $d * $k, $y + $d * 0.75, $t->text, 1.0, 1.5);
                      }
                  });
              $more->size($moreW, $t->space(3) + 2 * $t->space(1.5));
              if ($this->hovered($more)) { $more->color = $t->surfaceHover; }
              $this->end();
              if ($rt !== null && ($this->clicked($more) || ($more->id === $rt->focus && $this->shortcut(Key::DOWN)))) {
                  $window = Window::key();
                  $menu = [];
                  foreach ($overflow as $k) { // choosing an item does what clicking the control does; a divider is a separator
                      $label = $k->text ?? self::labelOf($k);
                      if ($label === null) { $menu[] = MenuItem::separator(); continue; }
                      $id = $k->id;
                      $menu[] = new MenuItem($label, function (MenuItem $it, ?Window $w) use ($rt, $id) { $rt->press($id); $w?->update(); });
                  }
                  if ($window !== null) { (new Menu($menu))->popupAt($window, $more->id, $rt); }
              }
          }
        $this->toolbars--;
        $this->end();
        return $n;
    }

    /** The first text inside a composite control, for naming it in a menu. */
    private static function labelOf(Node $n): ?string
    {
        foreach ($n->kids as $k) {
            if ($k->text !== null && $k->text !== '') { return $k->text; }
            if (($l = self::labelOf($k)) !== null) { return $l; }
        }
        return null;
    }

    /** Whether $key (a Key code or a character) was pressed this frame with exactly $mods, unless a focused control took it (mygo's Shortcut). */
    public function shortcut(int|string $key, int $mods = 0): bool
    {
        return $this->runtime?->shortcut($key, $mods) ?? false;
    }

    /** Whether the control just built changed its value this frame (checkbox, radio, slider, select), as mygo's Changed. */
    public function changed(Node $n): bool
    {
        return $n->changed;
    }

    /** A checkbox with a label, toggling $checked; changed() reports it. */
    public function checkbox(string $label, bool &$checked, ?float $size = null): Node
    {
        $t = $this->theme;
        $size ??= $t->fontSize;
        [$w, $h] = $this->metrics->measure($label, $size);
        $n = $this->add(new Node(Node::CHECKBOX, 0, 0, 0, $w + $t->space(6), max($h, $t->space(4)), 0, $label, null, $size, $t->text));
        $n->focusable = true;
        if ($this->runtime?->clicked($n->id)) {
            $checked = !$checked;
            $n->changed = true;
        }
        $n->on = $checked;
        return $n;
    }

    /** A switch with a label, toggling $on, as mygo's Switch; changed() reports it. */
    public function switch(string $label, bool &$on, ?float $size = null): Node
    {
        $t = $this->theme;
        $size ??= $t->fontSize;
        [$w, $h] = $this->metrics->measure($label, $size);
        $n = $this->add(new Node(Node::SWITCH, 0, 0, 0, $w + $t->space(11), max($h, $t->space(5)), 0, $label, null, $size, $t->text)); // the track Space(9), the gap Space(2)
        $n->focusable = true;
        if ($this->runtime?->clicked($n->id)) {
            $on = !$on;
            $n->changed = true;
        }
        $n->on = $on;
        return $n;
    }

    /**
     * A tree of items $fn builds with treeItem, as mygo's Tree: while an
     * item has the keyboard focus, Up and Down move it to the items
     * around, Right opens the item or moves to its first child, and Left
     * closes it or moves to its parent.
     */
    public function tree(Closure $fn): Node
    {
        $n = $this->column(0, 0);
        $local = $this->runtime?->local($n->id, fn() => (object)['items' => [], 'parents' => []]);
        $this->trees[] = ['local' => $local, 'items' => [], 'parents' => [], 'open' => []];
          $fn();
        $built = array_pop($this->trees);
        $this->end();
        if ($local !== null) { $local->items = $built['items']; $local->parents = $built['parents']; }
        return $n;
    }

    /**
     * An item of a tree showing $label, inside the item it is built in, as
     * mygo's TreeItem: $children builds the items inside it, shown while
     * $open; null children or a null $open makes a leaf. A click on its
     * arrow opens or closes it; clicked() reports a click elsewhere on it,
     * or Enter, for choosing it, which $selected shows.
     */
    public function treeItem(string $label, ?bool &$open, ?Closure $children, bool $selected = false): Node
    {
        $t = $this->theme;
        $rt = $this->runtime;
        $tb = &$this->trees[count($this->trees) - 1];
        $depth = count($tb['open']);
        $branch = $children !== null && $open !== null;
        $n = $this->row(0, $t->space(0.5), 0, $selected ? $t->accent : null)->round($t->radius)->align(Node::CENTER)->focusable()->size(0, $t->space(7));
        $id = $n->id;
        $tb['items'][] = $id;
        $tb['parents'][$id] = $tb['open'][$depth - 1] ?? null;
        if (!$selected && $this->hovered($n)) { $n->color = $t->surfaceHover; }
        if ($rt !== null && $rt->focus === $id) { // the keys, among last frame's items
            $last = $tb['local']->items; $at = array_search($id, $last, true); $to = null;
            if ($this->shortcut(Key::DOWN) && $at !== false && $at + 1 < count($last)) { $to = $last[$at + 1]; }
            if ($this->shortcut(Key::UP) && $at !== false && $at > 0) { $to = $last[$at - 1]; }
            if ($this->shortcut(Key::RIGHT)) {
                if ($branch && !$open) { $open = true; } elseif ($branch && $at !== false && $at + 1 < count($last)) { $to = $last[$at + 1]; }
            }
            if ($this->shortcut(Key::LEFT)) {
                if ($branch && $open) { $open = false; } else { $to = $tb['local']->parents[$id] ?? null; }
            }
            if ($to !== null) { $rt->focus = $to; }
        }
        $fg = $selected ? $t->accentText : $t->text;
          $this->column(0, 0)->size($t->space(1 + 4 * $depth), 0); $this->end(); // the indent
          $arrow = $this->column(0, 0)->size($t->space(4), $t->space(4))->center();
            if ($branch) { $this->text($open ? '▾' : '▸', $t->fontSize - 1, $selected ? $t->accentText : $t->textMuted); }
          $this->end();
          $this->text($label, $t->fontSize, $fg)->single();
          $this->column(0, 0)->size($t->space(2), 0); $this->end();
        $this->end();
        if ($branch && $this->clicked($arrow)) { $open = !$open; }
        if ($branch && $open) {
            $tb['open'][] = $id;
            $children();
            array_pop($tb['open']);
        }
        return $n;
    }

    /**
     * A sidebar of items in sections, as mygo's Sidebar (Finder's,
     * Mail's), $width wide, scrolling when they do not fit: a click
     * chooses an item, whose id $selected holds, and while the sidebar has
     * the focus Up, Down, Home and End choose among its items; it is one
     * stop of Tab, and changed() reports a new choice.
     */
    public function sidebar(string &$selected, Closure $fn, float $width = 200): Node
    {
        $t = $this->theme;
        $rt = $this->runtime;
        $n = $this->column($t->space(2.5), $t->space(0.5), 0, $t->surface)->size($width)->focusable();
        if ($rt !== null) {
            $local = $rt->local($n->id, fn() => (object)['scroll' => new ScrollState, 'items' => []]);
            $n->scroll($local->scroll);
            // The keys choose among last frame's items, in their order.
            $items = $local->items; $at = array_search($selected, $items, true); $to = null;
            if ($rt->focus === $n->id && $items !== []) {
                if ($this->shortcut(Key::DOWN)) { $to = $at === false ? 0 : min($at + 1, count($items) - 1); }
                if ($this->shortcut(Key::UP)) { $to = $at === false ? 0 : max($at - 1, 0); }
                if ($this->shortcut(Key::HOME)) { $to = 0; }
                if ($this->shortcut(Key::END)) { $to = count($items) - 1; }
            }
            if ($to !== null && $items[$to] !== $selected) { $selected = $items[$to]; $n->changed = true; }
        }
        $this->sidebars[] = ['node' => $n, 'selected' => &$selected, 'items' => []];
          $fn();
        $built = array_pop($this->sidebars);
        $this->end();
        if (isset($local)) { $local->items = $built['items']; }
        return $n;
    }

    /**
     * A section of a sidebar titled $title, as mygo's SidebarSection: the
     * items $fn builds while $open (null keeps it open); a click on the
     * title shows and hides them, its arrow showing as the pointer rests
     * on it.
     */
    public function sidebarSection(string $title, ?bool &$open, Closure $fn): Node
    {
        $t = $this->theme;
        $parent = $this->stack[count($this->stack) - 1];
        $n = $this->column(0, $t->space(0.5));
          if (count($parent->kids) > 1) { $this->column(0, 0)->size(0, $t->space(2.5)); $this->end(); } // apart from the section above
          $head = $this->row(0, 0)->padding($t->space(1), $t->space(2))->round($t->radius)->align(Node::CENTER);
            $this->text($title, $t->fontSize - 1, $t->textMuted, Font::BOLD);
            $this->spacer();
            if ($open !== null) { // the disclosure arrow, shown as the pointer rests on the title
                $hovered = $this->hovered($head); $shown = $open;
                $a = $this->box($t->space(4), $t->space(4), 0); $a->alpha = 0.0;
                if ($hovered) { $a->draw(fn(DisplayList $list, Node $a, Theme $t) => self::disclosure($list, $a, $shown ? 90 : 0, $t->textMuted)); }
            }
          $this->end();
          if ($open !== null && $this->clicked($head)) { $open = !$open; }
          if ($open === null || $open) { $fn(); }
        $this->end();
        return $n;
    }

    /**
     * An item of a sidebar choosing $id, showing $label after $icon (a
     * glyph, or null), as mygo's SidebarItem; $trailing builds what sits at
     * its end, a badge say. Chosen, it is the accent while the sidebar has
     * the focus and gray otherwise, as AppKit's.
     */
    public function sidebarItem(string $id, string $label, ?string $icon = null, ?Closure $trailing = null): Node
    {
        $t = $this->theme;
        $sb = &$this->sidebars[count($this->sidebars) - 1];
        $chosen = $sb['selected'] === $id;
        $focused = $this->focused($sb['node']);
        $sb['items'][] = $id;
        $n = $this->key("item:$id")->row(0, $t->space(2), 0, $chosen ? ($focused ? $t->accent : $t->surfacePressed) : null)->padding($t->space(1.5), $t->space(2))->round($t->radius)->align(Node::CENTER);
        if (!$chosen && $this->hovered($n)) { $n->color = $t->surfaceHover; }
        $fg = $chosen && $focused ? $t->accentText : $t->text;
          if ($icon !== null) { $this->text($icon, 16, $chosen && $focused ? $t->accentText : $t->accent); }
          $this->text($label, $t->fontSize, $fg)->single();
          if ($trailing !== null) { $this->spacer(); $trailing(); }
        $this->end();
        if ($this->clicked($n) && $sb['selected'] !== $id) {
            $sb['selected'] = $id;
            $sb['node']->changed = true;
            if ($this->runtime !== null) { $this->runtime->focus = $sb['node']->id; } // a click chooses and focuses the sidebar, as a list's row does
        }
        return $n;
    }

    /**
     * A panel built by $fn below $anchor (a node built this frame) while
     * $open, as mygo's Popover: a press outside the panel and the anchor,
     * or Escape, sets $open false, which changed() reports; the press goes
     * on to what is under it. Where there is no room below, the panel
     * shows above. It is built over everything else, at the window's root.
     */
    public function popover(Node $anchor, bool &$open, Closure $fn): ?Node
    {
        if (!$open) {
            return null;
        }
        $t = $this->theme;
        $rt = $this->runtime;
        $r = $rt?->rectOf($anchor->id) ?? [0, 0, 0, 0];
        $margin = $t->space(1);
        $saved = $this->stack; $this->stack = [$this->root]; // at the root: over the view, placed in the window
        $panel = $this->column($t->space(1), 0, 0, $t->background)->round($t->radius + 2)->border()->shadow(6, 20, 0.18)->at($r[0], $r[1] + $r[3] + $margin);
          $fn();
        $this->end();
        $this->stack = $saved;
        $last = $rt?->rectOf($panel->id);
        if ($last !== null && $r[1] + $r[3] + $margin + $last[3] > $this->height && $r[1] - $margin - $last[3] >= 0) {
            $panel->at($r[0], $r[1] - $margin - $last[3]); // no room below: above, as high as it was last frame
        }
        if ($rt !== null && ($this->shortcut(Key::ESCAPE) || ($rt->pressedAnywhere() && !$rt->pressed($panel->id) && !$rt->pressed($anchor->id)))) {
            $open = false;
            $panel->changed = true;
        }
        return $panel;
    }

    /**
     * A plain button with a chevron that drops a menu of $items below it
     * when pressed, or for Enter, Space or Down while it has the focus, as
     * mygo's MenuButton. $items: label => Closure, each run when chosen.
     * @param array<string, Closure> $items
     */
    public function menuButton(string $label, array $items, ?Window $window = null): Node
    {
        $n = $this->button($label);
        $n->fw += $this->theme->space(1.5) + $this->theme->space(2.5); // room for the chevron
        $n->chevron = true;
        $rt = $this->runtime;
        if ($rt !== null && ($this->clicked($n) || ($n->id === $rt->focus && $this->shortcut(Key::DOWN)))) {
            $window ??= Window::key();
            $menu = [];
            foreach ($items as $text => $fn) {
                $menu[] = new MenuItem($text, function (MenuItem $it, ?Window $w) use ($fn) { $fn(); $w?->update(); });
            }
            if ($window !== null) {
                (new Menu($menu))->popupAt($window, $n->id, $rt);
            }
        }
        return $n;
    }

    /**
     * A field for a search, as mygo's SearchField: a glass at its left,
     * "Search" while empty, and a button clearing it at its right while it
     * holds any, as Escape does while it has the focus. changed() reports
     * a change of its text, $state->submitted Enter. A $width of 0 fills
     * what the row has left.
     */
    public function searchField(TextState $state, float $width = 200, ?float $size = null): Node
    {
        $t = $this->theme;
        $size ??= $t->fontSize;
        // mygo's field(): padding Space(1.5) by Space(2), the glass, the input and the clear button Space(1.5) apart.
        $glass = $t->space(3.75); $clear = $t->space(4); $pad = $t->space(2); $gap = $t->space(1.5);
        $parent = $this->stack[count($this->stack) - 1];
        $this->row(0, 0, $width <= 0 && $parent->dir === Node::ROW ? 1 : 0); // 0: fills what the parent has across (a column stretches it; a row needs it to grow)
          $n = $this->input($state, 'Search', $width, $size)->inset($pad + $glass + $gap - Runtime::FIELD_INSET, $state->text === '' ? $pad - Runtime::FIELD_INSET : $pad + $clear + $gap - Runtime::FIELD_INSET);
          $w = $width > 0 ? $width : ($this->runtime?->rectOf($n->id)[2] ?? 0.0); // as laid out last frame, for the clear button's place
          // The magnifying glass, as mygo's (MingCute's search icon): a ring and a handle, stroked in the muted color.
          $this->box($glass, $glass, 0)->at($pad, ($n->fh - $glass) / 2)->draw(function (DisplayList $list, Node $g, Theme $t) {
              $k = $g->w / 24; $stroke = 2 * $k;
              $list->ring($g->x + 2 * $k, $g->y + 2 * $k, 12 * $k, 12 * $k, $t->textMuted, 1.0, 6 * $k, $stroke);
              $list->line($g->x + 14.5 * $k, $g->y + 14.5 * $k, $g->x + 20 * $k, $g->y + 20 * $k, $t->textMuted, 1.0, $stroke);
          })->alpha = 0.0;
          if ($state->text !== '') { // the button clearing it: a disc in the muted color at half, with a cross in the background's
              $x = $this->column(0, 0, 0, $t->textMuted)->round($clear / 2)->size($clear, $clear)->at($w - $pad - $clear, ($n->fh - $clear) / 2)->cursor('pointer')
                  ->draw(function (DisplayList $list, Node $b, Theme $t) {
                      $k = $b->w * 0.3;
                      $list->line($b->x + $k, $b->y + $k, $b->x + $b->w - $k, $b->y + $b->h - $k, $t->background, 1.0, 1.5);
                      $list->line($b->x + $b->w - $k, $b->y + $k, $b->x + $k, $b->y + $b->h - $k, $t->background, 1.0, 1.5);
                  });
              $x->alpha = 0.5;
              $this->end();
              if ($this->clicked($x) || ($this->runtime?->focus === $n->id && $this->shortcut(Key::ESCAPE))) {
                  $state->text = ''; $state->setCursor(0);
              }
          }
        $this->end();
        $n->changed = $state->seen !== null && $state->seen !== $state->text;
        $state->seen = $state->text;
        return $n;
    }

    /**
     * A dialog built by $fn over the dimmed window while $open, as mygo's
     * Modal: what is behind it is inert, Tab goes round what is inside;
     * a click outside the panel or Escape sets $open false, which
     * changed() reports.
     */
    public function modal(bool &$open, Closure $fn): ?Node
    {
        if (!$open) {
            return null;
        }
        $t = $this->theme;
        $back = $this->column(0, 0, 0, 0x000000)->at(0, 0)->size($this->width, $this->height)->center()->block();
        $back->alpha = 0.4;
          $panel = $this->column($t->space(5), $t->space(3), 0, $t->background)->round($t->space(2.5))->shadow(10, 30, 0.3);
            $fn();
          $this->end();
        $this->end();
        if ($this->shortcut(Key::ESCAPE) || ($this->clicked($back) && !$this->hovered($panel))) {
            $open = false;
            $back->changed = true;
        }
        return $back;
    }

    /** Shows $message near the bottom of the window for a few seconds, as mygo's Toast: the outcome of what the user just did. */
    public function toast(string $message): void
    {
        $this->runtime?->toast($message);
    }

    /** The toasts' viewport, built by Window after the view: the toasts showing, stacked at the bottom, their time stopped while the pointer rests on one. */
    public function toasts(): void
    {
        if ($this->runtime === null) {
            return;
        }
        $t = $this->theme;
        $paused = false;
        foreach ($this->runtime->toasts(true) as $i => $m) { // last frame's toasts, for the hover
            $paused = $paused || $this->runtime->hovered(self::hash($this->scope . "/toast/$i"));
        }
        $messages = $this->runtime->toasts($paused);
        if ($messages === []) {
            return;
        }
        $this->column($t->space(4), $t->space(2))->at(0, 0)->size($this->width, $this->height)->justify(Node::END)->align(Node::CENTER);
          foreach ($messages as $i => $m) {
              $this->key("toast/$i")->row($t->space(2.5), 0, 0, $t->surface)->round($t->space(2))->shadow(4, 12, 0.18);
                $this->text($m);
              $this->end();
          }
        $this->end();
    }

    /**
     * A table of $count rows under a header of $columns, as mygo's Table:
     * the rows are a list's (only those in view are built, $state keeps
     * the place and the choice, the keys move it), $cell($row, $col)
     * builds a cell's content, usually a text. Rows are as high as their
     * tallest cell and at least Space(8); cells pad Space(1.5) by Space(2.5). The user resizes a column by dragging the
     * right edge of its header ($state->columnWidths keeps it) and sorts
     * by a sortable column by clicking its header ($state->sort; the app
     * sorts its rows and sortChanged() reports a click).
     * @param list<TableColumn> $columns
     */
    public function table(ListState $state, array $columns, int $count, Closure $cell): Node
    {
        $t = $this->theme;
        $rowH = $t->space(8); $flexMin = $t->space(15);
        $rt = $this->runtime;
        $n = $this->column(0, 0, 1);
        $cells = function (Closure $fill) use ($columns, $state, $t, $flexMin) {
            $boxes = [];
            foreach ($columns as $j => $col) {
                $w = $state->columnWidths[$col->id()] ?? $col->width;
                $box = $this->key('c' . $col->id())->row(0, 0, $w > 0 ? 0 : 1)->padding($t->space(1.5), $t->space(2.5))->align(Node::CENTER)->justify($col->align);
                $box->size($w > 0 ? $w : max($col->minWidth, $flexMin), 0); // a shared column measures its least width and grows: its content does not push it (mygo's Shrink(1))
                  $fill($j);
                $this->end();
                $boxes[] = $box;
            }
            return $boxes;
        };
        // The header: a click sorts by a sortable column; a drag at a header's right edge resizes it.
        $head = $this->row(0, 0)->align(Node::STRETCH)->size(0, $rowH);
        $headRect = $rt?->rectOf($head->id);
        $heads = $cells(function (int $j) use ($columns, $state, $t) {
            $col = $columns[$j];
            $this->text($col->title, $t->fontSize, $t->textMuted, Font::BOLD);
            if ($state->sort !== null && $state->sort[0] === $col->id()) {
                $this->text($state->sort[1] ? '▾' : '▴', $t->fontSize - 2, $t->textMuted);
            }
        });
        foreach ($columns as $j => $col) {
            $h = $heads[$j];
            if ($col->sortable && $this->hovered($h)) { $h->color = $t->surfaceHover; }
            if ($col->sortable && $this->clicked($h)) { $state->sortBy($col->id()); }
            $r = $rt?->rectOf($h->id);
            if ($r === null || $headRect === null) {
                continue;
            }
            $grip = $this->key('g' . $col->id())->box(0, 0, 0)->at($r[0] - $headRect[0] + $r[2] - 3, 0)->size(6, $rowH)->cursor('resizeEW');
            $grip->alpha = 0.0;
            $d = $rt->dragged($grip->id);
            if ($d !== null) { // from the width it has (a shared column's as laid out last frame), by what the pointer moved
                $w = $state->columnWidths[$col->id()] ?? ($col->width > 0 ? $col->width : $r[2]);
                $state->columnWidths[$col->id()] = max($col->minWidth, $flexMin, $w + $d[0]);
            }
        }
        $this->end();
        $this->divider();
        $this->list($state, $count, function (int $i) use ($cells, $cell, $rowH, $t, $state) {
            $row = $this->row(0, 0)->align(Node::STRETCH)->min(0, $rowH); // as high as its tallest cell, at least the header
            if ($state->selected === null && $this->hovered($row)) { $row->color = $t->surfaceHover; } // chosen rows show the pointer already
              $cells(fn(int $j) => $cell($i, $j));
            $this->end();
        });
        $this->end();
        return $n;
    }

    /**
     * Two panes side by side (or $vertical: one above the other) with a
     * divider between them the user drags, or moves with the arrows once
     * it has the focus, as mygo's Split: $size is the first pane's width
     * (height), kept Space(10) from either edge; $first and $second build
     * the panes. changed() reports a move.
     */
    public function split(float &$size, Closure $first, Closure $second, bool $vertical = false): Node
    {
        $t = $this->theme;
        $minPane = $t->space(10); $grip = $t->space(1.5); $step = $t->space(2.5);
        $n = $vertical ? $this->column(0, 0, 1)->align(Node::STRETCH) : $this->row(0, 0, 1)->align(Node::STRETCH);
        $rt = $this->runtime;
        $r = $rt?->rectOf($n->id);
        $total = $r === null ? 0.0 : ($vertical ? $r[3] : $r[2]); // the room to share, as the last frame laid it out
        $set = function (float $v) use (&$size, $total, $minPane, $n) {
            if ($total > 0) { $v = min($v, $total - 1 - $minPane); }
            $v = max($v, $minPane);
            if ($v != $size) { $size = $v; $n->changed = true; }
        };
          $a = $this->column(0, 0);
          $vertical ? $a->size(0, $size) : $a->size($size, 0);
            $first();
          $this->end();
          $div = $this->box($vertical ? 0 : 1, $vertical ? 1 : 0, $t->border)->focusable(); // the line takes the focus between the panes
          $b = $this->column(0, 0, 1);
            $second();
          $this->end();
          // The handle the pointer drags spans a few points over either pane, above them.
          $handle = $this->box(0, 0, 0)->at($vertical ? 0 : $size - ($grip - 1) / 2, $vertical ? $size - ($grip - 1) / 2 : 0)
              ->size($vertical ? ($r[2] ?? 0) : $grip, $vertical ? $grip : ($r[3] ?? 0))->cursor($vertical ? 'resizeNS' : 'resizeEW');
          $handle->alpha = 0.0;
        $this->end();
        if ($rt !== null) {
            $held = $rt->dragged($handle->id);
            if ($held !== null) {
                $rt->focus = $div->id; // a press there focuses the line, as a press on it would
                $set($size + ($vertical ? $held[1] : $held[0]));
            }
            if ($rt->focus === $div->id) {
                if ($this->shortcut($vertical ? Key::UP : Key::LEFT)) { $set($size - $step); }
                if ($this->shortcut($vertical ? Key::DOWN : Key::RIGHT)) { $set($size + $step); }
            }
            if ($held !== null || $rt->hovered($handle->id)) { $div->color = $t->accent; }
        }
        return $n;
    }

    /** A line across the container: horizontal in a column, vertical in a row ($vertical). */
    public function divider(bool $vertical = false): Node
    {
        return $this->add(new Node(Node::BOX, 0, 0, 0, $vertical ? 1 : 0, $vertical ? 0 : 1, 0, null, $this->theme->border));
    }

    /** Empty room that grows to take what the container has left, pushing what follows to the end. */
    public function spacer(): Node
    {
        return $this->add(new Node(Node::CONTAINER, Node::ROW, 0, 0, 0, 0, 1, null, null));
    }

    /** A spinner showing activity of unknown length, as mygo's: spokes turning, as high as the font size, in the muted text color. */
    public function spinner(): Node
    {
        $size = $this->theme->fontSize * 1.25;
        return $this->add(new Node(Node::SPINNER, 0, 0, 0, $size, $size, 0, null, null));
    }

    /** A short text in a pill, as mygo's Badge: a count beside a sidebar's item, in the text color at a tenth. */
    public function badge(string $text): Node
    {
        $t = $this->theme;
        $size = $t->fontSize - 2;
        [$w, $h] = $this->metrics->measure($text, $size);
        $n = $this->column(0, 0, 0, $t->text)->round($h / 2)->size($w + $t->space(3), $h)->center(); // Space(1.5) a side
        $n->alpha = 0.1;
          $this->text($text, $size, $t->text);
        $this->end();
        return $n;
    }

    /**
     * Segments to choose one of, as mygo's Segmented: $options' labels in a
     * row, the chosen one raised; changed() reports a choice.
     * @param list<string> $options
     */
    public function segmented(array $options, int &$index): Node
    {
        $t = $this->theme;
        $index = max(0, min($index, count($options) - 1));
        $n = $this->row(1, 0, 0, $t->border)->round($t->radius); // the track: a border around a surface
          $track = $this->row($t->space(0.5), $t->space(0.5), 0, $t->surface)->round($t->radius - 1)->align(Node::STRETCH)->focusGroup(Node::HORIZONTAL, selects: true); // a radio group: the arrows choose
          foreach ($options as $i => $label) {
              $seg = $this->column(0, 0, 0, $i === $index ? $t->surfacePressed : null)->padding($t->space(1), $t->space(3))->round(max($t->radius - $t->space(0.5), 0))->focusable()->center();
              $seg->on = $i === $index; // Tab enters the group at the one chosen
              if ($i !== $index && $this->hovered($seg)) { $seg->color = $t->surfaceHover; }
                $this->text($label, $t->fontSize, $t->text);
              $this->end();
              if ($this->clicked($seg) && $i !== $index) {
                  $index = $i;
                  $n->changed = true;
              }
          }
          $this->end();
        $this->end();
        return $n;
    }

    /**
     * Tabs, as mygo's Tabs: the labels in a row, the chosen one underlined
     * in the accent; the caller builds the chosen tab's content after it.
     * changed() reports a choice.
     * @param list<string> $labels
     */
    public function tabs(array $labels, int &$index): Node
    {
        $t = $this->theme;
        $n = $this->row(0, $t->space(1))->focusGroup(Node::HORIZONTAL, selects: true); // a tab list: one stop of Tab, the arrows choose
        foreach ($labels as $i => $label) {
            $on = $i === $index;
            $tab = $this->column(0, 0)->padding($t->space(2), $t->space(3))->round($t->radius)->focusable()
                ->draw(function (DisplayList $list, Node $tab, Theme $t) use ($on) {
                    if ($on) { // the underline, Space(0.5) high, Space(1.5) in from the ends
                        $in = $t->space(1.5); $h = $t->space(0.5);
                        $list->rect($tab->x + $in, $tab->y + $tab->h - $h, $tab->w - 2 * $in, $h, $t->accent, 1.0, $h / 2);
                    }
                });
            $tab->on = $on;
            $this->text($label, $t->fontSize, $on || $this->hovered($tab) ? $t->text : $t->textMuted, $on ? Font::BOLD : 0);
            $this->end();
            if ($this->clicked($tab) && !$on) {
                $index = $i;
                $n->changed = true;
            }
        }
        $this->end();
        return $n;
    }

    /**
     * A collapsible section's header: a chevron and $title; clicking it
     * toggles $open. Returns whether it is open, so the caller builds the
     * body inside an if.
     */
    public function collapsible(string $title, bool &$open): bool
    {
        $t = $this->theme;
        $head = $this->row(0, $t->space(1))->padding($t->space(1), 0)->round($t->radius)->align(Node::CENTER)->focusable();
        if ($this->hovered($head)) { $head->color = $t->surfaceHover; }
          $this->column(0, 0)->size($t->space(0.5), 0); $this->end(); // PaddingStart(Space(0.5))
          $arrow = $this->box($t->space(4), $t->space(4), 0); $arrow->alpha = 0.0;
          $arrow->draw(fn(DisplayList $list, Node $a, Theme $t) => self::disclosure($list, $a, $open ? 90 : 0, $t->textMuted));
          $this->text($title, $t->fontSize, $t->text)->single();
          $this->column(0, 0)->size($t->space(2), 0); $this->end(); // PaddingEnd(Space(2))
        $this->end();
        if ($this->clicked($head)) {
            $open = !$open;
            $head->changed = true;
        }
        return $open;
    }

    /** mygo's disclosurePath: a chevron pointing right in box $a, turned $deg clockwise, stroked 1.5 in $color. */
    public static function disclosure(DisplayList $list, Node $a, float $deg, int $color): void
    {
        $cx = $a->x + $a->w / 2; $cy = $a->y + $a->h / 2; $d = $a->w / 8;
        $r = deg2rad($deg); $cos = cos($r); $sin = sin($r);
        $at = fn(float $x, float $y) => [$cx + $x * $cos - $y * $sin, $cy + $x * $sin + $y * $cos];
        [$x0, $y0] = $at(-$d, -2 * $d); [$x1, $y1] = $at($d, 0); [$x2, $y2] = $at(-$d, 2 * $d);
        $list->line($x0, $y0, $x1, $y1, $color, 1.0, 1.5);
        $list->line($x1, $y1, $x2, $y2, $color, 1.0, 1.5);
    }

    /**
     * A stepper, as mygo's and AppKit's beside a field: a bordered column
     * of an up and a down arrow stepping $value by $step within [$min,
     * $max]; a press steps at once and keeps stepping while held, Up and
     * Down step while it has the focus, Home and End go to the ends.
     * changed() reports a new value.
     */
    public function stepper(int|float &$value, float $min, float $max, float $step = 1): Node
    {
        $t = $this->theme;
        $n = $this->column(1, 1, 0, $t->border)->round($t->radius)->focusable();
        $n->size($t->space(5), $t->space(7));
        $ticks = $this->runtime?->stepper($n->id) ?? 0;
        $jump = $this->runtime?->jumped($n->id) ?? 0;
        $holding = $this->runtime?->holding($n->id) ?? 0;
        $v = $jump !== 0 ? ($jump > 0 ? $max : $min) : max($min, min($max, $value + $ticks * $step));
        foreach ([1 => '▴', -1 => '▾'] as $dir => $arrow) {
            $can = $dir > 0 ? $v < $max : $v > $min;
            $this->column(0, 0, 1, $holding === $dir ? $t->surfacePressed : $t->surface)->round($t->radius - 1)->center();
              $this->text($arrow, $t->fontSize - 2, $can ? $t->text : $t->textMuted);
            $this->end();
        }
        $this->end();
        if ($v != $value) {
            $value = is_int($value) ? (int)round($v) : $v;
            $n->changed = true;
        }
        return $n;
    }

    /** A radio button with a label: on while $value is $option, and sets it when chosen; changed() reports it. */
    public function radio(string $label, mixed &$value, mixed $option, ?float $size = null): Node
    {
        $t = $this->theme;
        $size ??= $t->fontSize;
        [$w, $h] = $this->metrics->measure($label, $size);
        $n = $this->add(new Node(Node::RADIO, 0, 0, 0, $w + $t->space(6), max($h, $t->space(4)), 0, $label, null, $size, $t->text));
        $n->focusable = true;
        if ($this->runtime?->clicked($n->id) && $value !== $option) {
            $value = $option;
            $n->changed = true;
        }
        $n->on = $value === $option;
        return $n;
    }

    /**
     * A slider over [$min, $max] moving $value: a press or drag sets it
     * where the pointer is, Left/Right move it by a hundredth of the range
     * while it has the focus; changed() reports it.
     */
    public function slider(float &$value, float $min = 0, float $max = 1, float $width = 160): Node
    {
        $t = $this->theme;
        $n = $this->add(new Node(Node::SLIDER, 0, 0, 0, $width, $t->space(5), 0, null, null, $t->fontSize, $t->text));
        $n->focusable = true;
        if ($this->runtime !== null) {
            $f = $this->runtime->slid($n->id);
            if ($f !== null) {
                $value = $min + $f * ($max - $min);
                $n->changed = true;
            }
            $step = $this->runtime->stepped($n->id);
            if ($step !== 0) {
                $value = max($min, min($max, $value + $step * ($max - $min) / 100));
                $n->changed = true;
            }
        }
        $n->fraction = $max > $min ? ($value - $min) / ($max - $min) : 0.0;
        $n->held = $this->runtime?->sliding($n->id) ?? false;
        return $n;
    }

    /**
     * A popup choosing among $options (labels) into $index: clicking it
     * shows the options as a menu at the control; changed() reports a
     * choice. Needs the window the control is in (the key window by default).
     * @param list<string> $options
     */
    public function select(array $options, int &$index, ?float $width = null, ?Window $window = null): Node
    {
        $t = $this->theme;
        $size = $t->fontSize;
        $label = $options[$index] ?? '';
        // A plain button's look (padding Space(1.5) by Space(3.5)), the label and a chevron Space(1.5) apart, at least Space(35) wide.
        $w = $width ?? max($t->space(35), max(array_map(fn($o) => $this->metrics->measure($o, $size)[0], $options ?: [''])) + 2 * $t->space(3.5) + $t->space(1.5) + $t->space(2.5));
        [, $h] = $this->metrics->measure('X', $size);
        $n = $this->add(new Node(Node::SELECT, 0, 0, 0, $w, $h + $t->space(3), 0, $label, $t->surface, $size, $t->text));
        $n->bordered = true; $n->chevron = true;
        $n->focusable = true;
        if ($this->runtime?->hovered($n->id)) {
            $n->color = $t->surfaceHover;
        }
        $rt = $this->runtime;
        if ($rt !== null && ($chosen = $rt->chosen($n->id)) !== null && $chosen !== $index && isset($options[$chosen])) {
            $index = $chosen; // the menu's action arrived after the popup returned, on a later run-loop turn
            $n->text = $options[$index];
            $n->changed = true;
        }
        if ($rt?->clicked($n->id) || ($n->id === $rt?->focus && $this->shortcut(Key::DOWN))) { // a click, Enter, Space or Down while focused, as a menu button's
            $window ??= Window::key();
            $items = [];
            $id = $n->id;
            foreach ($options as $i => $o) {
                $items[] = new MenuItem($o, function (MenuItem $it, ?Window $w) use ($rt, $id, $i) { $rt->chose($id, $i); $w?->update(); }, checked: $i === $index, type: MenuItem::CHECKBOX);
            }
            if ($window !== null) {
                (new Menu($items))->popupAt($window, $n->id, $rt);
            }
        }
        return $n;
    }

    /**
     * A single-line text field editing $state. Click or Tab gives it the
     * keyboard; the text input client of the window then edits $state. A
     * $width of 0 fills what the row has left.
     */
    public function input(TextState $state, string $placeholder = '', float $width = 200, ?float $size = null): Node
    {
        $t = $this->theme;
        $size ??= $t->fontSize;
        [, $h] = $this->metrics->measure('X', $size);
        $n = $this->add(new Node(Node::INPUT, 0, 0, 0, $width, $h + $t->space(3), $width <= 0 ? 1 : 0, $placeholder, $t->surface, $size, $t->text)); // mygo's: padding Space(1.5) by Space(2.5), the surface, bordered; 0: fills the row's remaining width
        $n->edit = $state;
        $n->focused = $this->runtime?->focused($n->id) ?? false;
        return $n;
    }

    /**
     * A text area editing $state: wraps at its width, scrolls with the wheel
     * and to keep the caret in view, Enter inserts a line, Up/Down move by
     * wrapped lines, Cmd+Z undoes. A $width or $height of 0 fills the room
     * its container has left along that axis (grow).
     */
    public function textarea(TextState $state, string $placeholder = '', float $width = 320, float $height = 120, ?float $size = null): Node
    {
        $t = $this->theme;
        $state->multiline = true;
        $n = $this->add(new Node(Node::TEXTAREA, 0, 0, 0, $width, $height, $width <= 0 || $height <= 0 ? 1 : 0, $placeholder, $t->surface, $size ?? $t->fontSize, $t->text));
        $n->edit = $state;
        $n->focused = $this->runtime?->focused($n->id) ?? false;
        return $n;
    }

    /** Whether the field's Enter was pressed this frame (resets the flag). */
    public function submitted(Node $n): bool
    {
        if ($n->edit !== null && $n->edit->submitted) {
            $n->edit->submitted = false;
            return true;
        }
        return false;
    }

    /** An image at $w × $h points (its own size when 0), corners rounded by $radius; $alpha fades it. */
    public function image(Image $image, float $w = 0, float $h = 0, float $radius = 0, float $alpha = 1.0): Node
    {
        if ($w <= 0 && $h <= 0) {
            $w = $image->w / $this->scale; $h = $image->h / $this->scale;
        } elseif ($w <= 0) {
            $w = $h * $image->w / $image->h;
        } elseif ($h <= 0) {
            $h = $w * $image->h / $image->w;
        }
        $n = $this->add(new Node(Node::IMAGE, 0, 0, 0, $w, $h, 0, null, null));
        $n->image = $image; $n->radius = $radius; $n->alpha = $alpha;
        return $n;
    }

    public function box(float $w, float $h, int $color): Node
    {
        return $this->add(new Node(Node::BOX, 0, 0, 0, $w, $h, 0, null, $color));
    }
}
