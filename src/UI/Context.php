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
     * wraps at the width the layout gave this node last frame (the
     * window's width the first time, and a frame follows when it differs);
     * otherwise it is one line. The node is as wide as its widest line.
     * @param Span[] $spans
     */
    public function rich(array $spans, ?float $size = null, ?int $color = null, ?float $width = null, bool $wrap = false): Node
    {
        $size ??= $this->theme->fontSize;
        $n = $this->add(new Node(Node::RICH, 0, 0, 0, 0, 0, 0, null, null, $size, $color ?? $this->theme->text));
        if ($wrap && $width === null) {
            $width = $this->runtime?->widthOf($n->id) ?? $this->width;
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
        $n->underline = true;
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

    /** Secondary text, in the theme's muted color. */
    public function muted(string $s, ?float $size = null): Node
    {
        return $this->text($s, $size, $this->theme->textMuted);
    }

    /** A primary button: accent face, lighter hovered, darker pressed. */
    public function button(string $s, ?float $size = null): Node
    {
        $t = $this->theme;
        $size ??= $t->fontSize;
        [$w, $h] = $this->metrics->measure($s, $size);
        $n = $this->add(new Node(Node::BUTTON, 0, 0, 0, $w + $t->space(6), $h + $t->space(2.5), 0, $s, $t->accent, $size, $t->accentText));
        $n->focused = $this->runtime?->focused($n->id) ?? false;
        if ($this->runtime?->hovered($n->id)) {
            $n->color = $this->runtime->input->mouseDown ? $t->accentPressed : $t->accentHover;
        }
        return $n;
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
        $n = $this->add(new Node(Node::SWITCH, 0, 0, 0, $w + $t->space(9.5), max($h, $t->space(5)), 0, $label, null, $size, $t->text));
        $n->focusable = true;
        if ($this->runtime?->clicked($n->id)) {
            $on = !$on;
            $n->changed = true;
        }
        $n->on = $on;
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
          $track = $this->row($t->space(0.5), $t->space(0.5), 0, $t->surface)->round($t->radius - 1)->align(Node::STRETCH);
          foreach ($options as $i => $label) {
              $seg = $this->column(0, 0, 0, $i === $index ? $t->surfacePressed : null)->round($t->radius - 1)->focusable()->center();
              if ($i !== $index && $this->hovered($seg)) { $seg->color = $t->surfaceHover; }
              [$w, $h] = $this->metrics->measure($label, $t->fontSize);
              $seg->size($w + $t->space(4), $h + $t->space(1.5));
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
        $n = $this->row(0, $t->space(4));
        foreach ($labels as $i => $label) {
            $tab = $this->column(0, $t->space(1))->focusable();
            $this->text($label, $t->fontSize, $i === $index ? $t->text : ($this->hovered($tab) ? $t->text : $t->textMuted), $i === $index ? Font::BOLD : 0);
            $this->box(0, 2, $t->accent)->alpha = $i === $index ? 1.0 : 0.0; // the underline, kept for the height
            $this->end();
            if ($this->clicked($tab) && $i !== $index) {
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
        $head = $this->row(0, $t->space(1.5))->focusable();
        $this->text($open ? '▾' : '▸', $t->fontSize, $t->textMuted);
        $this->text($title, $t->fontSize, $t->text, Font::BOLD);
        $this->end();
        if ($this->clicked($head)) {
            $open = !$open;
            $head->changed = true;
        }
        return $open;
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
        $w = $width ?? max(array_map(fn($o) => $this->metrics->measure($o, $size)[0], $options ?: [''])) + $t->space(10);
        [, $h] = $this->metrics->measure('X', $size);
        $n = $this->add(new Node(Node::SELECT, 0, 0, 0, $w, $h + $t->space(2.5), 0, $label, $t->surface, $size, $t->text));
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
        if ($rt?->clicked($n->id)) {
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
     * keyboard; the text input client of the window then edits $state.
     */
    public function input(TextState $state, string $placeholder = '', float $width = 200, ?float $size = null): Node
    {
        $t = $this->theme;
        $size ??= $t->fontSize;
        [, $h] = $this->metrics->measure('X', $size);
        $n = $this->add(new Node(Node::INPUT, 0, 0, 0, $width, $h + $t->space(3), 0, $placeholder, $t->background, $size, $t->text));
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
        $n = $this->add(new Node(Node::TEXTAREA, 0, 0, 0, $width, $height, $width <= 0 || $height <= 0 ? 1 : 0, $placeholder, $t->background, $size ?? $t->fontSize, $t->text));
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
