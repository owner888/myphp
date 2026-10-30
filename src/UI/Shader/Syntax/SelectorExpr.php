<?php
declare(strict_types=1);

namespace MyPHP\UI\Shader\Syntax;

/** An expression and a name after a dot: a swizzle, as v.xy. (go/ast's SelectorExpr) @internal */
final class SelectorExpr implements Expr
{
    public function __construct(
        /** Where it starts, in bytes into the source. */
        public int $pos,
        public Expr $x,
        public Ident $sel,
    ) {}
}
