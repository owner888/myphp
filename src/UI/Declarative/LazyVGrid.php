<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use MyPHP\UI\{Context, Node, Track};

/**
 * LazyVGrid, as SwiftUI's with an adaptive column (GridItem::adaptive):
 * as many columns as fit cells $minimum wide at least in the width it is
 * given (as it was laid out the frame before), sharing the width left over,
 * its views laid out in them row by row, $spacing apart.
 */
final class LazyVGrid extends View
{
    /** @var list<mixed> */
    private array $content = [];

    /** @param list<GridItem> $columns */
    public function __construct(public readonly array $columns, public readonly float $spacing = 8) {}

    public function __invoke(View|array|null ...$content): static
    {
        $this->content = $content;
        return $this;
    }

    protected function build(Context $c, Env $env): ?Node
    {
        $min = $this->columns[0]->minimum ?? throw new \LogicException('LazyVGrid takes an adaptive column: LazyVGrid(columns: [GridItem::adaptive(minimum: 132)])');
        $width = $c->runtime?->rectOf($c->upcomingId())[2] ?? $c->width;
        $columns = max(1, (int)floor(($width + $this->spacing) / ($min + $this->spacing)));
        $n = $c->grid(array_fill(0, $columns, Track::fr()), $this->spacing);
          foreach (ListView::flat($this->content) as $v) {
              $v->render($c, $env->along(Node::ROW))?->justifySelf(Node::STRETCH);
          }
        $c->end();
        View::takeRoom($n, $env->axis, View::FLEX_X);
        return $n;
    }
}
