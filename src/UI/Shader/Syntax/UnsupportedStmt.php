<?php
declare(strict_types=1);

namespace MyPHP\UI\Shader\Syntax;

/** A statement a shader cannot have, as switch, go or defer: its kind of node in go/ast (SwitchStmt, GoStmt...). (go/ast's Stmt) @internal */
final class UnsupportedStmt implements Stmt
{
    public function __construct(
        /** Where it starts, in bytes into the source. */
        public int $pos,
        public string $what,
    ) {}
}
