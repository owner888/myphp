<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use MyPHP\UI\Node;

/** A font, as SwiftUI's Font: a text style (Font::title…), which follows the system's text size, or a size of its own (Font::system). */
final class FontSpec
{
    public function __construct(
        /** A text style of MyPHP\UI\Font (Font::LARGE_TITLE…), or null for $size. */
        public readonly ?string $style = null,
        public readonly ?float $size = null,
        public readonly ?\Weight $weight = null,
        public readonly bool $mono = false,
    ) {}

    /** Gives $n this font. @internal */
    public function apply(Node $n): void
    {
        if ($this->style !== null) { $n->preferredFont($this->style); }
        if ($this->size !== null) { $n->fontSize($this->size); }
        if ($this->weight !== null) { $n->fontWeight($this->weight->value); }
        if ($this->mono) { $n->font('monospace'); }
    }
}
