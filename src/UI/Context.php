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
    /** The form being built (Context::form): its labels' boxes and the widest label, for them all to be as wide. */
    private ?object $form = null;
    /** The checkbox group being built (Context::checkboxGroup): references to its check boxes' values, or null outside one. */
    private ?array $checks = null;

    /** The accordion being built, innermost last: how many items it has so far (the second on gets a line above). */
    private array $accordions = [];
    /** The tree being built, innermost last: its local state (last frame's item ids and their parents), this frame's, the open items enclosing the one being built. */
    private array $trees = [];

    /** The room the window controls take in a window with a hidden title bar (Window::titleBar): height, left, right; zero elsewhere. */
    public array $titleBar = ['height' => 0.0, 'left' => 0.0, 'right' => 0.0];

    /**
     * The room the window controls take in a window with a hidden title
     * bar (WindowOptions::$titleBarStyle), as mygo's Context.TitleBar:
     * height, left and right in points, all zero in other windows and in
     * full screen. Keep clear of the controls and let the bar drag the
     * window: `$c->row(...)->size(0, max($bar['height'], 32))->dragWindow()`.
     * @return array{height: float, left: float, right: float}
     */
    public function titleBar(): array
    {
        return $this->titleBar;
    }

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
            $this->nextKey = null; // the key is this node's alone: a leaf must not pass it on to the next node built
        } elseif ($this->scope !== '') {
            $n->id = self::hash($this->scope . '#' . ++$this->ordinal);
        } else {
            $n->id = ++$this->count;
        }
        return $n;
    }

    /** The id add() will give the next node, for what is computed from its last-frame rect before it is built (a grid's columns). */
    private function nextId(): int
    {
        if ($this->nextKey !== null) { return self::hash($this->scope . '/' . $this->nextKey); }
        if ($this->scope !== '') { return self::hash($this->scope . '#' . ($this->ordinal + 1)); }
        return $this->count + 1;
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
        $key = $this->nextKey;
        $this->add($n);
        $this->stack[] = $n;
        if ($key !== null) { // a keyed container scopes the nodes inside it
            $this->scopes[] = [$this->scope, $this->ordinal];
            $this->scope .= '/' . $key;
            $this->ordinal = 0;
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

    /**
     * A grid, as mygo's Grid: its children fill its cells row by row, in
     * columns sized by $cols (Track::fixed, fr, fit) and rows that fit
     * their content, or by $rows; $gap between tracks. An int $cols gives
     * that many columns of equal width (mygo's Columns(n)).
     * @param int|list<Track> $cols @param list<Track> $rows
     */
    public function grid(int|array $cols, float $gap = 0, array $rows = [], float $pad = 0, int $grow = 0, ?int $color = null): Node
    {
        if (is_int($cols)) { $cols = array_fill(0, max(1, $cols), Track::fr()); }
        return $this->open(new Node(Node::CONTAINER, Node::ROW, $pad, $gap, 0, 0, $grow, null, $color))->grid($cols, $rows);
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
            $first->selected = $state->chosen($i);
            $rt = $this->runtime;
            if ($rt !== null && $state->reorder !== null) { // a press on the row, moved 4 points, drags it (and the rows chosen with it)
                $d = $rt->dragged($first->id);
                if ($d !== null) {
                    if ($state->drag === null) {
                        $r = $rt->rectOf($first->id) ?? [0, 0, 0, 0];
                        $state->drag = ['rows' => $state->draggedRows($i), 'row' => $i, 'ox' => $rt->input->mouseX - $r[0], 'oy' => $rt->input->mouseY - $r[1], 'dist' => 0.0, 'far' => false];
                    }
                    $state->drag['dist'] += hypot($d[0], $d[1]);
                    $state->drag['far'] = $state->drag['far'] || $state->drag['dist'] >= 4; // mygo's dragStart
                }
                if ($state->drag !== null && $state->drag['far'] && in_array($i, $state->drag['rows'], true)) {
                    self::fade($first, 0.4); // where the row was, as it moves with the pointer
                }
            }
            if ($rt !== null && $state->drag !== null && $state->drag['far']) {
                $rt->clicked($first->id); // the release that ends a drag is no click
            } elseif ($rt !== null && $rt->doubleClicked($first->id)) {
                $state->choose($i);
                $state->submit();
            } elseif ($rt !== null && $rt->clicked($first->id)) {
                $state->click($i, $rt->clickMods); // Cmd and Shift choose several, with a selection
            }
        }
        [$this->scope, $this->ordinal] = [$scope, $ordinal];
        $this->end();
        if ($state->drag !== null && $this->runtime !== null) {
            $this->reorderRows($state, $node, $count);
        }
        return $node;
    }

    /**
     * The rows of $list being dragged (ListState::$drag): shows where they
     * would go, a copy of the row under the pointer with how many go with
     * it, scrolls the list near its edges, and as they drop calls
     * ListState::$reorder with them and the row they go before, as mygo's
     * listFrame.reorder.
     */
    private function reorderRows(ListState $state, Node $list, int $count): void
    {
        $rt = $this->runtime; $t = $this->theme;
        $drag = &$state->drag;
        $x = $rt->input->mouseX; $y = $rt->input->mouseY;
        // The row before which the rows dropped at $y go: that of the row of the last frame whose middle is below it, the count past the last (dropAt).
        $to = -1; $below = -1;
        foreach ($list->kids as $k => $kid) {
            $r = $rt->rectOf($kid->id);
            if ($r === null) { continue; }
            $row = $state->first + $k;
            if ($y < $r[1] + $r[3] / 2) { if ($to < 0 || $row < $to) { $to = $row; } } elseif ($row > $below) { $below = $row; }
        }
        if ($to < 0) { $to = min($below + 1, $count); }
        if (!$rt->held()) { // dropped: rows already there stay
            if ($drag['far'] && !(count($drag['rows']) === 1 && ($to === $drag['rows'][0] || $to === $drag['rows'][0] + 1))) {
                ($state->reorder)($drag['rows'], $to);
            }
            $drag = null;
            return;
        }
        if (!$drag['far']) { return; }
        $first = $state->first;
        $list->drawOver(function (DisplayList $dl, Node $l, Theme $t) use ($to, $first) { // the line where they would go: at the top of row $to, or under the last
            $k = $to - $first;
            if (isset($l->kids[$k])) { $y = $l->kids[$k]->y - max($l->gap, 0) / 2; }
            elseif (isset($l->kids[$k - 1])) { $y = $l->kids[$k - 1]->y + $l->kids[$k - 1]->h + max($l->gap, 0) / 2; }
            else { return; }
            $x = $l->x + $l->padX();
            $dl->rect($x + 4, $y - 1, $l->w - 2 * $l->padX() - 4, 2, $t->accent, 1.0, 1.0);
            $dl->rect($x, $y - 4, 8, 8, $t->accent, 1.0, 4.0);
        });
        $this->dragScroll($list, $state, $y);
        $k = $drag['row'] - $first;
        if (isset($list->kids[$k])) { $this->dragGhost($list->kids[$k], count($drag['rows']), $x - $drag['ox'], $y - $drag['oy']); }
    }

    /** A copy of $src under the pointer at three quarters, at ($gx, $gy), with an accent pill of how many go with it when more than one; at the root, over everything (mygo's paintDrag). */
    private function dragGhost(Node $src, int $count, float $gx, float $gy): void
    {
        $t = $this->theme; $x = $this->runtime->input->mouseX; $y = $this->runtime->input->mouseY;
        $ghost = self::copy($src);
        self::fade($ghost, 0.75);
        $ghost->at($gx - $this->root->padX(), $gy - $this->root->padY());
        $this->root->kids[] = $ghost;
        if ($count > 1) {
            $saved = $this->stack; $this->stack = [$this->root];
            [$tw, $th] = $this->metrics->measure((string)$count, $t->fontSize - 2);
            $this->row(0, 0, 0, $t->accent)->size(max($tw + 10, $th + 4), $th + 4)->round(($th + 4) / 2)->center()->at($x + 10 - $this->root->padX(), $y - $th - 14 - $this->root->padY());
              $this->text((string)$count, $t->fontSize - 2, $t->accentText, Font::BOLD);
            $this->end();
            $this->stack = $saved;
        }
    }

    /** The scrolling container $node scrolls while a drag is within 32 points of its top or bottom, faster nearer to it (mygo's dragScroll). */
    private function dragScroll(Node $node, Scrollable $state, float $y): void
    {
        $lr = $this->runtime->rectOf($node->id);
        if ($lr === null) { return; }
        $edge = 32; $dy = 0.0;
        if ($y - $lr[1] < $edge) { $dy = -($edge - ($y - $lr[1])) / 2; } elseif ($lr[1] + $lr[3] - $y < $edge) { $dy = ($edge - ($lr[1] + $lr[3] - $y)) / 2; }
        if ($dy != 0) { $state->scrollBy($dy); $this->runtime->animate(); }
    }

    /** A copy of $n and its children, with ids of their own (not those of the nodes copied, which keep their clicks and hover). */
    private static function copy(Node $n): Node
    {
        $c = clone $n;
        $c->id = self::hash('copy/' . $n->id);
        $c->draw = null; $c->drawOver = null; $c->tooltip = null; $c->focusable = false;
        $c->kids = array_map(self::copy(...), $n->kids);
        return $c;
    }

    /** Sets $n's and its children's alpha. */
    private static function fade(Node $n, float $alpha): void
    {
        $n->alpha = $alpha;
        foreach ($n->kids as $k) { self::fade($k, $alpha); }
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
        if ($this->checks !== null) { $this->checks[] = &$checked; } // in a group: the head checks or clears it
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
     * Sections one above the other in a bordered box, that $fn builds with
     * accordionItem, as mygo's Accordion: each opens and closes on its own;
     * Up and Down move the focus between their headers, Home and End too.
     */
    public function accordion(Closure $fn): Node
    {
        $t = $this->theme;
        $n = $this->column(0, 0, 0, $t->background)->round($t->radius)->border()->focusGroup(Node::VERTICAL);
        $this->accordions[] = 0;
          $fn();
        array_pop($this->accordions);
        $this->end();
        return $n;
    }

    /**
     * A section of an accordion titled $title, whose content $fn builds
     * while $open, as mygo's AccordionItem: a click on the header, or Enter
     * or Space while it has the focus, opens and closes it; changed()
     * reports it.
     */
    public function accordionItem(string $title, bool &$open, Closure $fn): Node
    {
        $t = $this->theme;
        $k = count($this->accordions) - 1;
        $n = $this->column(0, 0);
          if ($this->accordions[$k]++ > 0) { $this->divider(); } // a line above all but the first
          $head = $this->row(0, $t->space(2))->padding($t->space(2.5), $t->space(3))->align(Node::CENTER)->focusable();
          if ($this->hovered($head)) { $head->color = $t->surfaceHover; }
            $this->text($title, $t->fontSize, $t->text, Font::BOLD)->single();
            $this->spacer();
            $a = $this->box($t->space(4), $t->space(4), 0); $a->alpha = 0.0;
            $a->draw(fn(DisplayList $list, Node $a, Theme $t) => self::disclosure($list, $a, $open ? 270 : 90, $t->textMuted)); // down, or up while open
          $this->end();
          if ($this->clicked($head)) { $open = !$open; $n->changed = true; }
          if ($open) {
              $this->column(0, $t->space(2))->padding(0, $t->space(3));
                $fn();
                $this->column(0, 0)->size(0, $t->space(3)); $this->end(); // Padding(0, Space(3), Space(3)): the bottom
              $this->end();
          }
        $this->end();
        return $n;
    }

    /**
     * A row of $max stars of which $value are filled, as mygo's Rating
     * (AppKit's rating level indicator): a click on a star sets it, a click
     * on the star set clears it, and the arrows, Home and End change it
     * while the rating has the focus; the stars the pointer would set show
     * as it rests on them. changed() reports a new value.
     */
    public function rating(int &$value, int $max = 5): Node
    {
        $t = $this->theme;
        $rt = $this->runtime;
        $n = $this->row(0, $t->space(0.5))->focusable();
        $set = function (int $v) use (&$value, $max, $n) { $v = max(0, min($max, $v)); if ($v !== $value) { $value = $v; $n->changed = true; } };
        if ($rt !== null && $rt->focus === $n->id) {
            if ($this->shortcut(Key::RIGHT) || $this->shortcut(Key::UP)) { $set($value + 1); }
            if ($this->shortcut(Key::LEFT) || $this->shortcut(Key::DOWN)) { $set($value - 1); }
            if ($this->shortcut(Key::HOME)) { $set(0); }
            if ($this->shortcut(Key::END)) { $set($max); }
        }
        $size = $t->fontSize * 1.25; $stars = []; $hover = -1;
          for ($i = 0; $i < $max; $i++) {
              $stars[$i] = $this->box($size, $size, 0); $stars[$i]->color = null; // a glyph alone
              if ($this->hovered($stars[$i])) { $hover = $i; }
          }
        $this->end();
        foreach ($stars as $i => $star) {
            if ($this->clicked($star)) { $set($value === $i + 1 ? 0 : $i + 1); if ($rt !== null) { $rt->focus = $n->id; } }
            $filled = $i < $value; $color = $t->warning; $alpha = 1.0;
            if ($hover >= 0) { $filled = $i <= $hover; $alpha = 0.6; }
            // A star glyph in place of mygo's star path: filled in the warning color, or outlined in the muted color.
            $star->text = $filled ? '★' : '☆'; $star->fontSize = $size; $star->fg = $filled ? $color : $t->textMuted; $star->alpha = $alpha;
        }
        return $n;
    }

    /**
     * Two knobs on one track choosing the range $low to $high between $lo
     * and $hi, as mygo's RangeSlider: a press drags the knob nearest, the
     * arrows, Home and End move the knob with the focus by $step (a
     * hundredth of the range when 0, which also leaves values unsnapped),
     * and changed() reports a move. Space(5) high, at least Space(25) wide.
     */
    public function rangeSlider(float &$low, float &$high, float $lo, float $hi, float $step = 0, float $width = 0): Node
    {
        $t = $this->theme;
        $rt = $this->runtime;
        $kw = $t->space(5);
        $low = max($lo, min($low, $high)); $high = min($hi, max($low, $high));
        $n = $this->row(0, 0, $width <= 0 ? 1 : 0)->padding(0, $kw / 2)->min($t->space(25), $t->space(5));
        if ($width > 0) { $n->size($width, $t->space(5)); } else { $n->size(0, $t->space(5)); }
        $keyStep = $step > 0 ? $step : ($hi - $lo) / 100;
        $snap = fn(float $v) => $step > 0 ? round(($v - $lo) / $step) * $step + $lo : $v;
        $set = function (string $which, float $to) use (&$low, &$high, $lo, $hi, $snap, $n) {
            [$from, $until] = $which === 'low' ? [$lo, $high] : [$low, $hi];
            $to = $snap(max($from, min($until, $to)));
            if ($to != ($which === 'low' ? $low : $high)) { if ($which === 'low') { $low = $to; } else { $high = $to; } $n->changed = true; }
        };
        $local = $rt?->local($n->id, fn() => (object)['dragging' => null]);
        $r = $rt?->rectOf($n->id);
        $knobs = [];
          foreach (['low', 'high'] as $k => $which) { // the knobs: focusable, placed over the track; the arrows move the one focused
              $v = $which === 'low' ? $low : $high;
              $frac = $hi > $lo ? ($v - $lo) / ($hi - $lo) : 0.0;
              $knob = $this->key($which)->box(0, 0, 0)->size($kw, $t->space(5))->at(($r === null ? 0 : ($r[2] - $kw)) * $frac - $kw / 2, 0)->focusable(); // centered where the knob is drawn: at() is from the padding box, kw/2 in
              $knob->color = null; $knob->ownRing = true; // the knob is painted, ring and all, by the slider's draw
              $knobs[$which] = $knob;
              if ($rt !== null && $rt->focus === $knob->id) {
                  if ($this->shortcut(Key::LEFT) || $this->shortcut(Key::DOWN)) { $set($which, $v - $keyStep); }
                  if ($this->shortcut(Key::RIGHT) || $this->shortcut(Key::UP)) { $set($which, $v + $keyStep); }
                  if ($this->shortcut(Key::HOME)) { $set($which, $which === 'low' ? $lo : $low); }
                  if ($this->shortcut(Key::END)) { $set($which, $which === 'low' ? $high : $hi); }
              }
          }
        $this->end();
        // A press on the track or a knob drags the knob nearest to it, and gives it the focus.
        if ($rt !== null && $r !== null && $local !== null) {
            $d = $rt->dragged($n->id) ?? $rt->dragged($knobs['low']->id) ?? $rt->dragged($knobs['high']->id);
            if ($d !== null) {
                $x = $lo + max(0.0, min(1.0, ($rt->input->mouseX - $r[0] - $kw / 2) / max(1.0, $r[2] - $kw))) * ($hi - $lo);
                if ($local->dragging === null) { $local->dragging = abs($x - $high) < abs($x - $low) || $x > $high ? 'high' : 'low'; }
                $set($local->dragging, $x);
                $rt->focus = $knobs[$local->dragging]->id;
            } else {
                $local->dragging = null;
            }
        }
        $dragging = $local->dragging ?? null;
        $focusLow = $rt !== null && $rt->focus === $knobs['low']->id && $rt->focusVisible($knobs['low']->id);
        $focusHigh = $rt !== null && $rt->focus === $knobs['high']->id && $rt->focusVisible($knobs['high']->id);
        $n->draw(function (DisplayList $list, Node $e, Theme $t) use ($low, $high, $lo, $hi, $kw, $dragging, $focusLow, $focusHigh) {
            $h = $t->space(1.5); $cy = $e->y + $t->space(2.5);
            $f = fn(float $v) => $hi > $lo ? ($v - $lo) / ($hi - $lo) : 0.0;
            $x0 = $e->x + $kw / 2 + ($e->w - $kw) * $f($low); $x1 = $e->x + $kw / 2 + ($e->w - $kw) * $f($high);
            $list->rect($e->x, $cy - $h / 2, $e->w, $h, $t->border, 1.0, $h / 2);
            $list->rect(min($x0, $x1), $cy - $h / 2, abs($x1 - $x0), $h, $t->accent, 1.0, $h / 2);
            Painter::knob($list, $t, $x0, $cy, $dragging === 'low', $focusLow);
            Painter::knob($list, $t, $x1, $cy, $dragging === 'high', $focusHigh);
        });
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
     * The popup of a combobox or autocomplete, mygo's comboboxBase and
     * styleOptions: $options below $anchor while open, at most Space(70)
     * high and scrolling, each item padded Space(1.5) by Space(2.5), the
     * one highlighted in the accent; Up and Down move the highlight, Enter
     * or a click chooses, Escape closes, as does the input losing the
     * focus. Returns the option chosen this frame, or null. $local keeps
     * open, typed, highlight and values across frames.
     * @param list<string> $options
     */
    private function comboPopup(Node $in, Node $anchor, TextState $state, object $local, array $options, bool $firstTyped, bool $typedOnly, bool $clicked = false): ?string
    {
        $t = $this->theme;
        $rt = $this->runtime;
        if ($rt === null) { return null; }
        $chosen = null;
        $focused = $rt->focus === $in->id;
        $typed = $local->seen !== null && $local->seen !== $state->text;
        $local->seen = $state->text;
        $open = function (bool $o, bool $typed, int $highlight) use ($local) { $local->open = $o; $local->typed = $typed; $local->highlight = $highlight; };
        $n = count($local->values);
        if (!$focused) { if ($local->open) { $open(false, false, -1); } }
        elseif ($typed) { $open(true, true, $firstTyped ? 0 : -1); }
        elseif ($clicked && !$local->open) { $open(true, false, -1); }
        if ($focused) {
            if ($this->shortcut(Key::DOWN)) { if (!$local->open) { $open(true, $local->typed, 0); } elseif ($n > 0) { $local->highlight = min($local->highlight + 1, $n - 1); } }
            if ($this->shortcut(Key::UP)) { if (!$local->open) { $open(true, $local->typed, -2); } elseif ($n > 0) { $local->highlight = max($local->highlight - 1, 0); } } // -2: the last, once known
            if ($local->open && $this->shortcut(Key::ESCAPE)) { $open(false, false, -1); }
            if ($state->submitted && $local->open && $local->highlight >= 0 && $local->highlight < $n) { // Enter chooses the highlight; the caller sees submitted otherwise
                $chosen = $local->values[$local->highlight]; $state->submitted = false;
            }
        }
        $shown = $local->open && (!$typedOnly || $local->typed) ? $options : [];
        $local->values = $shown;
        if ($local->highlight === -2 || $local->highlight >= count($shown)) { $local->highlight = count($shown) - 1; }
        if ($shown !== [] && $local->open) {
            $r = $rt->rectOf($anchor->id) ?? [0, 0, 0, 0];
            $saved = $this->stack; $this->stack = [$this->root];
            $panel = $this->key('combo:' . $in->id)->column($t->space(1), 0, 0, $t->background)->round($t->radius + 2)->border()->shadow(6, 20, 0.18)
                ->at($r[0], $r[1] + $r[3] + $t->space(1))->size($r[2], 0)->scroll($local->scroll);
            $panel->size($r[2], min($t->space(70), count($shown) * ($t->space(3) + $this->metrics->measure('X', $t->fontSize)[1]) + 2 * $t->space(1)));
              foreach ($shown as $i => $opt) {
                  $hl = $i === $local->highlight;
                  $item = $this->key("o$i")->row(0, 0, 0, $hl ? $t->accent : null)->padding($t->space(1.5), $t->space(2.5))->round($t->radius);
                    $this->text($opt, $t->fontSize, $hl ? $t->accentText : $t->text)->single();
                  $this->end();
                  if ($this->hovered($item) && $rt->pressedAnywhere() === false) { $local->highlight = $i; }
                  if ($this->clicked($item)) { $chosen = $opt; }
              }
            $this->end();
            $this->stack = $saved;
        }
        if ($chosen !== null) { $open(false, false, -1); }
        return $chosen;
    }

    /**
     * A combobox choosing $selected among $options, as mygo's Combobox: a
     * field showing the choice, whose text filters the options below it
     * as the user types, those starting with it first, while a click or
     * Down shows them all; Up and Down move among them and Enter chooses,
     * as does a click; Escape closes them, and the text goes back to the
     * choice when the field loses the focus. changed() reports a new
     * choice.
     * @param list<string> $options
     */
    public function combobox(string &$selected, array $options, float $width = 0): Node
    {
        $t = $this->theme;
        $rt = $this->runtime;
        $f = $this->row(0, $t->space(1.5), 0, $t->surface)->padding($t->space(1.5), $t->space(2.5))->round($t->radius)->border()->align(Node::CENTER)->cursor('ibeam'); // mygo's field: as wide as its content in a row, stretched in a column
        if ($width > 0) { $f->size($width, 0); }
        $make = fn() => (object)['text' => new TextState($selected), 'open' => false, 'typed' => false, 'highlight' => -1, 'values' => [], 'seen' => null, 'scroll' => new ScrollState];
        $local = $rt?->local($f->id, $make) ?? $make();
        $state = $local->text;
          [, $h] = $this->metrics->measure('X', $t->fontSize);
          $in = $this->add(new Node(Node::INPUT, 0, 0, 0, 0, $h, 1, '', null, $t->fontSize, $t->text)); // the input, unframed, in the field's box
          $in->edit = $state; $in->inset(-Runtime::FIELD_INSET, -Runtime::FIELD_INSET); $in->min($t->space(25), 0);
          $in->focused = $rt?->focused($in->id) ?? false;
          $arrow = $this->box($t->space(2.5), $t->space(2.5), 0); $arrow->color = null;
          $arrow->draw(fn(DisplayList $list, Node $a, Theme $t) => Painter::chevron($list, $a->x, $a->y, $a->w, $t->textMuted));
        $this->end();
        $f->borderColor = $in->focused ? $t->accent : ($this->hovered($f) ? Theme::mix($t->border, $t->text, 0.25) : null);
        $click = false; // the input's own click, which opens the options while they are closed
        $focus = false;
        if ($this->clicked($arrow)) { // the chevron toggles them, the choice selected as a click in the text opening them does
            $local->open = !$local->open; $local->typed = false; $local->highlight = -1; $focus = true;
            if ($local->open) { $state->selectAll(); }
        } elseif ($this->clicked($in)) {
            $click = true; $focus = true;
            if (!$local->open) { $state->selectAll(); } // a click opening them selects the choice, for the next key to replace and filter; clicks while they show place the caret
        } elseif ($this->clicked($f)) { $focus = true; } // the box's padding: the input takes the focus
        if ($focus && $rt !== null) { $rt->focus = $in->id; $in->focused = true; }
        $shown = $local->typed ? self::matching($options, $state->text) : $options;
        $chosen = $this->comboPopup($in, $f, $state, $local, $shown, true, false, $click);
        $state->submitted = false; // Enter on no highlight is nothing to a combobox
        if ($chosen !== null) {
            if ($selected !== $chosen) { $selected = $chosen; $f->changed = true; }
            $state->text = $chosen; $state->selectAll(); $local->seen = $chosen;
        } elseif (!$local->open && $rt?->focus !== $in->id && $state->text !== $selected) {
            $state->text = $selected; $state->setCursor(strlen($selected)); $local->seen = $selected; // what was typed matched no choice
        }
        return $f;
    }

    /**
     * A text field editing $value which suggests below it those of
     * $suggestions containing what was typed, those starting with it
     * first, as mygo's Autocomplete: Up and Down move among them and Enter
     * takes one, as does a click, while Enter on none is $state->submitted.
     * changed() reports a change, typed or taken.
     * @param list<string> $suggestions
     */
    public function autocomplete(TextState $state, array $suggestions, string $placeholder = '', float $width = 200): Node
    {
        $rt = $this->runtime;
        $in = $this->input($state, $placeholder, $width);
        $make = fn() => (object)['open' => false, 'typed' => false, 'highlight' => -1, 'values' => [], 'seen' => null, 'scroll' => new ScrollState, 'was' => $state->text];
        $local = $rt?->local($in->id, $make) ?? $make();
        $shown = [];
        if (($q = trim($state->text)) !== '') {
            foreach (self::matching($suggestions, $q) as $sug) { if (strcasecmp($sug, $state->text) !== 0) { $shown[] = $sug; } }
        }
        $chosen = $this->comboPopup($in, $in, $state, $local, $shown, false, true);
        if ($chosen !== null) { $state->text = $chosen; $state->setCursor(strlen($chosen)); $local->seen = $chosen; }
        $in->changed = $state->text !== $local->was;
        $local->was = $state->text;
        return $in;
    }

    /**
     * A field of tokens, as of tags or a mail's recipients, as mygo's
     * TokenField: $tokens show as chips, each with a button taking it out,
     * before an input where Enter or a comma adds what was typed and
     * Backspace while empty takes out the last; the $suggestions containing
     * what was typed show below, those not tokens yet, Up and Down moving
     * among them and Enter or a click adding one. The field wraps its
     * chips onto more lines. changed() reports a change.
     * @param list<string> $tokens
     * @param list<string> $suggestions
     */
    public function tokenField(array &$tokens, array $suggestions = [], float $width = 0): Node
    {
        $t = $this->theme;
        $rt = $this->runtime;
        $f = $this->row(0, $t->space(1.5), 0, $t->surface)->padding($t->space(1.5), $t->space(2.5))->round($t->radius)->border()->align(Node::CENTER)->cursor('ibeam'); // mygo's field: as wide as its content in a row, stretched in a column
        if ($width > 0) { $f->size($width, 0); }
        $f->wrap($rt?->rectOf($f->id)[2] ?? 0.0);
        $make = fn() => (object)['text' => new TextState(''), 'open' => false, 'typed' => false, 'highlight' => -1, 'values' => [], 'seen' => null, 'scroll' => new ScrollState];
        $local = $rt?->local($f->id, $make) ?? $make();
        $state = $local->text; $state->leaveEmptyBackspace = true;
        $changed = false;
        $add = function (string $s) use (&$tokens, &$changed) { $s = trim($s); if ($s !== '' && !in_array($s, $tokens, true)) { $tokens[] = $s; $changed = true; } };
          foreach ($tokens as $i => $tok) {
              $chip = $this->key($tok)->row(0, $t->space(1), 0, $t->surfacePressed)->padding($t->space(0.25), 0)->round($t->radius)->align(Node::CENTER)->cursor('arrow');
                $this->column(0, 0)->size($t->space(1), 0); $this->end(); // with the gap: PaddingStart(Space(2))
                $this->text($tok, $t->fontSize)->single();
                $x = $this->box($t->space(3.5), $t->space(3.5), 0)->round($t->space(1)); $x->color = $this->hovered($x) ? $t->surfaceHover : null;
                $x->draw(function (DisplayList $list, Node $b, Theme $t) { $k = $b->w * 0.3; $list->line($b->x + $k, $b->y + $k, $b->x + $b->w - $k, $b->y + $b->h - $k, $t->textMuted, 1.0, 1.25); $list->line($b->x + $b->w - $k, $b->y + $k, $b->x + $k, $b->y + $b->h - $k, $t->textMuted, 1.0, 1.25); });
                $this->column(0, 0); $this->end(); // with the gap: PaddingEnd(Space(1))
              $this->end();
              if ($this->clicked($x)) { array_splice($tokens, $i, 1); $changed = true; }
          }
          [, $h] = $this->metrics->measure('X', $t->fontSize);
          $in = $this->add(new Node(Node::INPUT, 0, 0, 0, 0, $h, 1, '', null, $t->fontSize, $t->text)); // the input, unframed, after the chips, keeping its identity whatever they are
          $in->edit = $state; $in->inset(-Runtime::FIELD_INSET, -Runtime::FIELD_INSET); $in->min($t->space(20), 0);
          $in->focused = $rt?->focused($in->id) ?? false;
        $this->end();
        $f->borderColor = $in->focused ? $t->accent : ($this->hovered($f) ? Theme::mix($t->border, $t->text, 0.25) : null);
        if ($this->clicked($f) && $rt !== null) { $rt->focus = $in->id; $in->focused = true; }
        if ($in->focused && $tokens !== [] && $state->text === '' && $this->shortcut(Key::DELETE)) { array_pop($tokens); $changed = true; }
        $shown = [];
        if (($q = trim($state->text)) !== '') {
            foreach (self::matching($suggestions, $q) as $sug) { if (!in_array($sug, $tokens, true)) { $shown[] = $sug; } }
        }
        $chosen = $this->comboPopup($in, $f, $state, $local, $shown, false, true);
        if (str_contains($state->text, ',')) {
            $parts = explode(',', $state->text);
            foreach (array_slice($parts, 0, -1) as $part) { $add($part); }
            $state->text = end($parts); $state->setCursor(strlen($state->text)); $local->seen = $state->text;
        }
        if ($chosen !== null) { $add($chosen); $state->text = ''; $state->setCursor(0); $local->seen = ''; }
        elseif ($state->submitted) { $state->submitted = false; $add($state->text); $state->text = ''; $state->setCursor(0); $local->seen = ''; }
        if ($changed) { $f->changed = true; }
        return $f;
    }

    /**
     * A field showing the day of $date, as mygo's DateInput: a plain
     * button of the date (en-US, 1/5/2026) and a calendar page, which a
     * click, Enter or Space opens a calendar below; there a click chooses
     * a day, as the arrows and Enter do, Page Up and Page Down or its
     * buttons move by months, and Escape closes it. changed() reports a
     * new date, which keeps the time of day and the zone of $date.
     */
    public function dateInput(\DateTimeImmutable &$date): Node
    {
        $t = $this->theme;
        $rt = $this->runtime;
        $b = $this->buttonFace($t->space(1.5), $t->space(3.5))->min($t->space(32.5), 0); // its text and a page icon at its ends (Justify(SpaceBetween))
          $this->text($date->format('n/j/Y'), $t->fontSize, $t->text)->single();
          $this->row(0, 0, 1); $this->end();
          $page = $this->box($t->space(3.5), $t->space(3.5), 0); $page->color = null;
          $page->draw(function (DisplayList $list, Node $a, Theme $t) use ($b) { // a calendar page: a box stroked 1.2 at radius 2, a 1.2 line under its top
              $list->rect($a->x + 1, $a->y + 2, $a->w - 2, $a->h - 3, $t->textMuted, 1.0, 2.0); $list->rect($a->x + 2.2, $a->y + 3.2, $a->w - 4.4, $a->h - 5.4, $b->color, 1.0, 1.0);
              $list->rect($a->x + 1, $a->y + 5, $a->w - 2, 1.2, $t->textMuted, 1.0, 0.0);
          });
        $this->end();
        $make = fn() => (object)['open' => false, 'cursor' => $date];
        $local = $rt?->local($b->id, $make) ?? $make();
        if ($this->clicked($b)) { $local->open = !$local->open; $local->cursor = $date; }
        $this->popover($b, $local->open, function () use (&$date, $local, $b, $rt) {
            $grid = $this->calendarGrid($date, $local->cursor, false, function (\DateTimeImmutable $d) use (&$date, $local, $b, $rt) {
                if (self::setDay($date, $d)) { $b->changed = true; }
                $local->open = false;
                $rt?->focusOn($b->id);
            });
            if ($rt !== null && $rt->focus === $b->id && $local->open) { $rt->focusOn($grid->id); } // AutoFocus: the calendar takes the keys as it opens
        });
        return $b;
    }

    /** A plain button's face as a row holding what follows, mygo's styleButton on coreButton: a bordered surface, the theme's radius, shaded under the pointer and while pressed, focusable (ringed by the painter). */
    private function buttonFace(float $padY, float $padX): Node
    {
        $t = $this->theme;
        $b = $this->row(0, 0, 0, $t->surface)->padding($padY, $padX)->round($t->radius)->border()->focusable()->align(Node::CENTER);
        if ($this->hovered($b)) { $b->color = $this->runtime->input->mouseDown ? $t->surfacePressed : $t->surfaceHover; }
        return $b;
    }

    /**
     * A month's calendar choosing the day of $date, as mygo's Calendar: a
     * click chooses a day, as the arrows do while the calendar has the
     * focus, Page Up and Page Down or its buttons move by months, Home
     * and End go to the month's first and last day. changed() reports a
     * new date, which keeps the time of day and the zone of $date. The
     * names and the first weekday are en-US.
     */
    public function calendar(\DateTimeImmutable &$date): Node
    {
        $changed = false;
        $g = $this->calendarGrid($date, $date, true, function (\DateTimeImmutable $d) use (&$date, &$changed) {
            if (self::setDay($date, $d)) { $changed = true; }
        });
        $g->changed = $changed;
        return $g;
    }

    /** Sets the day of $date to $d's, keeping its time of day and zone; whether it changed. */
    private static function setDay(\DateTimeImmutable &$date, \DateTimeImmutable $d): bool
    {
        $next = $date->setDate((int)$d->format('Y'), (int)$d->format('n'), (int)$d->format('j'));
        if ($next == $date) { return false; }
        $date = $next;
        return true;
    }

    /**
     * mygo's calendarGrid: the month around $cursor, the day the keys move,
     * with $date chosen; $choose chooses a day, as a click and Enter do,
     * and every move does with $moveChooses. Cells Space(8) by Space(7),
     * the chosen in the accent, the cursor's surfaceHover, today ringed
     * in the accent, the month's neighbors' days muted.
     */
    private function calendarGrid(\DateTimeImmutable $date, \DateTimeImmutable &$cursor, bool $moveChooses, Closure $choose): Node
    {
        $t = $this->theme;
        $rt = $this->runtime;
        $cur = $cursor;
        $month = $cur->setDate((int)$cur->format('Y'), (int)$cur->format('n'), 1)->setTime(0, 0);
        $title = $month->format('F Y');
        $grid = $this->column(0, $t->space(0.5))->focusable(); $grid->ownRing = true;
        $visible = $rt?->focusVisible($grid->id) ?? false;
        $grid->draw(function (DisplayList $list, Node $g, Theme $t) use ($visible) { if ($visible) { Painter::focusRing($list, $g->x, $g->y, $g->w, $g->h, $t->radius, $t); } });
        $move = function (\DateTimeImmutable $d) use ($moveChooses, $choose, &$cursor) { if ($moveChooses) { $choose($d); } else { $cursor = $d; } }; // choosing moves the cursor where it is the date chosen
        if ($rt?->focus === $grid->id) {
            if ($this->shortcut(Key::LEFT)) { $move($cur->modify('-1 day')); }
            elseif ($this->shortcut(Key::RIGHT)) { $move($cur->modify('+1 day')); }
            elseif ($this->shortcut(Key::UP)) { $move($cur->modify('-7 days')); }
            elseif ($this->shortcut(Key::DOWN)) { $move($cur->modify('+7 days')); }
            elseif ($this->shortcut(Key::PAGE_UP)) { $move(self::addMonths($cur, -1)); }
            elseif ($this->shortcut(Key::PAGE_DOWN)) { $move(self::addMonths($cur, 1)); }
            elseif ($this->shortcut(Key::HOME)) { $move($cur->modify('first day of this month')); }
            elseif ($this->shortcut(Key::END)) { $move($cur->modify('last day of this month')); }
            elseif ($rt->activated($grid->id)) { $choose($cur); }
        }
          $this->row(0, $t->space(1))->align(Node::CENTER);
            $prev = $this->button('‹')->padding($t->space(0.5), $t->space(2.5));
            if ($this->clicked($prev)) { $move(self::addMonths($cur, -1)); }
            $this->row(0, 0, 1)->justify(Node::CENTER); $this->text($title, $t->fontSize, $t->text, Font::BOLD); $this->end();
            $next = $this->button('›')->padding($t->space(0.5), $t->space(2.5));
            if ($this->clicked($next)) { $move(self::addMonths($cur, 1)); }
          $this->end();
          $this->row(0, 0);
            foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $name) {
                $this->row(0, 0)->size($t->space(8), 0)->justify(Node::CENTER); $this->text($name, $t->fontSize - 2, $t->textMuted); $this->end();
            }
          $this->end();
          $start = $month->modify('-' . $month->format('w') . ' days'); // six weeks from the Sunday on or before the first
          $today = (new \DateTimeImmutable('today', $date->getTimezone()))->format('Y-m-d');
          for ($w = 0; $w < 6; $w++) {
              $this->row(0, 0);
              for ($d = 0; $d < 7; $d++) {
                  $day = $start->modify('+' . ($w * 7 + $d) . ' days');
                  $ymd = $day->format('Y-m-d');
                  $chosen = $ymd === $date->format('Y-m-d'); $atCursor = $ymd === $cur->format('Y-m-d');
                  $cell = $this->key($ymd)->row(0, 0)->size($t->space(8), $t->space(7))->center()->round($t->radius);
                  $fg = $day->format('n') !== $month->format('n') ? $t->textMuted : $t->text;
                  if ($chosen) { $cell->color = $t->accent; $fg = $t->accentText; }
                  elseif ($atCursor || $this->hovered($cell)) { $cell->color = $t->surfaceHover; }
                  if ($ymd === $today && !$chosen) { $cell->border($t->accent); }
                    $this->text($day->format('j'), $t->fontSize, $fg);
                  $this->end();
                  if ($this->clicked($cell)) { $choose($day); if (!$moveChooses) { $cursor = $day; } } // with moveChooses the cursor is the date, which choose set
              }
              $this->end();
          }
        $this->end();
        return $grid;
    }

    /** $d moved $n months, as Go's AddDate normalizes: the 31st of a 30-day month lands in the month after. */
    private static function addMonths(\DateTimeImmutable $d, int $n): \DateTimeImmutable
    {
        return $d->setDate((int)$d->format('Y'), (int)$d->format('n') + $n, (int)$d->format('j'));
    }

    /**
     * A field of the time of day of $time, as mygo's TimeInput in the
     * 12-hour cycle of en-US: 3:04 PM. The hours, the minutes and the half
     * of the day each take the focus: Up and Down step the one focused
     * round the clock, digits typed set it, going on to the minutes once
     * the hours are typed, A or P chooses the half, Left and Right move
     * between them. changed() reports a new time, which keeps the date and
     * the zone of $time.
     */
    public function timeInput(\DateTimeImmutable &$time): Node
    {
        $t = $this->theme;
        $rt = $this->runtime;
        $f = $this->row(0, 0, 0, $t->surface)->padding($t->space(1.5), $t->space(2))->round($t->radius)->border()->align(Node::CENTER);
        $h = (int)$time->format('G'); $m = (int)$time->format('i');
        $set = function (int $nh, int $nm) use (&$time, $f) {
            $next = $time->setTime($nh, $nm, (int)$time->format('s'), (int)$time->format('u'));
            if ($next != $time) { $time = $next; $f->changed = true; }
        };
        $segs = []; $focused = [];
        $texts = [0 => (string)(($h + 11) % 12 + 1), 1 => sprintf('%02d', $m), 2 => $h >= 12 ? 'PM' : 'AM'];
          foreach ([0, 1, 2] as $i => $k) {
              if ($k === 1) { $this->row(0, 0)->padding(0, 1); $this->text(':', $t->fontSize, $t->textMuted); $this->end(); }
              elseif ($i > 0) { $this->box($t->space(1), 0, 0)->color = null; }
              $seg = $this->key("seg$k")->row(0, 0)->padding(0, $t->space(0.5))->round($t->space(1))->focusable(); $seg->ownRing = true;
              $focused[$k] = $rt?->focused($seg->id) ?? false;
              if ($focused[$k]) { $seg->color = $t->accent; }
                $this->text($texts[$k], $t->fontSize, $focused[$k] ? $t->accentText : $t->text);
              $this->end();
              $segs[$k] = $seg;
          }
        $this->end();
        if (in_array(true, $focused, true)) { $f->borderColor = $t->accent; }
        $make = fn() => (object)['typed' => '']; // the digits typed at the segment focused, until they set it or the focus leaves
        $local = $rt?->local($f->id, $make) ?? $make();
        if (!in_array(true, $focused, true)) { $local->typed = ''; }
        foreach ([0, 1, 2] as $i => $k) {
            $seg = $segs[$k];
            if (!$focused[$k] || $rt === null) { continue; }
            $step = 0;
            if ($this->shortcut(Key::UP)) { $step = 1; }
            elseif ($this->shortcut(Key::DOWN)) { $step = -1; }
            elseif ($i + 1 < 3 && $this->shortcut(Key::RIGHT)) { $rt->focusOn($segs[$k + 1]->id, true); $local->typed = ''; }
            elseif ($i > 0 && $this->shortcut(Key::LEFT)) { $rt->focusOn($segs[$k - 1]->id, true); $local->typed = ''; }
            $local->typed .= $rt->typed($seg->id);
            if ($k === 2) { // the half of the day: Up and Down switch it, as does the first letter of the other's name
                $pmNow = $h >= 12; $want = $step !== 0 ? !$pmNow : $pmNow;
                if ($local->typed !== '') {
                    $r = strtolower(mb_substr($local->typed, -1));
                    if ($r === 'p') { $want = true; } elseif ($r === 'a') { $want = false; }
                    $local->typed = '';
                }
                if ($want !== $pmNow) { $set($want ? $h + 12 : $h - 12, $m); }
                continue;
            }
            $top = $k === 0 ? 24 : 60;
            if ($step !== 0) {
                if ($k === 0) { $h = intdiv($h, 12) * 12 + ($h % 12 + $step + 12) % 12; } // round the twelve hours of the same half of the day
                else { $m = ($m + $step + $top) % $top; }
                $set($h, $m);
            }
            if ($local->typed !== '') { // digits: the first sets the segment, a second makes two digits of them, or starts anew past the top; both typed, or one that can't begin two, go on to the minutes
                if ($k === 0) { $top = 13; }
                $typed = $local->typed;
                $n = ctype_digit($typed) ? (int)$typed : null;
                if ($n === null || strlen($typed) > 2 || $n >= $top) { $typed = substr($typed, -1); $n = ctype_digit($typed) ? (int)$typed : null; $local->typed = $typed; }
                if ($n !== null) {
                    if ($k === 0 && $n === 0) { /* the first digit of 01 to 09 */ }
                    elseif ($k === 0) { $h = intdiv($h, 12) * 12 + $n % 12; $set($h, $m); }
                    else { $m = $n; $set($h, $m); }
                    if ((strlen($typed) === 2 || $n * 10 >= $top) && $k === 0) { $local->typed = ''; $rt->focusOn($segs[1]->id, true); }
                } else { $local->typed = ''; }
            }
        }
        return $f;
    }

    /** mygo's swatches: the colors a picker offers, by name. */
    private const SWATCHES = ['red' => 0xef4444, 'orange' => 0xf97316, 'yellow' => 0xeab308, 'green' => 0x22c55e, 'teal' => 0x14b8a6, 'blue' => 0x3b82f6,
        'indigo' => 0x6366f1, 'purple' => 0xa855f7, 'pink' => 0xec4899, 'brown' => 0x92400e, 'gray' => 0x71717a, 'black' => 0x000000];

    /**
     * A picker of $rgb at $alpha, as mygo's ColorPicker after AppKit's
     * color panel: a square choosing the saturation, across, and the
     * brightness, up, of the hue its slider chooses below it, a slider of
     * opacity, the color in hex, which the user edits, and swatches of
     * common colors. The arrows move across the square while it has the
     * focus. changed() reports a new color.
     */
    public function colorPicker(int &$rgb, float &$alpha): Node
    {
        $t = $this->theme;
        $rt = $this->runtime;
        $panel = $this->column(0, $t->space(2.5))->size($t->space(60), 0);
        $make = fn() => (object)['hsv' => self::toHSV($rgb), 'last' => [$rgb, $alpha], 'hex' => new TextState(self::hexOf($rgb, $alpha)), 'hue' => 0.0, 'opacity' => 0.0];
        $st = $rt?->local($panel->id, $make) ?? $make();
        if ($st->last !== [$rgb, $alpha]) { // the app set another color
            $st->hsv = self::toHSV($rgb); $st->last = [$rgb, $alpha]; $st->hex->text = self::hexOf($rgb, $alpha);
        }
        $set = function (float $h, float $sat, float $v, float $a) use (&$rgb, &$alpha, $st, $panel) {
            $sat = max(0.0, min(1.0, $sat)); $v = max(0.0, min(1.0, $v)); $a = max(0.0, min(1.0, $a));
            $st->hsv = [$h, $sat, $v];
            $next = self::fromHSV($h, $sat, $v);
            if ($next !== $rgb || $a !== $alpha) { $rgb = $next; $alpha = $a; $panel->changed = true; }
            $st->last = [$rgb, $alpha]; $st->hex->text = self::hexOf($rgb, $alpha);
        };
        [$h, $sat, $v] = $st->hsv;
          // The square of saturation and brightness.
          $sq = $this->box(0, $t->space(40), 0)->round($t->radius)->focusable()->cursor('crosshair'); $sq->color = null; $sq->ownRing = true;
          if ($rt !== null) {
              if ($rt->dragged($sq->id) !== null && ($r = $rt->rectOf($sq->id)) !== null && $r[2] > 0 && $r[3] > 0) {
                  $set($h, ($rt->input->mouseX - $r[0]) / $r[2], 1 - ($rt->input->mouseY - $r[1]) / $r[3], $alpha);
              }
              if ($rt->focus === $sq->id) {
                  if ($this->shortcut(Key::RIGHT)) { $set($h, $sat + 0.01, $v, $alpha); }
                  elseif ($this->shortcut(Key::LEFT)) { $set($h, $sat - 0.01, $v, $alpha); }
                  elseif ($this->shortcut(Key::UP)) { $set($h, $sat, $v + 0.01, $alpha); }
                  elseif ($this->shortcut(Key::DOWN)) { $set($h, $sat, $v - 0.01, $alpha); }
              }
          }
          [$h, $sat, $v] = $st->hsv;
          $hue = self::fromHSV($h, 1, 1);
          $ring = $rt?->focusVisible($sq->id) ?? false;
          $sq->draw(function (DisplayList $list, Node $a, Theme $t) use ($hue, $sat, $v, $ring) {
              $list->gradient2($a->x, $a->y, $a->w, $a->h, 0xffffff, 1.0, $hue, 1.0, false, $t->radius);
              $list->gradient2($a->x, $a->y, $a->w, $a->h, 0x000000, 0.0, 0x000000, 1.0, true, $t->radius);
              $cx = $a->x + $a->w * $sat; $cy = $a->y + $a->h * (1 - $v); $d = $t->space(3.5); // the color chosen, ringed
              $list->ring($cx - $d / 2, $cy - $d / 2, $d, $d, 0xffffff, 1.0, $d / 2, 2.0);
              $list->ring($cx - $d / 2 - 1, $cy - $d / 2 - 1, $d + 2, $d + 2, 0x000000, 0.3, $d / 2 + 1, 1.0);
              if ($ring) { Painter::focusRing($list, $a->x, $a->y, $a->w, $a->h, $t->radius, $t); }
          });
          // The hue, and the opacity.
          $st->hue = $h; $st->opacity = $alpha;
          $hs = $this->channelSlider($st->hue, 360, function (DisplayList $list, float $x, float $y, float $w, float $h) {
              $hues = [0xff0000, 0xffff00, 0x00ff00, 0x00ffff, 0x0000ff, 0xff00ff, 0xff0000];
              $sw = $w / 6;
              for ($i = 0; $i < 6; $i++) { $list->gradient2($x + $sw * $i, $y, $sw + 0.5, $h, $hues[$i], 1.0, $hues[$i + 1], 1.0); }
          });
          if ($hs->changed) { $set($st->hue, $sat, $v, $alpha); }
          $opaque = self::fromHSV($h, $sat, $v);
          $as = $this->channelSlider($st->opacity, 1, function (DisplayList $list, float $x, float $y, float $w, float $h) use ($opaque, $t) {
              self::checkers($list, $x, $y, $w, $h, $t->space(1.5));
              $list->gradient2($x, $y, $w, $h, $opaque, 0.0, $opaque, 1.0);
          });
          if ($as->changed) { $set($h, $sat, $v, $st->opacity); }
          // The color, and its hex.
          $this->row(0, $t->space(2))->align(Node::CENTER);
            $now = $rgb; $nowA = $alpha;
            $well = $this->box($t->space(7), $t->space(7), 0)->round($t->radius)->border(); $well->color = $t->surface;
            $well->draw(function (DisplayList $list, Node $a, Theme $t) use ($now, $nowA) {
                $list->pushClip($a->x + 1, $a->y + 1, $a->w - 2, $a->h - 2);
                self::checkers($list, $a->x + 1, $a->y + 1, $a->w - 2, $a->h - 2, $t->space(1.5));
                $list->rect($a->x + 1, $a->y + 1, $a->w - 2, $a->h - 2, $now, $nowA, $t->radius - 1);
                $list->popClip();
            });
            $in = $this->input($st->hex, '', 0);
            $typed = $st->hex->seen !== null && $st->hex->seen !== $st->hex->text; $st->hex->seen = $st->hex->text;
            if ($typed && ($parsed = self::parseHex($st->hex->text)) !== null) {
                $hex = $st->hex->text;
                [$ph, $ps, $pv] = self::toHSV($parsed[0]); $set($ph, $ps, $pv, $parsed[1]);
                $st->hex->text = $hex; $st->hex->seen = $hex; // as typed
            }
            if (!$in->focused && $st->hex->text !== self::hexOf($rgb, $alpha)) { $st->hex->text = self::hexOf($rgb, $alpha); $st->hex->seen = $st->hex->text; }
          $this->end();
          // Swatches: a grid of six columns, Space(1.5) apart, Space(5) high, the chosen bordered 2 in the text color.
          $this->grid(6, $t->space(1.5));
            foreach (self::SWATCHES as $name => $color) {
                $b = $this->key($name)->row(0, 0, 0, $color)->size(0, $t->space(5))->round($t->radius)->focusable()->tooltip($name);
                if ($rgb === $color && $alpha === 1.0) { $b->border($t->text, 2.0); }
                $this->end();
                if ($this->clicked($b)) { [$ph, $ps, $pv] = self::toHSV($color); $set($ph, $ps, $pv, 1.0); }
            }
          $this->end();
        $this->end();
        return $panel;
    }

    /** mygo's channelSlider: a slider of a picker's channel from 0 to $max, Space(4) high, padded by half its Space(3) knob, whose track $track paints in the rect it is given. */
    private function channelSlider(float &$v, float $max, Closure $track): Node
    {
        $t = $this->theme;
        $s = $this->slider($v, 0, $max, 0);
        $s->grow = 1; $s->fh = $t->space(4); $s->ownRing = true;
        $frac = $max > 0 ? $v / $max : 0.0; $held = $s->held; $ring = $this->runtime?->focusVisible($s->id) ?? false;
        $s->draw(function (DisplayList $list, Node $n, Theme $t) use ($track, $frac, $held, $ring) {
            $kw = $t->space(3);
            $bx = $n->x; $by = $n->y + $t->space(0.5); $bw = $n->w; $bh = $n->h - $t->space(1);
            $list->pushClip($bx, $by, $bw, $bh); $track($list, $bx, $by, $bw, $bh); $list->popClip(); // the track, in a capsule's clip were there one; the gradients fill the rect
            $x = $n->x + $kw / 2 + ($n->w - $kw) * $frac;
            $g = 1 + ($held ? 0.2 : 0.0); $w = $kw * $g; $h = $n->h * $g; $kx = $x - $w / 2; $ky = $n->y + $n->h / 2 - $h / 2;
            $list->shadow($kx, $ky, $w, $h, 0x000000, 0.3, $w / 2, 3, 0, 1);
            $list->rect($kx, $ky, $w, $h, 0xffffff, 1.0, $w / 2);
            if ($ring) { Painter::focusRing($list, $kx, $ky, $w, $h, $w / 2, $t); }
        });
        return $s;
    }

    /** mygo's checkers: the gray squares, $size wide, that show transparency, over white. */
    public static function checkers(DisplayList $list, float $x, float $y, float $w, float $h, float $size): void
    {
        $list->rect($x, $y, $w, $h, 0xffffff);
        for ($yy = $y, $row = 0; $yy < $y + $h; $yy += $size, $row++) {
            for ($xx = $x, $col = 0; $xx < $x + $w; $xx += $size, $col++) {
                if (($row + $col) % 2 === 0) { $list->rect($xx, $yy, min($size, $x + $w - $xx), min($size, $y + $h - $yy), 0xcccccc); }
            }
        }
    }

    /**
     * A swatch of $rgb at $alpha that opens a colorPicker below it, as
     * mygo's ColorWell after AppKit's: a click, Enter or Space opens it,
     * Escape or a click outside closes it. changed() reports a new color.
     */
    public function colorWell(int &$rgb, float &$alpha): Node
    {
        $t = $this->theme;
        $rt = $this->runtime;
        $b = $this->row(0, 0, 0, $t->surface)->padding($t->space(1), $t->space(1))->round($t->radius)->border()->focusable();
        if ($this->hovered($b)) { $b->color = $rt->input->mouseDown ? $t->surfacePressed : $t->surfaceHover; }
          $now = $rgb; $nowA = $alpha;
          $sw = $this->box($t->space(9), $t->space(5), 0)->round($t->space(1)); $sw->color = null;
          $sw->draw(function (DisplayList $list, Node $a, Theme $t) use ($now, $nowA) {
              $list->pushClip($a->x, $a->y, $a->w, $a->h);
              self::checkers($list, $a->x, $a->y, $a->w, $a->h, $t->space(1.25));
              $list->rect($a->x, $a->y, $a->w, $a->h, $now, $nowA, $t->space(1));
              $list->popClip();
          });
        $this->end();
        $make = fn() => (object)['open' => false];
        $local = $rt?->local($b->id, $make) ?? $make();
        if ($this->clicked($b)) { $local->open = !$local->open; }
        $this->popover($b, $local->open, function () use (&$rgb, &$alpha, $b, $t) {
            $this->column(0, 0)->padding($t->space(3) - $t->space(1), $t->space(3) - $t->space(1)); // the panel's Space(1) and this: Padding(Space(3))
              if ($this->colorPicker($rgb, $alpha)->changed) { $b->changed = true; }
            $this->end();
        });
        return $b;
    }

    /** The hue (0..360), saturation and value (0..1) of $rgb, mygo's toHSVA. */
    public static function toHSV(int $rgb): array
    {
        $r = (($rgb >> 16) & 255) / 255; $g = (($rgb >> 8) & 255) / 255; $b = ($rgb & 255) / 255;
        $hi = max($r, $g, $b); $lo = min($r, $g, $b); $d = $hi - $lo;
        $h = 0.0;
        if ($d == 0) { }
        elseif ($hi == $r) { $h = 60 * fmod(($g - $b) / $d, 6); }
        elseif ($hi == $g) { $h = 60 * (($b - $r) / $d + 2); }
        else { $h = 60 * (($r - $g) / $d + 4); }
        if ($h < 0) { $h += 360; }
        return [$h, $hi > 0 ? $d / $hi : 0.0, $hi];
    }

    /** The color of a hue, saturation and value, mygo's hsva.color. */
    public static function fromHSV(float $h, float $s, float $v): int
    {
        $c = $v * $s; $hh = fmod($h, 360) / 60; $y = $c * (1 - abs(fmod($hh, 2) - 1));
        [$r, $g, $b] = match (true) { $hh < 1 => [$c, $y, 0], $hh < 2 => [$y, $c, 0], $hh < 3 => [0, $c, $y], $hh < 4 => [0, $y, $c], $hh < 5 => [$y, 0, $c], default => [$c, 0, $y] };
        $m = $v - $c;
        $byte = fn(float $x) => (int)round(max(0, min(1, $x)) * 255);
        return ($byte($r + $m) << 16) | ($byte($g + $m) << 8) | $byte($b + $m);
    }

    /** $rgb as #rrggbb, or #rrggbbaa when not opaque. */
    public static function hexOf(int $rgb, float $alpha): string
    {
        return $alpha >= 1.0 ? sprintf('#%06x', $rgb) : sprintf('#%06x%02x', $rgb, (int)round($alpha * 255));
    }

    /** The [rgb, alpha] of #rgb, #rrggbb or #rrggbbaa (the # optional), or null. */
    public static function parseHex(string $s): ?array
    {
        $s = ltrim(trim($s), '#');
        if (!ctype_xdigit($s)) { return null; }
        return match (strlen($s)) {
            3 => [(int)hexdec($s[0] . $s[0] . $s[1] . $s[1] . $s[2] . $s[2]), 1.0],
            6 => [(int)hexdec($s), 1.0],
            8 => [(int)hexdec(substr($s, 0, 6)), hexdec(substr($s, 6)) / 255],
            default => null,
        };
    }

    /**
     * A check box over the check boxes $fn builds, indented below it, as
     * mygo's CheckboxGroup (a list of permissions): checked when they all
     * are, mixed when some are, and a click, or Space while it has the
     * focus, checks them all, or none when they all are. changed() reports
     * a click.
     */
    public function checkboxGroup(string $label, Closure $fn): Node
    {
        $t = $this->theme;
        $g = $this->column(0, $t->space(2));
          $dummy = false; $head = $this->checkbox($label, $dummy); // its click is read below, its state set from the boxes
          $saved = $this->checks; $this->checks = [];
          $this->row(0, 0);
            $this->column(0, 0)->size($t->space(6), 0); $this->end(); // PaddingStart(Space(6))
            $this->column(0, $t->space(2), 1); $fn(); $this->end();
          $this->end();
          $boxes = $this->checks; $this->checks = $saved;
          $on = 0; foreach ($boxes as $b) { if ($b) { $on++; } }
          $all = $on === count($boxes) && $on > 0;
          if ($head->changed) { // its click, as checkbox() took it
              foreach ($boxes as &$b) { $b = !$all; } unset($b);
              $g->changed = true; $head->changed = false;
              $all = !$all; $on = $all ? count($boxes) : 0;
          }
          $head->on = $all; $head->mixed = !$all && $on > 0;
        $this->end();
        return $g;
    }

    /**
     * A path of $items separated by chevrons, as mygo's Breadcrumbs after
     * Finder's path bar: the items but the last are links, which a click
     * chooses, as Enter does, setting $chosen to its index, which
     * changed() reports. Long items shorten with an ellipsis as room runs
     * short.
     * @param list<string> $items
     */
    public function breadcrumbs(array $items, int &$chosen): Node
    {
        $t = $this->theme;
        $e = $this->row(0, $t->space(0.5))->align(Node::CENTER);
          $n = count($items);
          foreach ($items as $i => $item) {
              if ($i > 0) { // a chevron pointing along the path, stroked 1.2 in the muted color
                  $this->box($t->space(3), $t->space(3), 0)->draw(function (DisplayList $list, Node $a, Theme $t) {
                      $cx = $a->x + $a->w / 2; $cy = $a->y + $a->h / 2; $d = $a->w / 6;
                      $list->line($cx - $d, $cy - 2 * $d, $cx + $d, $cy, $t->textMuted, 1.0, 1.2);
                      $list->line($cx + $d, $cy, $cx - $d, $cy + 2 * $d, $t->textMuted, 1.0, 1.2);
                  })->alpha = 0.0;
              }
              $last = $i === $n - 1;
              $b = $this->key($i)->row(0, 0)->padding($t->space(0.5), $t->space(1.5))->round($t->radius)->min($t->space(6), 0)->single(); // shrinks with the others as room runs short
              if (!$last) { $b->focusable(); if ($this->hovered($b)) { $b->color = $t->surfaceHover; } }
                $this->text($item, $t->fontSize, $last ? $t->text : $t->textMuted, $last ? Font::BOLD : 0)->single(); // the last, where the path is: no link, weight 500 (bold here)
              $this->end();
              if (!$last && $this->clicked($b)) { $chosen = $i; $e->changed = true; }
          }
        $this->end();
        return $e;
    }

    /** What alertDialog returns in the frame Escape closes the alert, choosing no button. */
    public const ALERT_DISMISSED = -2;

    /**
     * An alert over the window while $open, as mygo's AlertDialog after
     * AppKit's: $title, $message and $buttons, the last the default, in
     * the accent with the focus, which Enter clicks. Escape closes it
     * choosing no button, whatever the buttons say; a click outside does
     * nothing. Returns the index of the button clicked in the frame it
     * is, which closes the alert, ALERT_DISMISSED in the frame Escape
     * closes it, and -1 otherwise. An alert that must be answered sets
     * $open back to true on ALERT_DISMISSED.
     */
    public function alertDialog(bool &$open, string $title, string $message, string ...$buttons): int
    {
        if (!$open) { return -1; }
        $t = $this->theme;
        $rt = $this->runtime;
        $chosen = -1;
        $back = $this->key('alert')->column(0, 0, 0, 0x000000)->at(0, 0)->size($this->width, $this->height)->center()->block(); // keyed: it comes and goes, and its state must not be another node's
        $back->alpha = 0.4;
          $panel = $this->column($t->space(5), $t->space(3), 0, $t->background)->size(min($t->space(75), $this->width - $t->space(10)), 0)->round($t->space(2.5))->shadow(10, 30, 0.3);
            $this->text($title, $t->fontSize + 1, $t->text, Font::BOLD);
            if ($message !== '') { $this->text($message, $t->fontSize, $t->textMuted); }
            $this->column(0, 0)->size(0, $t->space(2)); $this->end(); // Margin(Space(2), 0, 0, 0) on the buttons
            $this->row(0, $t->space(2))->justify(Node::END);
              $make = fn() => (object)['opening' => true];
              $local = $rt?->local($panel->id, $make) ?? $make();
              foreach ($buttons as $i => $label) {
                  $b = $i === count($buttons) - 1 ? $this->primaryButton($label) : $this->button($label);
                  if ($i === count($buttons) - 1 && $local->opening && $rt !== null) { $local->opening = false; $rt->focusOn($b->id); } // AutoFocus: the default, which Enter clicks
                  if ($this->clicked($b)) { $chosen = $i; }
              }
            $this->end();
          $this->end();
        $this->end();
        if ($chosen < 0 && $this->shortcut(Key::ESCAPE)) { $chosen = self::ALERT_DISMISSED; }
        if ($chosen !== -1) { $open = false; $local->opening = true; }
        return $chosen;
    }

    /**
     * A scroll container of $n items in a grid, as mygo's GridView after
     * Photos' library and Finder's icon view, built with $item($i): as
     * many columns as fit items at least $minWidth wide, which share the
     * width left, and rows $height high, built only as they show, so
     * grids of millions of items are as fast as small ones; Space(2)
     * spaces the items. With $s->selects, a click chooses an item, as the
     * arrows do in two dimensions while the grid has the focus, with Home,
     * End and the first letters of an item's $s->label; changed() reports
     * a new choice, submitted() a double click or Enter.
     */
    public function gridView(GridState $s, int $n, float $minWidth, float $height, Closure $item): Node
    {
        $t = $this->theme;
        $rt = $this->runtime;
        $n = max($n, 0);
        $gap = $t->space(2);
        $id = $this->nextId(); // the list's node, built below: its width last frame gives the columns
        $width = $rt?->rectOf($id)[2] ?? 0.0;
        if ($width <= 0) { $width = $this->width; }
        $cols = max(1, (int)floor(($width + $gap) / ($minWidth + $gap)));
        if ($s->cols !== $cols && $s->cols > 0 && $s->started) { // keep the rows in view on the same items
            $s->rows->scrollTo(intdiv($s->rows->row * $s->cols, $cols), false);
        }
        $s->cols = $cols; $s->started = true;
        $rows = intdiv($n + $cols - 1, $cols);
        $cw = ($width - $gap * ($cols - 1)) / $cols; // the items share the width (Grow(1).Basis(0))
        $focused = $s->selects && ($rt?->focused($id) ?? false);
        $changed = false; $submitted = false;
        $grid = $this->list($s->rows, $rows, function (int $r) use ($s, $n, $cols, $cw, $gap, $height, $item, $t, $rt, $focused, &$changed, &$submitted, $id) {
            $this->row(0, $gap)->padding($gap / 2, 0)->align(Node::STRETCH);
              for ($i = $r * $cols; $i < min($n, ($r + 1) * $cols); $i++) {
                  // A border, clear unless chosen, keeps the content in place as it is.
                  $cell = $this->key($i)->column(0, 0)->padding(2, 2)->size($cw, $height)->round($t->radius);
                  if ($s->selects) {
                      $chosen = $s->chosen($i);
                      if ($chosen && $focused) { $cell->color = Theme::mix($t->accent, $t->background, 0.8); $cell->border($t->accent, 2.0); } // Accent.Alpha(0.2)
                      elseif ($chosen) { $cell->color = $t->surfacePressed; $cell->border($t->border, 2.0); }
                      elseif ($this->hovered($cell)) { $cell->color = $t->surfaceHover; }
                      if ($i === $s->selected && $s->selection !== null && !$chosen && $rt?->focusVisible($id)) { // where the keys moved without choosing
                          $cell->draw(fn(DisplayList $list, Node $a, Theme $t) => Painter::focusRing($list, $a->x + 3, $a->y + 3, $a->w - 6, $a->h - 6, $a->radius, $t));
                      }
                  }
                    $item($i);
                  $this->end();
                  if ($rt !== null && $s->reorder !== null) { // a press on the cell, moved 4 points, drags it (and the items chosen with it)
                      $d = $rt->dragged($cell->id);
                      if ($d !== null) {
                          if ($s->drag === null) {
                              $cr = $rt->rectOf($cell->id) ?? [0, 0, 0, 0];
                              $s->drag = ['items' => $s->draggedItems($i, $n), 'item' => $i, 'ox' => $rt->input->mouseX - $cr[0], 'oy' => $rt->input->mouseY - $cr[1], 'dist' => 0.0, 'far' => false];
                          }
                          $s->drag['dist'] += hypot($d[0], $d[1]);
                          $s->drag['far'] = $s->drag['far'] || $s->drag['dist'] >= 4;
                      }
                      if ($s->drag !== null && $s->drag['far'] && in_array($i, $s->drag['items'], true)) { self::fade($cell, 0.4); }
                  }
                  if ($rt !== null && $s->drag !== null && $s->drag['far']) {
                      $rt->clicked($cell->id); // the release that ends a drag is no click
                  } elseif ($s->selects && $rt !== null) {
                      if ($this->doubleClicked($cell)) { $submitted = true; }
                      if ($this->clicked($cell)) { $changed = $this->gridChoose($s, $i, $n, $rt->clickMods) || $changed; $rt->focusOn($id); }
                  }
              }
            $this->end();
        });
        if ($s->selects) { $grid->focusable = true; }
        if ($s->selects && $rt !== null && $rt->focus === $grid->id && $n > 0) { // the keys: the arrows in two dimensions, Home, End, Enter, the first letters
            $at = $s->selected; $in = $at >= 0 && $at < $n; $to = -1; $extend = false;
            foreach ([[Key::RIGHT, 1], [Key::LEFT, -1], [Key::DOWN, $cols], [Key::UP, -$cols]] as [$key, $d]) {
                $plain = $this->shortcut($key); $shift = $s->selection !== null && $this->shortcut($key, Key::SHIFT);
                if (!$plain && !$shift) { continue; }
                $extend = $shift;
                if (!$in) { $to = 0; }
                elseif ($at + $d >= 0 && $at + $d < $n) { $to = $at + $d; }
                elseif ($d === $cols && intdiv($at, $cols) < intdiv($n - 1, $cols)) { $to = $n - 1; } // the last row, shorter
            }
            if ($this->shortcut(Key::HOME)) { $to = 0; }
            if ($this->shortcut(Key::END)) { $to = $n - 1; }
            if ($s->selection !== null && $this->shortcut('a', Key::COMMAND) && $s->selection->selectAll($n, $s->keyOf(...))) { $changed = true; }
            if ($rt->activated($grid->id) && $in) { $submitted = true; }
            if ($s->label !== null && ($typed = $rt->typed($grid->id)) !== '') {
                $now = hrtime(true) / 1e9;
                if ($now - $s->typedAt >= 1.0) { $s->typed = ''; }
                $s->typed .= $typed; $s->typedAt = $now;
                for ($k = 1; $k <= $n; $k++) { // from the next item, or this one once more than a letter is typed
                    $j = (max($at, 0) + $k - (mb_strlen($s->typed) > 1 ? 1 : 0) + $n) % $n;
                    if (str_starts_with(mb_strtolower(($s->label)($j)), mb_strtolower($s->typed))) { $to = $j; break; }
                }
            }
            if ($to >= 0) {
                if ($extend) {
                    if ($s->selection->pivot < 0 || !$in) { $s->selection->pivot = max($at, 0); $s->selection->span = 0; }
                    $changed = $s->selection->extend($to, $n, $s->keyOf(...)) || $changed;
                    $s->selected = $to;
                } else {
                    $changed = $this->gridChoose($s, $to, $n, 0) || $changed;
                }
                $s->reveal($to);
            }
        }
        $grid->changed = $changed;
        if ($submitted) { $s->rows->submit(); }
        if ($s->drag !== null && $rt !== null) { $this->reorderItems($s, $grid, $n, $cols, $gap); }
        return $grid;
    }

    /** The items of a grid being dragged (GridState::$drag), as reorderRows does a list's rows: the line beside the cell they would go before, the ghost, the scrolling, the drop (mygo's GridState.reorder and dropAt). */
    private function reorderItems(GridState $s, Node $grid, int $n, int $cols, float $gap): void
    {
        $rt = $this->runtime;
        $drag = &$s->drag;
        $x = $rt->input->mouseX; $y = $rt->input->mouseY;
        // The item before which the items dropped go, and the cell of last frame the line goes beside, on which side: the cell under the pointer, or the nearest in its row; below the cells, past the last.
        $best = -1; $dist = INF; $cellK = null; $cellR = null;
        foreach ($grid->kids as $rk => $row) {
            foreach ($row->kids as $ck => $cell) {
                $r = $rt->rectOf($cell->id);
                if ($r === null || $y < $r[1] || $y >= $r[1] + $r[3]) { continue; }
                $d = abs($x - ($r[0] + $r[2] / 2));
                if ($d < $dist) { $best = ($s->rows->first + $rk) * $cols + $ck; $dist = $d; $cellK = [$rk, $ck]; $cellR = $r; }
            }
        }
        if ($best < 0) { $to = $n; $after = true; $last = $n - 1; $cellK = [intdiv($last, $cols) - $s->rows->first, $last % $cols]; }
        else { $after = $x >= $cellR[0] + $cellR[2] / 2; $to = $after ? $best + 1 : $best; }
        if (!$rt->held()) { // dropped: an item already there stays
            if ($drag['far'] && !(count($drag['items']) === 1 && ($to === $drag['items'][0] || $to === $drag['items'][0] + 1))) {
                ($s->reorder)($drag['items'], $to);
            }
            $drag = null;
            return;
        }
        if (!$drag['far']) { return; }
        $grid->drawOver(function (DisplayList $dl, Node $g, Theme $t) use ($cellK, $after, $gap) {
            $cell = $g->kids[$cellK[0]]->kids[$cellK[1]] ?? null;
            if ($cell === null) { return; }
            $lx = $after ? $cell->x + $cell->w + $gap / 2 : $cell->x - $gap / 2;
            $dl->rect($lx - 1, $cell->y + 4, 2, $cell->h - 8, $t->accent, 1.0, 1.0);
        });
        $this->dragScroll($grid, $s->rows, $y);
        $k = $drag['item'] - $s->rows->first * $cols;
        $src = $grid->kids[intdiv($k, $cols)]->kids[$k % $cols] ?? null;
        if ($src !== null && $k >= 0) { $this->dragGhost($src, count($drag['items']), $x - $drag['ox'], $y - $drag['oy']); }
    }

    /**
     * An SVG as an icon, as mygo's Icon: its shapes in the color of the
     * text (or $color), whatever colors the SVG has, as high as the font
     * size (or $size), keeping its aspect ratio. Parse the SVG once, not
     * in the view.
     */
    public function icon(SVG $svg, ?float $size = null, ?int $color = null): Node
    {
        $t = $this->theme;
        $h = $size ?? $t->fontSize;
        $n = $this->box($h * $svg->w / $svg->h, $h, 0); $n->color = null;
        $n->draw(fn(DisplayList $list, Node $a, Theme $t) => Painter::icon($list, $svg, $a->x, $a->y, $a->w, $a->h, $color ?? $t->text, $a->alpha));
        $n->alpha = 0.0; // the box paints nothing itself
        return $n;
    }

    /**
     * A picture of a person or a thing named $name, as mygo's Avatar: the
     * $image cropped to a circle, or the initials of $name on a color it
     * picks from it, 2.25 times the font size high.
     */
    public function avatar(string $name, ?Image $image = null): Node
    {
        $t = $this->theme;
        $size = $t->fontSize * 2.25;
        if ($image !== null) {
            $e = $this->image($image, $size, $size, $size / 2)->tooltip($name);
            $e->cover = true; // Fit(Cover): the shorter side fills the circle, the rest is cropped
            return $e;
        }
        $h = 2166136261; // the hue of the name's FNV-1a hash
        for ($i = 0, $n = strlen($name); $i < $n; $i++) { $h = (($h ^ ord($name[$i])) * 16777619) & 0xffffffff; }
        $e = $this->column(0, 0, 0, self::hsl($h % 360, 0.45, 0.55))->size($size, $size)->round($size / 2)->center()->tooltip($name);
          $this->text(self::initials($name), $size * 0.4, 0xffffff, Font::BOLD)->single(); // FontWeight(600)
        $this->end();
        return $e;
    }

    /** The first letters of the first and last words of $name, upper case. */
    public static function initials(string $name): string
    {
        $first = ''; $last = ''; $inWord = false;
        foreach (mb_str_split($name) as $r) {
            $letter = preg_match('/[\p{L}\p{N}]/u', $r) === 1;
            if ($letter && !$inWord) { if ($first === '') { $first = mb_strtoupper($r); } else { $last = mb_strtoupper($r); } }
            $inWord = $letter;
        }
        return $first . $last;
    }

    /** A color of hue (0..360), saturation and lightness (0..1), mygo's hslColor. */
    public static function hsl(float $h, float $s, float $l): int
    {
        $c = (1 - abs(2 * $l - 1)) * $s; $x = $c * (1 - abs(fmod($h / 60, 2) - 1)); $m = $l - $c / 2;
        [$r, $g, $b] = match (true) { $h < 60 => [$c, $x, 0], $h < 120 => [$x, $c, 0], $h < 180 => [0, $c, $x], $h < 240 => [0, $x, $c], $h < 300 => [$x, 0, $c], default => [$c, 0, $x] };
        return ((int)(($r + $m) * 255) << 16) | ((int)(($g + $m) * 255) << 8) | (int)(($b + $m) * 255);
    }

    /** Chooses item $i of a grid as a click with $mods does (mygo's GridState.click); whether the choice changed. */
    private function gridChoose(GridState $s, int $i, int $n, int $mods): bool
    {
        if ($s->selection !== null) { return $s->selection->click($i, $mods, $n, $s->keyOf(...), $s->selected); }
        if ($s->selected === $i) { return false; }
        $s->selected = $i;
        return true;
    }

    /** mygo's matching: the options starting with $query (ignoring case) first, then those containing it; all for an empty query. */
    public static function matching(array $options, string $query): array
    {
        $q = strtolower(trim($query));
        if ($q === '') { return $options; }
        $starts = []; $contains = [];
        foreach ($options as $o) {
            $l = strtolower($o);
            if (str_starts_with($l, $q)) { $starts[] = $o; } elseif (str_contains($l, $q)) { $contains[] = $o; }
        }
        return array_merge($starts, $contains);
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
          $this->box($glass, $glass, 0)->at($pad, ($n->fh - $glass) / 2)->draw(self::magnifier(...))->alpha = 0.0;
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

    /** The magnifying glass of a field for searching, as mygo's (MingCute's search icon, Apache 2.0): a ring and a handle stroked 2 on its 24 grid, in the muted color. */
    private static function magnifier(DisplayList $list, Node $g, Theme $t): void
    {
        $k = $g->w / 24; $stroke = 2 * $k;
        $list->ring($g->x + 2 * $k, $g->y + 2 * $k, 12 * $k, 12 * $k, $t->textMuted, 1.0, 6 * $k, $stroke);
        $list->line($g->x + 14.5 * $k, $g->y + 14.5 * $k, $g->x + 20 * $k, $g->y + 20 * $k, $t->textMuted, 1.0, $stroke);
    }

    /**
     * A bar for finding text while $open, as mygo's FindBar after Safari's
     * and Xcode's: a field of $query, which takes the focus as the bar
     * opens with what was found before selected, how many $matches there
     * are, previous and next buttons choosing $current among them, and
     * Done. Enter in the field goes to the next match, Shift+Enter to the
     * previous, as do Cmd+G and Shift+Cmd+G; Escape and Done close the
     * bar. The app finds: it counts the matches of $query and shows match
     * $current, which changed() reports moving. Closed, it builds nothing
     * and returns null.
     */
    public function findBar(bool &$open, TextState $query, int $matches, int &$current): ?Node
    {
        $t = $this->theme;
        $rt = $this->runtime;
        $bar = $this->key('find bar')->row(0, $t->space(2), 0, $t->surface)->padding($t->space(1.5), $t->space(3))->align(Node::CENTER);
        $make = fn() => (object)['opening' => true]; // its state goes as it closes: true in the frame it opens
        $local = $rt?->local($bar->id, $make) ?? $make();
        if (!$open) { // nothing: an empty box out of the flow, keeping the bar's key for its state to start over as it opens
            $local->opening = true;
            $bar->at(0, 0)->size(0, 0); $bar->color = null; $bar->padding(0, 0);
            $this->end();
            return null;
        }
        $bar->draw(function (DisplayList $list, Node $b, Theme $t) { $list->rect($b->x, $b->y + $b->h - 1, $b->w, 1, $t->border); }); // BorderWidth(0, 0, 1, 0)
        $current = max(0, min($current, $matches - 1));
        $step = function (int $d) use (&$current, $matches, $bar) { if ($matches > 0) { $current = ($current + $d + $matches) % $matches; $bar->changed = true; } };
        if ($this->shortcut('g', Key::COMMAND)) { $step(1); } elseif ($this->shortcut('g', Key::SHIFT | Key::COMMAND)) { $step(-1); }
          // The field: mygo's field() with a magnifier and the input, grown to at most Space(90).
          $glass = $t->space(3.75);
          $f = $this->row(0, $t->space(1.5), 1, $t->surface)->padding($t->space(1.5), $t->space(2.5))->round($t->radius)->border()->align(Node::CENTER)->cursor('ibeam');
            $this->box($glass, $glass, 0)->draw(self::magnifier(...))->alpha = 0.0;
            [, $h] = $this->metrics->measure('X', $t->fontSize);
            $in = $this->add(new Node(Node::INPUT, 0, 0, 0, 0, $h, 1, 'Find', null, $t->fontSize, $t->text));
            $in->edit = $query; $in->inset(-Runtime::FIELD_INSET, -Runtime::FIELD_INSET); $in->min($t->space(25), 0);
            $in->focused = $rt?->focused($in->id) ?? false;
          $this->end();
          $fw = $rt?->rectOf($bar->id)[2] ?? 0.0;
          if ($fw > 0) { $f->size(min($t->space(90), $fw), 0); $f->grow = 0; } // MaxWidth(Space(90)): at most that of the bar's width last frame
          $f->borderColor = $in->focused ? $t->accent : ($this->hovered($f) ? Theme::mix($t->border, $t->text, 0.25) : null);
          if ($this->clicked($f) && $rt !== null) { $rt->focusOn($in->id); $in->focused = true; }
          if ($local->opening && $rt !== null) { $local->opening = false; $rt->focusOn($in->id); $in->focused = true; $query->selectAll(); }
          if ($query->submitted) { $query->submitted = false; $step(($rt?->submitMods() ?? 0) & Key::SHIFT ? -1 : 1); }
          if ($in->focused && $this->shortcut(Key::ESCAPE)) { $open = false; }
          $status = $query->text === '' ? '' : ($matches > 0 ? ($current + 1) . ' of ' . $matches : 'No matches');
          $this->row(0, 0)->min($t->space(18), 0); $this->text($status, $t->fontSize, $t->textMuted); $this->end();
          foreach ([-1, 1] as $d) { // previous and next: a chevron up or down, Space(3), stroked 1.5
              $btn = $this->key("step$d")->buttonFace($t->space(1.5), $t->space(2));
              if ($matches === 0) { $btn->alpha = 0.5; $btn->focusable = false; }
                $this->box($t->space(3), $t->space(3), 0)->draw(function (DisplayList $list, Node $a, Theme $t) use ($d) {
                    $cx = $a->x + $a->w / 2; $cy = $a->y + $a->h / 2; $k = $a->w / 4; $s = $d < 0 ? 1 : -1;
                    $list->line($cx - $k, $cy + $s * $k / 2, $cx, $cy - $s * $k / 2, $t->text, 1.0, 1.5);
                    $list->line($cx, $cy - $s * $k / 2, $cx + $k, $cy + $s * $k / 2, $t->text, 1.0, 1.5);
                })->alpha = 0.0;
              $this->end();
              if ($matches > 0 && $this->clicked($btn)) { $step($d); }
          }
          $done = $this->button('Done');
          if ($this->clicked($done)) { $open = false; }
        $this->end();
        return $bar;
    }

    /**
     * A column of the fields $fn builds (field, fieldset), gap Space(3),
     * whose labels sit in a column of their own beside the controls, as
     * macOS's forms: aligned to the right, as wide as the widest, and
     * centered on their control's first child (mygo levels them with its
     * first line).
     */
    public function form(Closure $fn): Node
    {
        $f = $this->column(0, $this->theme->space(3));
        $saved = $this->form; $this->form = (object)['labels' => [], 'width' => 0.0];
          $fn();
          foreach ($this->form->labels as $box) { $box->size($this->form->width, $box->fh); } // as wide as the widest, measured as they were built
        $this->form = $saved;
        $this->end();
        return $f;
    }

    /**
     * A field, as mygo's Field: $label above the control $fn builds, or
     * beside it in a form; a click on the label focuses the control (or
     * clicks a check box, switch or radio button). $description shows
     * below the control muted, $error in the danger color, which an input
     * then borders in. The control is the first focusable $fn builds.
     */
    public function field(string $label, Closure $fn, string $description = '', string $error = ''): Node
    {
        $t = $this->theme;
        $rt = $this->runtime;
        $inForm = $this->form !== null;
        $f = $inForm ? $this->row(0, $t->space(3))->align(Node::START) : $this->column(0, $t->space(1.5));
          if ($inForm) {
              [$lw] = $this->metrics->measure($label, $t->fontSize);
              $this->form->width = max($this->form->width, $lw);
              $box = $this->row(0, 0)->justify(Node::END)->align(Node::CENTER); $this->form->labels[] = $box;
                $l = $this->text($label, $t->fontSize, $t->text);
              $this->end();
          } else {
              $l = $this->text($label, $t->fontSize, $t->text, Font::BOLD); // FontWeight(500): bold, the one weight besides regular
          }
          $content = $this->column(0, $t->space(1.5), $inForm ? 1 : 0); // in a form: the rest of the row
            $fn();
            if ($description !== '') { $this->text($description, $t->fontSize - 1, $t->textMuted); }
            if ($error !== '') { $this->text($error, $t->fontSize - 1, $t->danger); }
          $this->end();
        $this->end();
        $ctrl = self::fieldControl($content);
        if ($ctrl === null) { return $f; }
        if ($inForm) { $box->fh = $rt?->rectOf($ctrl->id)[3] ?? 0.0; } // level with the control: as high as it was last frame
        if ($error !== '' && $ctrl->kind === Node::INPUT) { $ctrl->borderColor = $t->danger; }
        $l->cursor = 'arrow';
        if ($this->clicked($l) && $rt !== null) {
            $to = self::focusIn($ctrl);
            if ($to !== null) { $rt->focusOn($to->id); }
            if (in_array($ctrl->kind, [Node::CHECKBOX, Node::SWITCH, Node::RADIO], true)) { $rt->press($ctrl->id); } // clicked next frame, as mygo's clickLater
        }
        return $f;
    }

    /** The control of a field: the first node inside $e that takes the focus, or a group of them (a focus group). */
    private static function fieldControl(Node $e): ?Node
    {
        foreach ($e->kids as $k) {
            if ($k->focusGroup !== 0) { return $k; }
            if ($k->focusable || $k->kind === Node::BUTTON || $k->kind === Node::INPUT || $k->kind === Node::TEXTAREA || $k->kind === Node::SLIDER) { return $k; }
            if (($c = self::fieldControl($k)) !== null) { return $c; }
        }
        return null;
    }

    /** What a click on the label of a field focuses: its control, or in a group of them, the one on, else the first. */
    private static function focusIn(Node $e): ?Node
    {
        if ($e->focusable || $e->kind === Node::BUTTON || $e->kind === Node::INPUT || $e->kind === Node::TEXTAREA || $e->kind === Node::SLIDER) { return $e; }
        $first = null; $chosen = null;
        $walk = function (Node $e) use (&$walk, &$first, &$chosen) {
            foreach ($e->kids as $k) {
                if ($chosen !== null) { return; }
                if ($k->focusable || $k->kind === Node::BUTTON) { $first ??= $k; if ($k->on) { $chosen = $k; } }
                $walk($k);
            }
        };
        $walk($e);
        return $chosen ?? $first;
    }

    /**
     * A group of the fields $fn builds under $legend (bold), as mygo's
     * Fieldset: gap Space(3), and Space(3) apart from what is above it, as
     * a section. In a form, its fields' labels line up with the others'.
     */
    public function fieldset(string $legend, Closure $fn): Node
    {
        $t = $this->theme;
        $parent = $this->stack[count($this->stack) - 1];
        $above = $parent->kids !== [];
        if ($above) { $this->column(0, 0)->size(0, $t->space(3)); $this->end(); } // Margin(Space(3), 0, 0, 0)
        $g = $this->column(0, $t->space(3));
          $this->text($legend, $t->fontSize, $t->text, Font::BOLD); // FontWeight(600)
          $fn();
        $this->end();
        return $g;
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
        $back = $this->key('modal')->column(0, 0, 0, 0x000000)->at(0, 0)->size($this->width, $this->height)->center()->block(); // keyed: it comes and goes
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
        $n->bordered = true;
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
