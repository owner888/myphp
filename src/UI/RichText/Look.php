<?php
declare(strict_types=1);

namespace MyPHP\UI\RichText;

use Closure;
use MyPHP\UI\Span;

/**
 * How the text of an editor looks (mygo's richtext look): its font and the
 * theme's colors, set as it builds.
 *
 * @internal
 */
final class Look
{
    /** The room of the paragraph being laid out, which attachments shrink to. */
    public float $width = 0;

    /**
     * @param array{0: int, 1: float} $border
     * @param ?Closure(string, Span): void $tagStyle
     */
    public function __construct(
        public Theme $theme,
        public float $size,
        /** Points a level of indentation takes. */
        public float $indent,
        /** Around an attachment not loaded yet. */
        public array $border,
        public ?Closure $tagStyle,
    ) {}

    /** The style of headings of $level, from 1. */
    public function heading(int $level): HeadingStyle
    {
        return $this->theme->headings[min(max($level, 1), 6) - 1];
    }

    /** How a run of $text with attributes $a looks in a paragraph of style $st. */
    public function span(string $text, Attributes $a, ParagraphStyle $st): Span
    {
        $th = $this->theme;
        $s = new Span($text, size: $a->size > 0 ? $a->size : null, font: $a->font !== '' ? $a->font : null, weight: $a->weight > 0 ? $a->weight : null,
            italic: $a->italic, underline: $a->underline, strikethrough: $a->strikethrough);
        $set = function (?array $c) use ($s): void { $s->color = $c[0]; $s->alpha = $c[1]; };
        if ($a->color !== null) { $set($a->color); }
        if ($a->background !== null) { $s->background = $a->background[0]; $s->backgroundAlpha = $a->background[1]; }
        if ($a->code) {
            $s->font = $th->codeFont;
            $s->size ??= $this->size * $th->codeSize;
            if ($s->background === null) { $s->background = $th->codeBackground[0]; $s->backgroundAlpha = $th->codeBackground[1]; }
            // A pill hugging the code, as Markdown views show it.
            $s->radius = max(3, $s->size / 4);
        }
        if ($a->link !== '') {
            if ($s->color === null) { $set($th->link); }
            $s->underline = true;
        }
        if ($st->list === ListKind::Tasks && $st->checked) {
            $s->strikethrough = true;
            if ($s->color === null) { $set($th->muted); }
        }
        if ($st->quote && $s->color === null) { $set($th->quoteText); }
        if (($a->code || $st->codeBlock) && $s->color === null && $th->code !== $th->text) { $set($th->code); }
        if ($st->heading > 0 && $s->color === null && ($c = $this->heading($st->heading)->color) !== $th->text) { $set($c); }
        if ($a->tag !== '' && $this->tagStyle !== null) { ($this->tagStyle)($a->tag, $s); }
        if (($at = $a->attachment) !== null) {
            // A box of the attachment's size, scaled down to the width.
            [$w, $h] = $at->size();
            if ($this->width > 0 && $w > $this->width) {
                [$w, $h] = [$this->width, $h * $this->width / $w];
            }
            $s->objectWidth = $w; $s->objectHeight = $h;
        }
        return $s;
    }
}
