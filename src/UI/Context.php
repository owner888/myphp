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

    /** $width and $height are the room the tree will be laid out in. */
    public function __construct(private TextMetrics $metrics, public ?Runtime $runtime = null, public float $width = 0, public float $height = 0)
    {
        $this->root = new Node(Node::CONTAINER, Node::COLUMN, 0, 0, 0, 0, 0, null, null);
        $this->stack = [$this->root];
    }

    private function add(Node $n): Node
    {
        $this->stack[count($this->stack) - 1]->kids[] = $n;
        $n->id = ++$this->count;
        return $n;
    }

    /** Whether the node just built was clicked (a mouse-up on where it was last frame). */
    public function clicked(Node $n): bool
    {
        return $this->runtime?->clicked($n->id) ?? false;
    }

    public function hovered(Node $n): bool
    {
        return $this->runtime?->hovered($n->id) ?? false;
    }

    public function column(float $pad = 0, float $gap = 0, int $grow = 0, ?int $color = null): Node
    {
        $n = $this->add(new Node(Node::CONTAINER, Node::COLUMN, $pad, $gap, 0, 0, $grow, null, $color));
        $this->stack[] = $n;
        return $n;
    }

    public function row(float $pad = 0, float $gap = 0, int $grow = 0, ?int $color = null): Node
    {
        $n = $this->add(new Node(Node::CONTAINER, Node::ROW, $pad, $gap, 0, 0, $grow, null, $color));
        $this->stack[] = $n;
        return $n;
    }

    public function end(): void
    {
        array_pop($this->stack);
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
        $state->count = $count;
        $state->row = min($state->row, max(0, $count - 1));
        $node = $this->column(0, 0, 1)->scroll($state);
        $state->first = $state->row;
        $y = -$state->offset;
        $limit = max($state->viewport, $this->height) * 1.5;
        for ($i = $state->row; $i < $count && $y < $limit; $i++) {
            $row($i);
            $y += $state->height($i);
        }
        $this->end();
        return $node;
    }

    public function text(string $s, float $size = 14, int $color = 0x1d1d1f): Node
    {
        [$w, $h] = $this->metrics->measure($s, $size);
        return $this->add(new Node(Node::TEXT, 0, 0, 0, $w, $h, 0, $s, null, $size, $color));
    }

    public function button(string $s, float $size = 13): Node
    {
        [$w, $h] = $this->metrics->measure($s, $size);
        return $this->add(new Node(Node::BUTTON, 0, 0, 0, $w + 24, $h + 10, 0, $s, 0x3377ff, $size, 0xffffff));
    }

    public function box(float $w, float $h, int $color): Node
    {
        return $this->add(new Node(Node::BOX, 0, 0, 0, $w, $h, 0, null, $color));
    }
}
