<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Terminal;

/** The font of a terminal's text, with what Ghostty's font options set (mygo's terminal.Font). */
final class Font
{
    /** @param list<string> $features */
    public function __construct(
        /** The font family, a comma-separated list as Node::font() takes it: "monospace", the system's, when empty. */
        public string $family = '',
        /** The size in points: 13 when 0. */
        public float $size = 0,
        /** How heavy text is, from 100 (thin) to 900 (black): 400 when 0. Bold text is 300 heavier, and at least 700. */
        public int $weight = 0,
        /**
         * OpenType features turned on and off by tag, as Ghostty's
         * font-feature: "ss01" or "+ss01" turns one on, "calt=0" or "-calt"
         * off, and "cv05=2" picks an alternate.
         */
        public array $features = [],
        /** The height of rows, a multiple of the font's own line height: 1 when 0, and 1.2 rows 20% taller, as Ghostty's adjust-cell-height = 20%. */
        public float $lineHeight = 0,
        /** Draws text with a thicker stroke, as Ghostty's font-thicken: not drawn yet (docs/mygo-deviations.md). */
        public bool $thicken = false,
    ) {}

    /** The font with its defaults, at $scale, as a key that tells fonts apart (mygo's fontKey). @internal @return array{family: string, size: float, weight: int, features: string, lineHeight: float, thicken: bool, scale: float} */
    public function key(float $scale): array
    {
        return [
            'family' => trim($this->family) === '' ? 'monospace' : $this->family,
            'size' => $this->size > 0 ? $this->size : 13.0,
            'weight' => min(max($this->weight > 0 ? $this->weight : 400, 100), 900),
            'features' => self::featureList($this->features),
            'lineHeight' => $this->lineHeight > 0 ? $this->lineHeight : 1.0,
            'thicken' => $this->thicken,
            'scale' => $scale,
        ];
    }

    /** The features as Node::fontFeatures() takes them. @param list<string> $features */
    public static function featureList(array $features): string
    {
        $out = [];
        foreach ($features as $f) {
            $f = trim($f);
            if (str_starts_with($f, '-')) {
                $f = trim(substr($f, 1)) . '=0';
            } elseif (str_starts_with($f, '+')) {
                $f = trim(substr($f, 1));
            }
            if ($f !== '' && $f !== '=0') { $out[] = $f; }
        }
        return implode(',', $out);
    }
}
