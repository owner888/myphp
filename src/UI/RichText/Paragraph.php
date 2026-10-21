<?php
declare(strict_types=1);

namespace MyPHP\UI\RichText;

/** A paragraph of a Text: its style and its runs, without its newline (mygo's richtext.Paragraph). */
final class Paragraph
{
    /** @param list<Run> $runs */
    public function __construct(
        public ParagraphStyle $style = new ParagraphStyle,
        public array $runs = [],
    ) {}
}
