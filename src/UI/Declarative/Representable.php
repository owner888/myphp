<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use Closure;
use MyPHP\UI\{Context, Node};

/**
 * SwiftUI's NSViewRepresentable: a view another part of myphp builds into
 * the context — a plugin's game, terminal or player, an editor of a large
 * buffer — ($make(Context): Node), among the declarative views, its
 * modifiers applying to the node it makes. $fills: it takes the room it is
 * given, as a game or a player does.
 */
final class Representable extends View
{
    /** @param Closure(Context): Node $make */
    public function __construct(public readonly Closure $make, public readonly bool $fills = false) {}

    protected function build(Context $c, Env $env): ?Node
    {
        $n = ($this->make)($c);
        $env->font?->apply($n);
        if ($env->mono) { $n->font('monospace'); }
        if ($this->fills) { View::takeRoom($n, $env->axis, View::FLEX_X | View::FLEX_Y); }
        return $n;
    }
}
