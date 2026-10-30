<?php
declare(strict_types=1);

namespace MyPHP\UI\Shader\Syntax;

/** A binary operation, $x $op $y, at $x. (go/ast's BinaryExpr) @internal */
final class BinaryExpr implements Expr
{
    public function __construct(
        /** Where it starts, in bytes into the source. */
        public int $pos,
        public Expr $x,
        public string $op,
        public Expr $y,
        public int $opPos,
    ) {}
}
