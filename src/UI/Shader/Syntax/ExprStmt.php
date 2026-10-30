<?php
declare(strict_types=1);

namespace MyPHP\UI\Shader\Syntax;

/** An expression standing as a statement. (go/ast's ExprStmt) @internal */
final class ExprStmt implements Stmt
{
    public function __construct(
        /** Where it starts, in bytes into the source. */
        public int $pos,
        public Expr $x,
    ) {}
}
