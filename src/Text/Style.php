<?php
declare(strict_types=1);

namespace MyPHP\Text;

/** The font of a text (mygo's text.Style). */
final class Style
{
    public function __construct(
        /** A comma-separated list of font families, where "system-ui" and "monospace" are the system's own; "" is the system UI font. */
        public readonly string $family = '',
        /** In points; 0 is 14. */
        public readonly float $size = 0,
        /** From 100 (thin) to 900 (black); 0 is 400. */
        public readonly int $weight = 0,
        public readonly bool $italic = false,
        /** The height of a line as a multiple of the size; 0 takes the font's own line spacing. */
        public readonly float $lineHeight = 0,
        /** Points added after every character; negative tightens. */
        public readonly float $letterSpacing = 0,
        /** OpenType features, comma separated: a tag turns one on, and tag=value sets it, as "tnum, liga=0, salt=2". */
        public readonly string $features = '',
        /** The language of the text, a BCP 47 tag as "ja" or "zh-Hant"; "" is the system's (System::setLanguage). */
        public readonly string $lang = '',
    ) {}

    public function weight(): int
    {
        return $this->weight <= 0 ? 400 : min($this->weight, 999);
    }

    /** The size in points. */
    public function fontSize(): float
    {
        return $this->size <= 0 ? 14.0 : $this->size;
    }

    /** What tells styles apart, for caches. */
    public function key(): string
    {
        return $this->family . "\0" . $this->size . "\0" . $this->weight . "\0" . (int)$this->italic . "\0" . $this->lineHeight . "\0" . $this->letterSpacing . "\0" . $this->features . "\0" . $this->lang;
    }

    /** A copy with what $with sets. @param array<string, mixed> $with */
    public function with(array $with): self
    {
        return new self(...array_merge(get_object_vars($this), $with));
    }
}
