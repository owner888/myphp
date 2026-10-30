<?php
declare(strict_types=1);

namespace MyPHP\UI\Shader\Syntax;

/** for $key, $value := range $x: $tok ":=", "=" or "" for neither. (go/ast's RangeStmt) @internal */
final class RangeStmt implements Stmt
{
    public function __construct(
        /** Where it starts, in bytes into the source. */
        public int $pos,
        public ?Expr $key,
        public ?Expr $value,
        public string $tok,
        public Expr $x,
        public BlockStmt $body,
    ) {}
}
