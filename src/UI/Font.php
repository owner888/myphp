<?php
declare(strict_types=1);

namespace MyPHP\UI;

/** Style bits for text: the system font plain, bold, italic, or the fixed-pitch font. */
final class Font
{
    public const BOLD = 1, ITALIC = 2, MONO = 4;

    /** UIKit's text styles (UIFont.TextStyle), for Node::preferredFont(): the HIG's sizes on a phone or tablet, and on the desktop. */
    public const LARGE_TITLE = 'largeTitle', TITLE1 = 'title1', TITLE2 = 'title2', TITLE3 = 'title3', HEADLINE = 'headline',
        BODY = 'body', CALLOUT = 'callout', SUBHEADLINE = 'subheadline', FOOTNOTE = 'footnote', CAPTION1 = 'caption1', CAPTION2 = 'caption2';

    /** The HIG's sizes and weights of the text styles at the default (Large) text size: on iOS and iPadOS, and on macOS; [points, weight]. */
    private const TOUCH_STYLES = [
        self::LARGE_TITLE => [34, 400], self::TITLE1 => [28, 400], self::TITLE2 => [22, 400], self::TITLE3 => [20, 400],
        self::HEADLINE => [17, 600], self::BODY => [17, 400], self::CALLOUT => [16, 400], self::SUBHEADLINE => [15, 400],
        self::FOOTNOTE => [13, 400], self::CAPTION1 => [12, 400], self::CAPTION2 => [11, 400],
    ];
    private const DESKTOP_STYLES = [
        self::LARGE_TITLE => [26, 400], self::TITLE1 => [22, 400], self::TITLE2 => [17, 400], self::TITLE3 => [15, 400],
        self::HEADLINE => [13, 700], self::BODY => [13, 400], self::CALLOUT => [12, 400], self::SUBHEADLINE => [11, 400],
        self::FOOTNOTE => [10, 400], self::CAPTION1 => [10, 400], self::CAPTION2 => [10, 500],
    ];

    /**
     * The size in points and the weight of text style $style (one of the
     * constants above) as the HIG gives them: iOS's on a touch screen,
     * macOS's on the desktop, both scaled by $scale, the theme's text size
     * over the default's (Theme::textScale), as the system's larger text
     * scales them. Throws for a style that is not one.
     * @return array{0: float, 1: int}
     */
    public static function preferred(string $style, bool $touch, float $scale = 1.0): array
    {
        $table = $touch ? self::TOUCH_STYLES : self::DESKTOP_STYLES;
        if (!isset($table[$style])) {
            throw new \InvalidArgumentException("Not a text style: $style (Font::LARGE_TITLE … Font::CAPTION2)");
        }
        [$size, $weight] = $table[$style];
        return [$size * $scale, $weight];
    }

    /** Adds a TrueType or OpenType font, or collection, that text uses with fontFamily(): under $family, or its own family name for '' (mygo's RegisterFont). Throws what is not a font. */
    public static function register(string $data, string $family = ''): void
    {
        \MyPHP\App::text()->textSystem()->registerFont($data, $family);
    }

    /** The font families text can use, as a font setting offers them: the app's registered ones first, then the system's, each once, sorted without regard to case; the system's asked for once (mygo's FontFamilies). @return list<FontFamily> */
    public static function families(): array
    {
        return \MyPHP\App::text()->textSystem()->fontFamilies();
    }
}
