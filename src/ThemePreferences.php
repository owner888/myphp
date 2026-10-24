<?php
declare(strict_types=1);

namespace MyPHP;

/**
 * The accent color and the accessibility settings of the desktop, which an
 * app and its pages should follow (mygo's ThemePreferences). Pages get
 * them as JSON, window.myphp.preferences: {"accent": "#rrggbb" or null,
 * "reduceMotion": …, "reduceTransparency": …, "highContrast": …,
 * "textScale": …}.
 */
final class ThemePreferences implements \JsonSerializable
{
    public function __construct(
        /** The color of the desktop's controls, 0xRRGGBB, null where it has none. UI\Theme's default follows it. */
        public readonly ?int $accent = null,
        /** Reduce Motion. */
        public readonly bool $reduceMotion = false,
        /** Reduce Transparency: opaque surfaces in place of translucent ones, as vibrancy. */
        public readonly bool $reduceTransparency = false,
        /** Increase Contrast. */
        public readonly bool $highContrast = false,
        /** How many times larger than usual text should be; macOS has no such setting, so 1. */
        public readonly float $textScale = 1.0,
    ) {}

    /** @return array{accent: ?string, reduceMotion: bool, reduceTransparency: bool, highContrast: bool, textScale: float} */
    public function jsonSerialize(): array
    {
        return [
            'accent' => $this->accent === null ? null : sprintf('#%06x', $this->accent),
            'reduceMotion' => $this->reduceMotion, 'reduceTransparency' => $this->reduceTransparency,
            'highContrast' => $this->highContrast, 'textScale' => $this->textScale,
        ];
    }

    /** What jsonSerialize() encodes, decoded (mygo's UnmarshalJSON); throws on another shape. */
    public static function fromJSON(string $json): self
    {
        $v = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        if (!is_array($v)) {
            throw new \InvalidArgumentException('myphp: theme preferences: not an object');
        }
        $accent = $v['accent'] ?? null;
        if ($accent !== null && (!is_string($accent) || preg_match('/^#([0-9a-f]{6})([0-9a-f]{2})?$/i', $accent, $m) !== 1)) {
            throw new \InvalidArgumentException('myphp: theme preferences: bad accent');
        }
        return new self($accent === null ? null : (int)hexdec($m[1]), (bool)($v['reduceMotion'] ?? false), (bool)($v['reduceTransparency'] ?? false),
            (bool)($v['highContrast'] ?? false), (float)($v['textScale'] ?? 0) ?: 1.0);
    }
}
