<?php
declare(strict_types=1);

namespace MyPHP\UI;

use Closure;

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
     * character when a word is wider) and at newlines; without one it is
     * one line. The node is as wide as its widest line.
     * @param Span[] $spans
     */
    public function rich(array $spans, ?float $size = null, ?int $color = null, ?float $width = null): Node
    {
        $size ??= $this->theme->fontSize;
        $layout = RichLayout::wrap($this->metrics, $spans, $size, $width ?? INF);
        $n = $this->add(new Node(Node::RICH, 0, 0, 0, $layout->w, $layout->h, 0, null, null, $size, $color ?? $this->theme->text));
        $n->spans = $spans;
        $n->rich = $layout;
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
     * A text area editing $state: wraps at $width, scrolls to keep the caret
     * in view, Enter inserts a line, Up/Down move by wrapped lines, Cmd+Z undoes.
     */
    public function textarea(TextState $state, string $placeholder = '', float $width = 320, float $height = 120, ?float $size = null): Node
    {
        $t = $this->theme;
        $state->multiline = true;
        $n = $this->add(new Node(Node::TEXTAREA, 0, 0, 0, $width, $height, 0, $placeholder, $t->background, $size ?? $t->fontSize, $t->text));
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
