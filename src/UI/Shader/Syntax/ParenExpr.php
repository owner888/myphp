<?php
declare(strict_types=1);

namespace MyPHP\UI\Shader\Syntax;

/** A parenthesized expression. (go/ast's ParenExpr) @internal */
final class ParenExpr implements Expr
{
    public function __construct(
        /** Where it starts, in bytes into the source. */
        public int $pos,
        public Expr $x,
    ) {}
}
