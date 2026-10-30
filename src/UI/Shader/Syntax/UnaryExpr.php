<?php
declare(strict_types=1);

namespace MyPHP\UI\Shader\Syntax;

/** A unary operation, $op $x. (go/ast's UnaryExpr) @internal */
final class UnaryExpr implements Expr
{
    public function __construct(
        /** Where it starts, in bytes into the source. */
        public int $pos,
        public string $op,
        public Expr $x,
    ) {}
}
