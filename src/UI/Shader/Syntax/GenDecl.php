<?php
declare(strict_types=1);

namespace MyPHP\UI\Shader\Syntax;

/** A var, const, type or import declaration, $tok saying which, of one spec or of several in parentheses. (go/ast's GenDecl) @internal */
final class GenDecl implements Decl
{
    public function __construct(
        /** Where it starts, in bytes into the source. */
        public int $pos,
        public string $tok,
        /** @var list<ValueSpec|TypeSpec|ImportSpec> */
        public array $specs,
    ) {}
}
