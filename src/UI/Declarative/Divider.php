<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use MyPHP\UI\{Context, Node};

/** Divider, as SwiftUI's: a hairline across a VStack, or down an HStack. */
final class Divider extends View
{
    protected function build(Context $c, Env $env): ?Node
    {
        $vertical = $env->stackAxis === Node::ROW;
        $n = $c->divider($vertical);
        if ($vertical === ($env->axis === Node::ROW)) { $n->alignSelf(Node::STRETCH); } else { $n->grow = 1; } // across its container, or along it in a frame's box
        return $n;
    }
}
