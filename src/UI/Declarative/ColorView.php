<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use MyPHP\UI\{Context, Node};

/** A color as a view, as SwiftUI's Color in a stack: it fills the room it is given. */
final class ColorView extends View
{
    public function __construct(public readonly ColorValue $color) {}

    protected function build(Context $c, Env $env): ?Node
    {
        $n = $c->column(0, 0, 1, $this->color->rgb($env));
        $c->end();
        $n->alignSelf(Node::STRETCH);
        if ($this->color->alpha < 1) { $n->background($this->color->rgb($env), $this->color->alpha); }
        return $n;
    }
}
