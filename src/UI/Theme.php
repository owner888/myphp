<?php
declare(strict_types=1);

namespace MyPHP\UI;

/**
 * The colors and metrics widgets draw with, as mygo's Theme. Colors are
 * 0xRRGGBB; the translucent ones carry their alpha beside them.
 * App::theme() follows the system's appearance and accent color unless
 * the app sets one.
 */
final class Theme
{
    public function __construct(
        public bool $dark = false,
        /** Fills the window. */
        public int $background = 0xffffff,
        /** The face of buttons, inputs and other controls, and while hovered or pressed. */
        public int $surface = 0xf4f4f5,
        public int $surfaceHover = 0xe9e9ec,
        public int $surfacePressed = 0xdddde1,
        /** Outlines controls. */
        public int $border = 0xd9d9de,
        public int $text = 0x18181b,
        /** Secondary text and placeholders. */
        public int $textMuted = 0x71717a,
        /** Primary buttons, checked controls and focus rings; accentText is text on them. */
        public int $accent = 0x2563eb,
        public int $accentHover = 0x1d4ed8,
        public int $accentPressed = 0x1e40af,
        public int $accentText = 0xffffff,
        public int $danger = 0xdc2626,
        public int $warning = 0xd97706,
        public int $success = 0x16a34a,
        /** Highlights selected text. */
        public int $selection = 0x2563eb,
        public float $selectionAlpha = 0.25,
        /** The ring around the control with the keyboard focus. */
        public int $focus = 0x2563eb,
        public float $focusAlpha = 0.55,
        public int $scrollbar = 0x000000,
        public float $scrollbarAlpha = 0.32,
        public float $scrollbarWidth = 6,
        /** Rounds the corners of controls. */
        public float $radius = 6,
        /** The unit of paddings and gaps; widgets size in multiples of it. */
        public float $spacing = 4,
        public float $fontSize = 13,
        /** Fills tooltips and toasts, the theme turned over so that they stand out from what they are over, and text on it; null for $text and $background (mygo's Inverse, InverseText). */
        public ?int $inverse = null,
        public ?int $inverseText = null,
    ) {}

    /** The fill of tooltips and toasts and their text (mygo's inverse). @return array{0: int, 1: int} */
    public function inverse(): array
    {
        return [$this->inverse ?? $this->text, $this->inverseText ?? $this->background];
    }

    public static function light(): self
    {
        return new self();
    }

    public static function dark(): self
    {
        return new self(
            dark: true,
            background: 0x18181b, surface: 0x27272a, surfaceHover: 0x323236, surfacePressed: 0x3c3c41,
            border: 0x3f3f46, text: 0xf4f4f5, textMuted: 0xa1a1aa,
            accent: 0x3b82f6, accentHover: 0x60a5fa, accentPressed: 0x2563eb, accentText: 0xffffff,
            danger: 0xef4444, warning: 0xf59e0b, success: 0x22c55e,
            selection: 0x3b82f6, selectionAlpha: 0.4, focus: 0x60a5fa, focusAlpha: 0.6,
            scrollbar: 0xffffff, scrollbarAlpha: 0.35,
        );
    }

    /** n units of spacing. */
    public function space(float $n): float
    {
        return $n * $this->spacing;
    }

    /** The theme following the desktop's settings (mygo's Theme.follow): its accent, more contrast, larger text. */
    public function follow(Preferences $p): self
    {
        $t = $p->accent !== null ? $this->withAccent($p->accent) : clone $this;
        if ($p->highContrast) {
            $t->border = self::mix($t->border, $t->text, 0.45);
            $t->textMuted = self::mix($t->textMuted, $t->text, 0.4);
            $t->focusAlpha = 1.0;
            $t->scrollbarAlpha = min(1.0, $t->scrollbarAlpha * 1.6);
        }
        if ($p->textScale > 0 && $p->textScale != 1.0) {
            $t->fontSize *= $p->textScale;
        }
        return $t;
    }

    /** Uses the system's accent color: hover and pressed shades are derived from it. */
    public function withAccent(int $rgb): self
    {
        $t = clone $this;
        $t->accent = $rgb;
        $t->accentHover = self::shade($rgb, $this->dark ? 1.15 : 0.88);
        $t->accentPressed = self::shade($rgb, $this->dark ? 0.9 : 0.76);
        $t->selection = $rgb;
        $t->focus = $rgb;
        return $t;
    }

    /** $a mixed with $b by $k (0 all $a, 1 all $b), as mygo's Color.Mix. */
    /** The gray of the same lightness as $rgb (BT.709 luminance), for what is drawn in shades of gray. */
    public static function luminance(int $rgb): int
    {
        $l = (int)round(0.2126 * ($rgb >> 16 & 255) + 0.7152 * ($rgb >> 8 & 255) + 0.0722 * ($rgb & 255));
        return $l << 16 | $l << 8 | $l;
    }

    public static function mix(int $a, int $b, float $k): int
    {
        $ch = fn(int $x, int $y) => max(0, min(255, (int)round($x + ($y - $x) * $k)));
        return ($ch(($a >> 16) & 255, ($b >> 16) & 255) << 16) | ($ch(($a >> 8) & 255, ($b >> 8) & 255) << 8) | $ch($a & 255, $b & 255);
    }

    public static function shade(int $rgb, float $factor): int
    {
        $ch = fn(int $v) => max(0, min(255, (int)round($v * $factor)));
        return ($ch(($rgb >> 16) & 255) << 16) | ($ch(($rgb >> 8) & 255) << 8) | $ch($rgb & 255);
    }
}
