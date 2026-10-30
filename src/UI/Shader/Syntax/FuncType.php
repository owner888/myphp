<?php
declare(strict_types=1);

namespace MyPHP\UI\Shader\Syntax;

/** A function's parameters and results. (go/ast's FuncType) @internal */
final class FuncType
{
    public function __construct(
        /** Where it starts, in bytes into the source. */
        public int $pos,
        /** @var list<Field> */
        public array $params,
        /** @var ?list<Field> null when it has none */
        public ?array $results,
    ) {}
}
