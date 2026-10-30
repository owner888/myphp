<?php
declare(strict_types=1);

namespace MyPHP\UI\Shader\Syntax;

/** An import spec: the path, quoted. (go/ast's ImportSpec) @internal */
final class ImportSpec
{
    public function __construct(
        /** Where it starts, in bytes into the source. */
        public int $pos,
        public string $path,
    ) {}
}
