<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use MyPHP\UI\{Context, Node};

/** A row of a 2D Grid, as SwiftUI's GridRow. */
final class GridRow extends View
{
    /** @var list<mixed> */
    public array $cells = [];

    public function __construct(public readonly ?\VerticalAlignment $alignment = null) {}

    public function __invoke(View|array|null ...$cells): static
    {
        $this->cells = $cells;
        return $this;
    }

    protected function build(Context $c, Env $env): ?Node
    {
        $row = $c->row(0, 8);
          View::renderAll($c, $env->along(Node::ROW), $this->cells);
        $c->end();
        return $row;
    }
}