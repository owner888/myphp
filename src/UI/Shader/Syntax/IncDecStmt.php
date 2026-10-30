<?php
declare(strict_types=1);

namespace MyPHP\UI\Shader\Syntax;

/** $x++ or $x--. (go/ast's IncDecStmt) @internal */
final class IncDecStmt implements Stmt
{
    public function __construct(
        /** Where it starts, in bytes into the source. */
        public int $pos,
        public Expr $x,
        public string $tok,
    ) {}
}
