<?php
declare(strict_types=1);

namespace MyPHP\UI\Shader\Syntax;

/** A type spec: its name, and the type it names or aliases. (go/ast's TypeSpec) @internal */
final class TypeSpec
{
    public function __construct(
        /** Where it starts, in bytes into the source. */
        public int $pos,
        public Ident $name,
        public Expr $type,
        public bool $alias,
    ) {}
}
