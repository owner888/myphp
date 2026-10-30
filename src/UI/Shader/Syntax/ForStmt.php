<?php
declare(strict_types=1);

namespace MyPHP\UI\Shader\Syntax;

/** A for statement of a condition, or of three clauses: each null when left out. (go/ast's ForStmt) @internal */
final class ForStmt implements Stmt
{
    public function __construct(
        /** Where it starts, in bytes into the source. */
        public int $pos,
        public ?Stmt $init,
        public ?Expr $cond,
        public ?Stmt $post,
        public BlockStmt $body,
    ) {}
}
