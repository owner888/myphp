<?php
declare(strict_types=1);

namespace MyPHP\UI\Shader\Syntax;

/** A struct type. (go/ast's StructType) @internal */
final class StructType implements Expr
{
    public function __construct(
        /** Where it starts, in bytes into the source. */
        public int $pos,
        /** @var list<Field> */
        public array $fields,
    ) {}
}
