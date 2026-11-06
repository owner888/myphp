<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use MyPHP\UI\{Context, Node};

/**
 * VStack and HStack, as SwiftUI's: the views given (the second
 * parentheses) one after the other down or across, $spacing apart (8 by
 * default), lined up by $alignment across; a Spacer among them takes the
 * room left, so the stack fills its container along its axis.
 */
final class Stack extends View
{
    /** @var list<mixed> */
    private array $content = [];

    public function __construct(public readonly int $axis, public readonly int $align, public readonly ?float $spacing) {}

    /** The views of the stack. */
    public function __invoke(View|array|null ...$content): static
    {
        $this->content = $content;
        return $this;
    }

    protected function build(Context $c, Env $env): ?Node
    {
        $gap = $this->spacing ?? 8.0;
        $n = $this->axis === Node::COLUMN ? $c->column(0, $gap) : $c->row(0, $gap);
        $n->alignItems($this->align);
        View::renderAll($c, $env->along($this->axis), $this->content);
        $c->end();
        View::takeRoom($n, $env->axis, View::flexOfKids($n)); // as SwiftUI's: a Spacer, a list or a stack inside that takes the room makes the stack take it
        return $n;
    }
}
