<?php
declare(strict_types=1);

namespace MyPHP\UI\Shader\Syntax;

/** A var or const spec: names, a type or null, and values. (go/ast's ValueSpec) @internal */
final class ValueSpec
{
    public function __construct(
        /** Where it starts, in bytes into the source. */
        public int $pos,
        /** @var list<Ident> */
        public array $names,
        public ?Expr $type,
        /** @var list<Expr> */
        public array $values,
    ) {}
}
