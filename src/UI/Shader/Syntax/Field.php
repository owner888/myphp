<?php
declare(strict_types=1);

namespace MyPHP\UI\Shader\Syntax;

/** A parameter, a result or a struct's field: its names, none for an unnamed one, and its type. (go/ast's Field) @internal */
final class Field
{
    public function __construct(
        /** Where it starts, in bytes into the source. */
        public int $pos,
        /** @var list<Ident> */
        public array $names,
        public Expr $type,
    ) {}
}
