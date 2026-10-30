<?php
declare(strict_types=1);

namespace MyPHP\UI\Shader\Syntax;

/** A function declaration: $recv for a method, null for a function; $body null for one without. (go/ast's FuncDecl) @internal */
final class FuncDecl implements Decl
{
    public function __construct(
        /** Where it starts, in bytes into the source. */
        public int $pos,
        /** @var ?list<Field> */
        public ?array $recv,
        public Ident $name,
        public FuncType $type,
        public ?BlockStmt $body,
    ) {}
}
