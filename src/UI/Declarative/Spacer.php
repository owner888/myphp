<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use MyPHP\UI\{Context, Node};

/** Spacer, as SwiftUI's: takes the room the stack it is in has left, at least $minLength. */
final class Spacer extends View
{
    public function __construct(public readonly ?float $minLength = null) {}

    protected function build(Context $c, Env $env): ?Node
    {
        $n = $c->spacer();
        if ($this->minLength !== null) { $env->axis === Node::ROW ? $n->minWidth($this->minLength) : $n->minHeight($this->minLength); }
        return $n;
    }
}
