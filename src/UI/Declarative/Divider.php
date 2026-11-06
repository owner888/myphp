<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use MyPHP\UI\{Context, Node};

/** Divider, as SwiftUI's: a hairline across a VStack, or down an HStack. */
final class Divider extends View
{
    protected function build(Context $c, Env $env): ?Node
    {
        $n = $c->divider($env->axis === Node::ROW);
        $n->alignSelf(Node::STRETCH);
        return $n;
    }
}
