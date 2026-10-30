<?php
declare(strict_types=1);

namespace MyPHP\UI\Shader\Syntax;

/** break, continue, goto or fallthrough, $label or null. (go/ast's BranchStmt) @internal */
final class BranchStmt implements Stmt
{
    public function __construct(
        /** Where it starts, in bytes into the source. */
        public int $pos,
        public string $tok,
        public ?Ident $label,
    ) {}
}
