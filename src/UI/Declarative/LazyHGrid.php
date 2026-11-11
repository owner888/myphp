<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use MyPHP\UI\{Context, Node, Track};

/** LazyHGrid, as SwiftUI's: views arranged in rows of $rows GridItems across columns. */
final class LazyHGrid extends View
{
    /** @var list<mixed> */
    private array $content = [];

    /** @param list<GridItem> $rows */
    public function __construct(
        public readonly array $rows,
        public readonly float $spacing = 8,
    ) {}

    public function __invoke(View|array|null ...$content): static
    {
        $this->content = $content;
        return $this;
    }

    protected function build(Context $c, Env $env): ?Node
    {
        $items = ListView::flat($this->content);
        $rowCount = max(1, count($this->rows));
        $colCount = max(1, (int)ceil(count($items) / $rowCount));
        $n = $c->grid(array_fill(0, $colCount, Track::fit()), $this->spacing);
        $cellEnv = $env->along(Node::COLUMN);
        foreach ($items as $idx => $v) {
            $r = ($idx % $rowCount) + 1;
            $col = intdiv($idx, $rowCount) + 1;
            $k = $v->render($c, $cellEnv);
            $k?->rowStart($r)->columnStart($col);
        }
        $c->end();
        View::takeRoom($n, $env->axis, View::flexOfKids($n));
        return $n;
    }
}