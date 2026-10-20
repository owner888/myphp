<?php
declare(strict_types=1);

namespace MyPHP\Text;

/**
 * Styles the runes of a text before $end, after those of the spans before
 * it (mygo's text.Span). What it sets replaces the Style of the layout;
 * italic only turns italics on.
 */
final class Span
{
    public function __construct(
        public readonly int $end,
        public readonly string $family = '',
        public readonly float $size = 0,
        public readonly int $weight = 0,
        public readonly bool $italic = false,
        public readonly float $letterSpacing = 0,
        public readonly string $features = '',
        /** The language of the runes, as Style::$lang; "" keeps the text's. */
        public readonly string $lang = '',
        /**
         * When $objectWidth is set, each rune of the span, an object
         * replacement character (U+FFFC), is an inline object: a box that
         * wide, standing that high on the baseline, which lines break
         * around and which draws no glyph (Glyph::$object).
         */
        public readonly float $objectWidth = 0,
        public readonly float $objectHeight = 0,
    ) {}

    /** The style of the span's runes in a text of style $base. */
    public function style(Style $base): Style
    {
        $with = [];
        if ($this->family !== '') { $with['family'] = $this->family; }
        if ($this->size > 0) { $with['size'] = $this->size; }
        if ($this->weight > 0) { $with['weight'] = $this->weight; }
        if ($this->italic) { $with['italic'] = true; }
        if ($this->letterSpacing != 0) { $with['letterSpacing'] = $this->letterSpacing; }
        if ($this->features !== '') { $with['features'] = $this->features; }
        if ($this->lang !== '') { $with['lang'] = $this->lang; }
        return $with === [] ? $base : $base->with($with);
    }

    /** A copy ending at $end. */
    public function until(int $end): self
    {
        return new self($end, $this->family, $this->size, $this->weight, $this->italic, $this->letterSpacing, $this->features, $this->lang, $this->objectWidth, $this->objectHeight);
    }

    public function key(): string
    {
        return $this->end . "\0" . $this->family . "\0" . $this->size . "\0" . $this->weight . "\0" . (int)$this->italic . "\0" . $this->letterSpacing . "\0" . $this->features . "\0" . $this->lang . "\0" . $this->objectWidth . "\0" . $this->objectHeight;
    }

    /**
     * The spans of runes $start to $end of a text, with their ends relative to $start (mygo's spansIn).
     * @param list<Span> $spans
     * @return list<Span>
     */
    public static function in(array $spans, int $start, int $end): array
    {
        $out = [];
        $from = 0;
        foreach ($spans as $sp) {
            $s0 = $from;
            $from = max($sp->end, $from);
            if ($from <= $start) {
                continue;
            }
            if ($s0 >= $end) {
                break;
            }
            $out[] = $sp->until(min($from, $end) - $start);
        }
        return $out;
    }
}
