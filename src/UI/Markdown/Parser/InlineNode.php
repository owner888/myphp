<?php
declare(strict_types=1);

namespace MyPHP\UI\Markdown\Parser;

/** An inline while the inline parser builds the tree (mygo's inode). @internal */
final class InlineNode
{
    public string $dest = '', $title = '';
    public int $parent = -1, $first = -1, $last = -1, $prev = -1, $next = -1;
    public bool $auto = false, $display = false;
    /** A text node others may merge into. */
    public bool $plain = false;
    /** A plain node merged into whose $text is not yet the subject's bytes it spans, which InlineParser::text() makes it: PHP copies a substring, which Go slices. */
    public bool $lazy = false;

    public function __construct(public int $kind, public string $text, public int $start, public int $end) {}
}
