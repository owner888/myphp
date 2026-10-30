<?php
declare(strict_types=1);

namespace MyPHP\UI\Shader\Syntax;

/** A composite literal, $type{$elts}. (go/ast's CompositeLit) @internal */
final class CompositeLit implements Expr
{
    public function __construct(
        /** Where it starts, in bytes into the source. */
        public int $pos,
        public ?Expr $type,
        /** @var list<Expr> */
        public array $elts,
    ) {}
}
