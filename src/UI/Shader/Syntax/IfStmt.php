<?php
declare(strict_types=1);

namespace MyPHP\UI\Shader\Syntax;

/** An if statement: $else an IfStmt or a BlockStmt, or null. (go/ast's IfStmt) @internal */
final class IfStmt implements Stmt
{
    public function __construct(
        /** Where it starts, in bytes into the source. */
        public int $pos,
        public ?Stmt $init,
        public Expr $cond,
        public BlockStmt $body,
        public ?Stmt $else,
    ) {}
}
