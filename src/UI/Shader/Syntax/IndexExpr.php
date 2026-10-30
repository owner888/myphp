<?php
declare(strict_types=1);

namespace MyPHP\UI\Shader\Syntax;

/** An expression indexed, $x[$index]. (go/ast's IndexExpr) @internal */
final class IndexExpr implements Expr
{
    public function __construct(
        /** Where it starts, in bytes into the source. */
        public int $pos,
        public Expr $x,
        public Expr $index,
    ) {}
}
