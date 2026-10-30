<?php
declare(strict_types=1);

namespace MyPHP\UI\Shader\Syntax;

/** A return statement. (go/ast's ReturnStmt) @internal */
final class ReturnStmt implements Stmt
{
    public function __construct(
        /** Where it starts, in bytes into the source. */
        public int $pos,
        /** @var list<Expr> */
        public array $results,
    ) {}
}
