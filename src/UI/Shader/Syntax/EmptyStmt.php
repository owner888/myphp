<?php
declare(strict_types=1);

namespace MyPHP\UI\Shader\Syntax;

/** A statement of nothing. (go/ast's EmptyStmt) @internal */
final class EmptyStmt implements Stmt
{
    public function __construct(
        /** Where it starts, in bytes into the source. */
        public int $pos,
    ) {}
}
