<?php
declare(strict_types=1);

namespace MyPHP\UI\Shader\Syntax;

/** A source file: its package's name and its declarations. (go/ast's File) @internal */
final class File
{
    public function __construct(
        /** Where it starts, in bytes into the source. */
        public int $pos,
        public Ident $name,
        /** @var list<FuncDecl|GenDecl> */
        public array $decls,
    ) {}
}
