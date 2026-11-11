<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use MyPHP\UI\{Context, Node};

/** LazyHStack, as SwiftUI's: views laid out horizontally in a ScrollView(Axis::horizontal). */
final class LazyHStack extends View
{
    /** @var list<mixed> */
    private array $content = [];

    public function __construct(
        public readonly \VerticalAlignment $alignment = \VerticalAlignment::center,
        public readonly float $spacing = 8,
    ) {}

    public function __invoke(View|array|null ...$content): static
    {
        $this->content = $content;
        return $this;
    }

    protected function build(Context $c, Env $env): ?Node
    {
        $align = match ($this->alignment) {
            \VerticalAlignment::top => Node::START,
            \VerticalAlignment::bottom => Node::END,
            \VerticalAlignment::firstTextBaseline, \VerticalAlignment::lastTextBaseline => Node::BASELINE,
            default => Node::CENTER,
        };
        $n = $c->row(0, $this->spacing)->alignItems($align);
          View::renderAll($c, $env->along(Node::ROW), $this->content);
        $c->end();
        View::takeRoom($n, $env->axis, View::flexOfKids($n));
        return $n;
    }
}