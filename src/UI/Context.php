<?php
declare(strict_types=1);

namespace MyPHP\UI;

/** Builds a frame's tree. The view function calls these in order, as code. */
final class Context
{
    public Node $root;
    public int $count = 0;
    /** @var Node[] */
    private array $stack;

    public function __construct()
    {
        $this->root = new Node(Node::CONTAINER, Node::COLUMN, 0, 0, 0, 0, 0, null, 0);
        $this->stack = [$this->root];
    }

    private function add(Node $n): Node
    {
        $this->stack[count($this->stack) - 1]->kids[] = $n;
        $this->count++;
        return $n;
    }

    public function column(float $pad = 0, float $gap = 0, int $grow = 0, int $color = 0): Node
    {
        $n = $this->add(new Node(Node::CONTAINER, Node::COLUMN, $pad, $gap, 0, 0, $grow, null, $color));
        $this->stack[] = $n;
        return $n;
    }

    public function row(float $pad = 0, float $gap = 0, int $grow = 0, int $color = 0): Node
    {
        $n = $this->add(new Node(Node::CONTAINER, Node::ROW, $pad, $gap, 0, 0, $grow, null, $color));
        $this->stack[] = $n;
        return $n;
    }

    public function end(): void
    {
        array_pop($this->stack);
    }

    /** Text size is an estimate until CoreText shaping is wired in. */
    public function text(string $s, float $size = 14): Node
    {
        return $this->add(new Node(Node::TEXT, 0, 0, 0, strlen($s) * $size * 0.55, $size * 1.3, 0, $s, 0));
    }

    public function button(string $s): Node
    {
        return $this->add(new Node(Node::BUTTON, 0, 8, 0, strlen($s) * 8 + 24, 28, 0, $s, 0x3377ff));
    }

    public function box(float $w, float $h, int $color): Node
    {
        return $this->add(new Node(Node::BOX, 0, 0, 0, $w, $h, 0, null, $color));
    }
}
