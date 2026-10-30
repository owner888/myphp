<?php
declare(strict_types=1);

namespace MyPHP\UI\Shader\Syntax;

/** A construct of Go a shader cannot have, as a pointer, a map or a function literal: its kind of node in go/ast (StarExpr, MapType, FuncLit...). (go/ast's Expr) @internal */
final class Unsupported implements Expr
{
    public function __construct(
        /** Where it starts, in bytes into the source. */
        public int $pos,
        public string $what,
    ) {}
}
