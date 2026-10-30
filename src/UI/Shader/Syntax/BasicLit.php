<?php
declare(strict_types=1);

namespace MyPHP\UI\Shader\Syntax;

/** A literal: $kind one of Token::INT, FLOAT, IMAG, CHAR, STRING, and its text. (go/ast's BasicLit) @internal */
final class BasicLit implements Expr
{
    public function __construct(
        /** Where it starts, in bytes into the source. */
        public int $pos,
        public string $kind,
        public string $value,
    ) {}
}
