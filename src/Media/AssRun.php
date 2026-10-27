<?php
declare(strict_types=1);

namespace MyPHP\Media;

/**
 * A run of an ASS line in one style, as its style and its override tags
 * make it: sizes in the script's PlayRes units, colors 0xRRGGBB with an
 * alpha of 0 (clear) to 1. A text of "\n" alone is a line's break (\N).
 */
final class AssRun
{
    public function __construct(
        public readonly string $text,
        public readonly string $font,
        public readonly float $size,
        /** 400 regular, 700 bold, or the weight \b gave. */
        public readonly int $weight,
        public readonly bool $italic,
        public readonly bool $underline,
        public readonly bool $strikeout,
        public readonly int $color,
        public readonly float $alpha,
        public readonly int $outlineColor,
        public readonly float $outlineAlpha,
        public readonly int $shadowColor,
        public readonly float $shadowAlpha,
        /** The outline's width and the shadow's offset, in PlayRes units. */
        public readonly float $border,
        public readonly float $shadow,
        public readonly float $spacing,
        /** 1, an outline and a shadow; 3, an opaque box of the outline's color behind the text. */
        public readonly int $borderStyle,
        /** \fscx and \fscy as parts (1 for 100 %), and \frz in degrees, counterclockwise. */
        public readonly float $scaleX = 1.0,
        public readonly float $scaleY = 1.0,
        public readonly float $rotation = 0.0,
    ) {}
}
