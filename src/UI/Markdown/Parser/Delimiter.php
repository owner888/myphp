<?php
declare(strict_types=1);

namespace MyPHP\UI\Markdown\Parser;

/** A run of delimiters the inline parser may match (mygo's delim). @internal */
final class Delimiter
{
    public function __construct(
        public string $c,
        public int $n,
        public int $orig,
        public int $node,
        public int $prev,
        public int $next,
        public bool $canOpen,
        public bool $canClose,
        public int $at,
    ) {}
}
