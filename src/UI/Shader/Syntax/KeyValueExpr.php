<?php
declare(strict_types=1);

namespace MyPHP\UI\Shader\Syntax;

/** A key and its value in a composite literal. (go/ast's KeyValueExpr) @internal */
final class KeyValueExpr implements Expr
{
    public function __construct(
        /** Where it starts, in bytes into the source. */
        public int $pos,
        public Expr $key,
        public Expr $value,
    ) {}
}
