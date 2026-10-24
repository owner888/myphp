<?php
declare(strict_types=1);

namespace MyPHP\UI;

/** Style bits for text: the system font plain, bold, italic, or the fixed-pitch font. */
final class Font
{
    public const BOLD = 1, ITALIC = 2, MONO = 4;

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
