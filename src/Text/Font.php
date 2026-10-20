<?php
declare(strict_types=1);

namespace MyPHP\Text;

/**
 * A font of the system at a size (mygo's text.Font): layouts and glyphs
 * refer to fonts by the object, the engine giving the same for the same
 * font and size; $id is the engine's key of it, which rasterizes its glyphs.
 */
final class Font
{
    public function __construct(
        /** The em size in points. */
        public readonly float $size,
        /** The font's vertical metrics in points. */
        public readonly float $ascent,
        public readonly float $descent,
        public readonly float $lineGap,
        public readonly int $id = 0,
        /** Where the font's underline and strikethrough center, in points below the baseline (above it for a negative one), and their thickness. */
        public readonly float $underlineCenter = 0,
        public readonly float $underlineThick = 0,
        public readonly float $strikeCenter = 0,
        public readonly float $strikeThick = 0,
    ) {}
}
