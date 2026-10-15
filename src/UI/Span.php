<?php
declare(strict_types=1);

namespace MyPHP\UI;

/**
 * A run of a rich text with a style of its own, as mygo's Span: what it
 * leaves null takes the text's size and color.
 *
 *     $c->rich([new Span('Saved '), new Span('report.pdf', style: Font::BOLD), new Span(' to '),
 *               new Span('Documents', color: $t->accent, underline: true)]);
 */
final class Span
{
    public function __construct(
        public string $text,
        public ?float $size = null,
        /** Font::BOLD | Font::ITALIC | Font::MONO */
        public int $style = 0,
        public ?int $color = null,
        public bool $underline = false,
        public bool $strikethrough = false,
        /** Highlights the span, as search results are. */
        public ?int $background = null,
    ) {}
}
