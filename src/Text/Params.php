<?php
declare(strict_types=1);

namespace MyPHP\Text;

/** A text to lay out (mygo's text.Params). */
final class Params
{
    // The horizontal alignment of the lines of a layout (mygo's Align).
    /** Left, or right in right-to-left paragraphs. */
    public const START = 0;
    public const CENTER = 1;
    public const END = 2;
    /** Left and right whatever the lines' direction, as the start and the end of an interface laid out right to left. */
    public const LEFT = 3, RIGHT = 4;

    private ?string $key = null;

    /** @param list<Span> $spans */
    public function __construct(
        public readonly string $text,
        public readonly Style $style = new Style(),
        /** Wraps lines to this many points; 0 or less only breaks lines at newlines. */
        public readonly float $width = 0,
        /** Truncates the text to that many lines, ending it with $ellipsis ("…" when empty); 0 is unlimited. */
        public readonly int $maxLines = 0,
        public readonly string $ellipsis = '',
        /** Breaks lines only at newlines, aligning them within $width all the same. */
        public readonly bool $noWrap = false,
        public readonly int $align = self::START,
        /**
         * Makes paragraphs without a strongly directional character, as
         * numbers or an empty line, right to left, as in an interface laid
         * out right to left; the others read in the direction of their first
         * strongly directional character.
         */
        public readonly bool $rtl = false,
        /** Keeps the advance of whitespace at the end of wrapped lines, as text editors do. */
        public readonly bool $keepSpaces = false,
        /** Only breaks lines between words, letting a long word overflow the width instead of breaking it. */
        public readonly bool $noBreakWords = false,
        /** Styles runs of the text apart from $style. */
        public readonly array $spans = [],
    ) {}

    /** What tells parameters apart, for the cache of layouts. */
    public function key(): string
    {
        if ($this->key === null) {
            $k = $this->style->key() . "\1" . $this->width . "\1" . $this->maxLines . "\1" . $this->ellipsis . "\1" . (int)$this->noWrap . (int)$this->rtl . (int)$this->keepSpaces . (int)$this->noBreakWords . $this->align;
            foreach ($this->spans as $sp) {
                $k .= "\2" . $sp->key();
            }
            $this->key = $k . "\3" . $this->text;
        }
        return $this->key;
    }
}
