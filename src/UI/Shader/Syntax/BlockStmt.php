<?php
declare(strict_types=1);

namespace MyPHP\UI\Shader\Syntax;

/** A block: its statements, $rbrace at its closing brace. (go/ast's BlockStmt) @internal */
final class BlockStmt implements Stmt
{
    public function __construct(
        /** Where it starts, in bytes into the source. */
        public int $pos,
        /** @var list<Stmt> */
        public array $list,
        public int $rbrace,
    ) {}
}
