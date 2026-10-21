<?php
declare(strict_types=1);

namespace MyPHP\UI\RichText;

/**
 * The formats of a document's selection (mygo's richtext.Formatting), as
 * a toolbar and a Format menu show them: of the runes selected, or of the
 * typing attributes at a caret.
 */
final class Formatting
{
    public function __construct(
        /** Those of the first rune selected, or the typing attributes at a caret. */
        public Attributes $attributes = new Attributes,
        public FormatState $bold = FormatState::FormatOff,
        public FormatState $italic = FormatState::FormatOff,
        public FormatState $underline = FormatState::FormatOff,
        public FormatState $strikethrough = FormatState::FormatOff,
        public FormatState $code = FormatState::FormatOff,
        /** The link all of the selection is in, '' when none is. */
        public string $link = '',
        /** The style of the first paragraph selected, and whether others differ from it. */
        public ParagraphStyle $paragraph = new ParagraphStyle,
        public bool $paragraphsMixed = false,
    ) {}
}
