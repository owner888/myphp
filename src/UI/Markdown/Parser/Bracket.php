<?php
declare(strict_types=1);

namespace MyPHP\UI\Markdown\Parser;

/** An opening bracket the inline parser may close into a link (mygo's bracket). @internal */
final class Bracket
{
    public bool $bracketAfter = false;

    public function __construct(
        public int $node,
        public int $prev,
        public int $prevDelim,
        public int $index,
        public bool $image,
        public bool $active,
    ) {}
}
