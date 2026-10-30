<?php
declare(strict_types=1);

namespace MyPHP\UI\Shader\Syntax;

/** ..., in an array type's length. (go/ast's Ellipsis) @internal */
final class Ellipsis implements Expr
{
    public function __construct(
        /** Where it starts, in bytes into the source. */
        public int $pos,
        public ?Expr $elt,
    ) {}
}
