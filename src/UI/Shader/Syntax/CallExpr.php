<?php
declare(strict_types=1);

namespace MyPHP\UI\Shader\Syntax;

/** A call, or a conversion: $fun($args), $ellipsis where its last argument is followed by ... (-1 for none), $rparen at its closing parenthesis. (go/ast's CallExpr) @internal */
final class CallExpr implements Expr
{
    public function __construct(
        /** Where it starts, in bytes into the source. */
        public int $pos,
        public Expr $fun,
        /** @var list<Expr> */
        public array $args,
        public int $ellipsis,
        public int $rparen,
    ) {}
}
