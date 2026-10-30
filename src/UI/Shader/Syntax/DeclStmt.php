<?php
declare(strict_types=1);

namespace MyPHP\UI\Shader\Syntax;

/** A declaration in a function. (go/ast's DeclStmt) @internal */
final class DeclStmt implements Stmt
{
    public function __construct(
        /** Where it starts, in bytes into the source. */
        public int $pos,
        public GenDecl $decl,
    ) {}
}
