<?php
declare(strict_types=1);

namespace MyPHP\UI\Shader\Syntax;

/** A statement with a label. (go/ast's LabeledStmt) @internal */
final class LabeledStmt implements Stmt
{
    public function __construct(
        /** Where it starts, in bytes into the source. */
        public int $pos,
        public Ident $label,
        public Stmt $stmt,
    ) {}
}
