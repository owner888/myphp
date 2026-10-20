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
    public ?bool $strike = null;
    public ?float $spacing = null;
    public ?string $features = null;
    public ?string $lang = null;
    public ?int $decoColor = null;
    public ?float $decoThick = null;
    public ?int $background = null;
    public ?int $selection = null;

    /** This style over $base: what this leaves null, $base's. */
    public function over(?self $base): self
    {
        if ($base === null) {
            return $this;
        }
        $s = clone $base;
        foreach (get_object_vars($this) as $k => $v) {
            if ($v !== null && $k !== 'fixedLine') {
                $s->$k = $v;
                if ($k === 'lineHeight') { $s->fixedLine = $this->fixedLine; }
            }
        }
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
            || $this->lineHeight !== null || ($this->align ?? 0) !== 0 || $this->wavy || $this->strike
            || ($this->spacing ?? 0.0) != 0 || ($this->features ?? '') !== '' || $this->lang !== null
            || $this->decoColor !== null || $this->decoThick !== null || $this->background !== null;
    }

    /** The engine's style of this one, $size for none. */
    public function style(float $size): Style
    {
        $size = $this->size ?? $size;
        $lh = $this->lineHeight === null ? 0.0 : ($this->fixedLine ? $this->lineHeight / max($size, 1e-6) : $this->lineHeight);
        return new Style($this->family ?? '', $size, $this->weight ?? 0, $this->italic ?? false, $lh, $this->spacing ?? 0, $this->features ?? '', $this->lang ?? '');
    }
}
