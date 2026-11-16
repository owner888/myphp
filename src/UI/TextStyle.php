<?php
declare(strict_types=1);

namespace MyPHP\UI;

use MyPHP\Text\Style;

/**
 * The text styling a node sets, which the text inside it inherits (mygo's
 * textStyle): what it leaves null comes from the nodes around it, then the
 * theme. Node's fontSize(), font(), fontWeight(), italic(), textColor()…
 * set it.
 */
final class TextStyle
{
    public ?string $family = null;
    public ?float $size = null;
    public ?int $weight = null;
    public ?bool $italic = null;
    /** 0xRRGGBB, and its opacity. */
    public ?int $color = null;
    public ?float $alpha = null;
    /** A multiple of the font size, or points when $fixedLine. */
    public ?float $lineHeight = null;
    public bool $fixedLine = false;
    /** Node::START, CENTER or END. */
    public ?int $align = null;
    public ?bool $underline = null;
    public ?bool $wavy = null;
    /** An underline of dots (Node::dottedUnderline). */
    public ?bool $dotted = null;
    public ?bool $strike = null;
    public ?float $spacing = null;
    public ?string $features = null;
    public ?string $lang = null;
    public ?int $decoColor = null;
    public ?float $decoThick = null;
    public ?int $background = null;
    public ?int $selection = null;
    public ?float $selectionAlpha = null;
    /** Node::textOutline: its width in points, color and opacity. @var ?array{0: float, 1: int, 2: float} */
    public ?array $outline = null;
    /** Node::textShadow: its offset, color and opacity. @var ?array{0: float, 1: float, 2: int, 3: float} */
    public ?array $textShadow = null;
    /** Node::textBlur: its sigma in points. */
    public ?float $blur = null;
    /** Node::textCutOut: whether the outline, and the shadow, have the glyphs taken out of them. @var ?array{0: bool, 1: bool} */
    public ?array $cutOut = null;

    /** This style over $base: what this leaves null, $base's. */
    public function over(?self $base): self
    {
        if ($base === null) {
            return $this;
        }
        $s = clone $base;
        if ($this->family !== null) { $s->family = $this->family; }
        if ($this->size !== null) { $s->size = $this->size; }
        if ($this->weight !== null) { $s->weight = $this->weight; }
        if ($this->italic !== null) { $s->italic = $this->italic; }
        if ($this->color !== null) { $s->color = $this->color; }
        if ($this->alpha !== null) { $s->alpha = $this->alpha; }
        if ($this->lineHeight !== null) { $s->lineHeight = $this->lineHeight; $s->fixedLine = $this->fixedLine; }
        if ($this->align !== null) { $s->align = $this->align; }
        if ($this->underline !== null) { $s->underline = $this->underline; }
        if ($this->wavy !== null) { $s->wavy = $this->wavy; }
        if ($this->dotted !== null) { $s->dotted = $this->dotted; }
        if ($this->strike !== null) { $s->strike = $this->strike; }
        if ($this->spacing !== null) { $s->spacing = $this->spacing; }
        if ($this->features !== null) { $s->features = $this->features; }
        if ($this->lang !== null) { $s->lang = $this->lang; }
        if ($this->decoColor !== null) { $s->decoColor = $this->decoColor; }
        if ($this->decoThick !== null) { $s->decoThick = $this->decoThick; }
        if ($this->background !== null) { $s->background = $this->background; }
        if ($this->selection !== null) { $s->selection = $this->selection; }
        if ($this->selectionAlpha !== null) { $s->selectionAlpha = $this->selectionAlpha; }
        if ($this->outline !== null) { $s->outline = $this->outline; }
        if ($this->textShadow !== null) { $s->textShadow = $this->textShadow; }
        if ($this->blur !== null) { $s->blur = $this->blur; }
        if ($this->cutOut !== null) { $s->cutOut = $this->cutOut; }
        return $s;
    }

    /**
     * Whether text in this style needs laying out by the text system, which
     * text() measures without: anything but a size, a color, an underline,
     * italics, a weight of 400 or 700 and the system's or the monospaced
     * family.
     */
    public function needsLayout(): bool
    {
        return ($this->family !== null && $this->family !== 'monospace' && $this->family !== 'system-ui')
            || ($this->weight !== null && $this->weight !== 400 && $this->weight !== 700)
            || $this->lineHeight !== null || ($this->align ?? 0) !== 0 || $this->wavy || $this->dotted || $this->strike
            || ($this->spacing ?? 0.0) != 0 || ($this->features ?? '') !== '' || $this->lang !== null
            || $this->decoColor !== null || $this->decoThick !== null || $this->background !== null || $this->outline !== null || $this->textShadow !== null || $this->blur !== null || $this->cutOut !== null;
    }

    /** The engine's style of this one, $size for none. */
    public function style(float $size): Style
    {
        $size = $this->size ?? $size;
        $lh = $this->lineHeight === null ? 0.0 : ($this->fixedLine ? $this->lineHeight / max($size, 1e-6) : $this->lineHeight);
        return new Style($this->family ?? '', $size, $this->weight ?? 0, $this->italic ?? false, $lh, $this->spacing ?? 0, $this->features ?? '', $this->lang ?? '');
    }
}
