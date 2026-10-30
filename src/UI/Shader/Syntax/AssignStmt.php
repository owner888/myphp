<?php
declare(strict_types=1);

namespace MyPHP\UI\Shader\Syntax;

/** An assignment or a short declaration: $tok "=", ":=" or an operator's assignment ("+="). (go/ast's AssignStmt) @internal */
final class AssignStmt implements Stmt
{
    public function __construct(
        /** Where it starts, in bytes into the source. */
        public int $pos,
        /** @var list<Expr> */
        public array $lhs,
        public string $tok,
        /** @var list<Expr> */
        public array $rhs,
    ) {}
}
