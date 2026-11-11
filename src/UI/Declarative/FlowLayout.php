<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use MyPHP\UI\{Context, Node};

/**
 * FlowLayout, a wrapping horizontal container for tags, chips and filters:
 * lays out its children left-to-right with $spacing and wraps onto new rows
 * with $lineSpacing when a row is full.
 */
final class FlowLayout extends View
{
    /** @var list<mixed> */
    private array $content = [];

    public function __construct(
        public readonly float $spacing = 8,
        public readonly ?float $lineSpacing = null,
        public readonly \HorizontalAlignment $alignment = \HorizontalAlignment::leading,
    ) {}

    public function __invoke(View|array|null ...$content): static
    {
        $this->content = $content;
        return $this;
    }

    protected function build(Context $c, Env $env): ?Node
    {
        $n = $c->row(0, $this->spacing);
        $rect = $c->runtime?->rectOf($n->id);
        $w = $rect !== null && $rect[2] > 0 ? $rect[2] : 360.0;
        $n->wrap($w)
            ->gap($this->spacing, $this->lineSpacing ?? $this->spacing)
            ->justify(match ($this->alignment) {
                \HorizontalAlignment::leading => Node::START,
                \HorizontalAlignment::center => Node::CENTER,
                \HorizontalAlignment::trailing => Node::END,
            });
          View::renderAll($c, $env->along(Node::ROW), $this->content);
        $c->end();
        View::takeRoom($n, $env->axis, View::FLEX_X);
        return $n;
    }
}