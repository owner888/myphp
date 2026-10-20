<?php
declare(strict_types=1);

namespace MyPHP\Text;

/** A positioned glyph (mygo's text.Glyph). */
final class Glyph
{
    public function __construct(
        public ?Font $font,
        public int $id,
        /** The font size in points. */
        public float $size,
        /** The glyph's drawing origin, shaping offsets included, and its baseline. */
        public float $x,
        public float $y,
        /** How far it moves the pen, apart from those offsets. */
        public float $advance,
        /** The first rune of the glyph's cluster, and how many runes the cluster holds; the ellipsis's glyphs hold 0. */
        public int $cluster,
        public int $runes = 0,
        public bool $rtl = false,
        /** The box of an inline object (Span::$objectWidth), which draws no glyph: what shows there is the caller's. */
        public bool $object = false,
    ) {}
}
