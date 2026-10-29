<?php
declare(strict_types=1);

namespace MyPHP\UI;

/**
 * A run of a rich text with a style of its own, as mygo's Span: what it
 * leaves null takes the style of the text around it, and $italic,
 * $underline, $wavyUnderline and $strikethrough only turn those on.
 *
 *     $c->richText([new Span('Saved '), new Span('report.pdf', weight: 600), new Span(' to '),
 *               new Span('Documents', color: $t->accent, underline: true)]);
 */
final class Span
{
    public function __construct(
        public string $text,
        public ?float $size = null,
        /** Font::BOLD (a weight of 700), ITALIC and MONO (the monospaced family), over $weight, $italic and $font. */
        public int $style = 0,
        public ?int $color = null,
        public bool $underline = false,
        public bool $strikethrough = false,
        /** Highlights the span, as search results are. */
        public ?int $background = null,
        /** A family, as Node::font(). */
        public ?string $font = null,
        /** From 100 to 900. */
        public ?int $weight = null,
        public bool $italic = false,
        /** The opacity of $color, and of $background unless $backgroundAlpha sets its own. */
        public ?float $alpha = null,
        /** Rounds the corners of the background, which then hugs the span's glyphs, a little wider than them, rather than filling the line, as code within text shows. */
        public float $radius = 0,
        public bool $wavyUnderline = false,
        /** Those of the span's underline and strikethrough, as Node::decorationColor(). */
        public ?int $decorationColor = null,
        public ?float $decorationThickness = null,
        public ?float $letterSpacing = null,
        /** As Node::fontFeatures(), comma separated. */
        public ?string $features = null,
        /** The language, as Node::lang(). */
        public ?string $lang = null,
        /**
         * When $objectWidth is set, each rune of the span, an object
         * replacement character (U+FFFC), is an inline object: a box of that
         * size standing on the baseline, which lines break around and which
         * draws nothing, where a rich text editor draws an image and a
         * text what Node::drawObjects draws. $objectDescent extends the box
         * that far below the baseline, as math's subscripts and fractions
         * go: the line makes room for it (mygo's ObjectDescent).
         */
        public float $objectWidth = 0,
        public float $objectHeight = 0,
        public float $objectDescent = 0,
        /** Raises the span's text, or its objects, that many points above the baseline, or lowers it when negative, as superscripts and subscripts with a smaller $size: footnote references, exponents, chemical formulas. The line makes room for it, and its underline, strikethrough and background go with it (mygo's BaselineShift). */
        public float $baselineShift = 0,
        /** The opacity of $background, as a code span's tint. */
        public ?float $backgroundAlpha = null,
        /** @internal VideoPlayer (ASS's runs drawn apart): laid out, but nothing of it painted, its glyphs, outline, shadow, background or lines. */
        public bool $ghost = false,
    ) {}

    /** The span over $base: what it leaves unset, $base's (mygo's over). */
    public function over(self $base): self
    {
        $s = clone $this;
        $s->font ??= $base->font;
        $s->size ??= $base->size;
        $s->weight ??= $base->weight;
        $s->color ??= $base->color;
        $s->alpha ??= $base->alpha;
        $s->decorationColor ??= $base->decorationColor;
        $s->decorationThickness ??= $base->decorationThickness;
        $s->background ??= $base->background;
        $s->backgroundAlpha ??= $base->backgroundAlpha;
        $s->letterSpacing ??= $base->letterSpacing;
        $s->features ??= $base->features;
        $s->lang ??= $base->lang;
        $s->style |= $base->style;
        $s->italic = $s->italic || $base->italic;
        $s->underline = $s->underline || $base->underline;
        $s->wavyUnderline = $s->wavyUnderline || $base->wavyUnderline;
        $s->strikethrough = $s->strikethrough || $base->strikethrough;
        return $s;
    }

    /** The engine's span of this one, ending at rune $end. */
    public function textSpan(int $end): \MyPHP\Text\Span
    {
        $weight = $this->weight ?? (($this->style & Font::BOLD) !== 0 ? 700 : 0);
        $family = $this->font ?? (($this->style & Font::MONO) !== 0 ? 'monospace' : '');
        return new \MyPHP\Text\Span($end, $family, $this->size ?? 0, $weight, $this->italic || ($this->style & Font::ITALIC) !== 0,
            $this->letterSpacing ?? 0, $this->features ?? '', $this->lang ?? '', $this->objectWidth, $this->objectHeight, $this->objectDescent, $this->baselineShift);
    }
}
