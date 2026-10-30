<?php
declare(strict_types=1);

namespace MyPHP\UI\Shader\Syntax;

/** A name. (go/ast's Ident) @internal */
final class Ident implements Expr
{
    public function __construct(
        /** Where it starts, in bytes into the source. */
        public int $pos,
        public string $name,
    ) {}
}
