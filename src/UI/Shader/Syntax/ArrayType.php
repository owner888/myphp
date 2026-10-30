<?php
declare(strict_types=1);

namespace MyPHP\UI\Shader\Syntax;

/** An array type, [$len]$elt: $len an Ellipsis for [...], null for a slice. (go/ast's ArrayType) @internal */
final class ArrayType implements Expr
{
    public function __construct(
        /** Where it starts, in bytes into the source. */
        public int $pos,
        public ?Expr $len,
        public Expr $elt,
    ) {}
}
