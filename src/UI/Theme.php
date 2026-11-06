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
        /** The unit of paddings and gaps; widgets size in multiples of it; 0 is the default, 4. */
        public float $spacing = 4,
        public float $fontSize = 13,
        /** Fills tooltips and toasts, the theme turned over so that they stand out from what they are over, and text on it; null for $text and $background (mygo's Inverse, InverseText). */
        public ?int $inverse = null,
        public ?int $inverseText = null,
    ) {}

    /** The platform's text size the theme started from, which textScale() measures $fontSize against: 13 unless App::systemTheme set the platform's (defaultFontSize). Not mygo's. */
    public float $baseFontSize = 13.0;
    /** UIKit's sizes for a phone's controls, 0 for mygo's measured ones (the desktop): the least height of a button, a field and a select (44), a switch's track (51 × 31), a segmented control's height (32). Not mygo's; Theme::phone() sets them. */
    public float $controlHeight = 0.0, $switchWidth = 0.0, $switchHeight = 0.0, $segmentedHeight = 0.0;
    /** UIKit's grouped list colors, where they differ from $surface and $background: the page behind inset grouped sections (systemGroupedBackground) and the sections themselves (secondarySystemGroupedBackground); null for $surface and $background. Not mygo's. */
    public ?int $groupBackground = null, $groupSurface = null;

    /**
     * The theme of a phone or tablet: UIKit's system colors (label,
     * secondaryLabel, systemBackground, secondarySystemBackground, separator,
     * systemBlue, systemRed, systemOrange, systemGreen, and the grouped
     * backgrounds), 17-point text, corners rounded 12, and UIKit's control
     * sizes; the one design on iOS and Android. App::systemTheme() starts
     * from it on the phones. Not mygo's.
     */
    public static function phone(bool $dark): self
    {
        $t = $dark
            ? new self(dark: true,
                background: 0x000000, surface: 0x1c1c1e, surfaceHover: 0x2c2c2e, surfacePressed: 0x3a3a3c,
                border: 0x38383a, text: 0xffffff, textMuted: 0x8e8e93,
                accent: 0x0a84ff, accentHover: 0x409cff, accentPressed: 0x0a6fd6, accentText: 0xffffff,
                danger: 0xff453a, warning: 0xff9f0a, success: 0x30d158,
                selection: 0x0a84ff, selectionAlpha: 0.4, focus: 0x409cff, focusAlpha: 0.6,
                scrollbar: 0xffffff, scrollbarAlpha: 0.35, scrollbarWidth: 3, radius: 12, fontSize: 17)
            : new self(
                background: 0xffffff, surface: 0xf2f2f7, surfaceHover: 0xe5e5ea, surfacePressed: 0xd1d1d6,
                border: 0xc6c6c8, text: 0x000000, textMuted: 0x8e8e93,
                accent: 0x007aff, accentHover: 0x0a84ff, accentPressed: 0x0060df, accentText: 0xffffff,
                danger: 0xff3b30, warning: 0xff9500, success: 0x34c759,
                selection: 0x007aff, selectionAlpha: 0.25, focus: 0x007aff, focusAlpha: 0.55,
                scrollbar: 0x000000, scrollbarAlpha: 0.35, scrollbarWidth: 3, radius: 12, fontSize: 17);
        $t->baseFontSize = 17.0;
        $t->controlHeight = 44.0; $t->switchWidth = 51.0; $t->switchHeight = 31.0; $t->segmentedHeight = 32.0;
        // the grouped page is the gray in the light, black in the dark; the sections white in the light, the dark gray in the dark: UIKit's inversion
        [$t->groupBackground, $t->groupSurface] = $dark ? [0x000000, 0x1c1c1e] : [0xf2f2f7, 0xffffff];
        return $t;
    }

    /**
     * The size of text the platform's controls use, which the system theme
     * starts from (mygo's defaultFontSize): 13 points on macOS, 14 on Linux
     * (mygo's), and 17 on a phone or tablet (iOS's body, the one design on
     * Android too). Theme::light() and dark() keep 13, so the headless tests
     * measure the same on every platform; App::systemTheme() applies this.
     */
    public static function defaultFontSize(): float
    {
        if (\MyPHP\App::isIOS() || \MyPHP\App::isAndroid()) {
            return 17.0;
        }
        return PHP_OS_FAMILY === 'Darwin' ? 13.0 : 14.0;
    }

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
        return $n * ($this->spacing > 0 ? $this->spacing : 4.0); // no spacing is the default's (mygo's Spacing 0)
    }

    /** n times the font size, as CSS's rem, to size nodes with the text (mygo's Rem): the system's 13 points for a theme without one. */
    public function rem(float $n): float
    {
        return $n * ($this->fontSize > 0 ? $this->fontSize : 13.0);
    }

    /** How many times larger than the platform's default ($baseFontSize) the theme's text is: 1 for a theme that follows no larger text; what Font::preferred() and the text styles scale by. */
    public function textScale(): float
    {
        return $this->fontSize > 0 && $this->baseFontSize > 0 ? $this->fontSize / $this->baseFontSize : 1.0;
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

    /** Uses the system's accent color, as mygo's follow: hover and pressed shades, the selection and the focus ring of it, and text on it black where white would not stand out, as on yellow. */
    public function withAccent(int $rgb): self
    {
        $t = clone $this;
        $t->accent = $rgb;
        if ($this->dark) {
            [$t->accentHover, $t->accentPressed] = [self::mix($rgb, 0xffffff, 0.2), self::mix($rgb, 0x000000, 0.15)];
            [$t->selection, $t->selectionAlpha, $t->focus, $t->focusAlpha] = [$rgb, 0.4, self::mix($rgb, 0xffffff, 0.2), 0.6];
        } else {
            [$t->accentHover, $t->accentPressed] = [self::mix($rgb, 0x000000, 0.12), self::mix($rgb, 0x000000, 0.25)];
            [$t->selection, $t->selectionAlpha, $t->focus, $t->focusAlpha] = [$rgb, 0.25, $rgb, 0.55];
        }
        $t->accentText = (self::luminance($rgb) & 255) > 165 ? 0x18181b : 0xffffff;
        return $t;
    }

    /** The gray of the same lightness as $rgb (BT.709 luminance), for what is drawn in shades of gray. */
    public static function luminance(int $rgb): int
    {
        $l = (int)round(0.2126 * ($rgb >> 16 & 255) + 0.7152 * ($rgb >> 8 & 255) + 0.0722 * ($rgb & 255));
        return $l << 16 | $l << 8 | $l;
    }

    /** $a mixed with $b by $k (0 all $a, 1 all $b), as mygo's Color.Mix: by their wide colors when one is (WideColor::mix). */
    public static function mix(int $a, int $b, float $k): int
    {
        if (WideColor::isWide($a) || WideColor::isWide($b)) {
            return WideColor::mix($a, $b, max(0.0, min($k, 1.0)));
        }
        $ch = fn(int $x, int $y) => max(0, min(255, (int)round($x + ($y - $x) * $k)));
        return ($ch(($a >> 16) & 255, ($b >> 16) & 255) << 16) | ($ch(($a >> 8) & 255, ($b >> 8) & 255) << 8) | $ch($a & 255, $b & 255);
    }
}
