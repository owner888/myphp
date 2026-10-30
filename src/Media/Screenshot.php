<?php
declare(strict_types=1);

namespace MyPHP\Media;

use MyPHP\Native\ImageIO;

/**
 * The formats of a player's screenshots, IINA's (Preference.ScreenshotFormat,
 * screenShotFormat): PNG, JPEG as .jpg or .jpeg, WebP, JPEG XL, AVIF, those
 * ImageIO writes here (IINA's mpv writes them all, by FFmpeg); a frame's
 * PNG (Player::snapshot) in one of them.
 *
 * @internal UI\MoviePlayerState, UI\MoviePlayerSettingsPages
 */
final class Screenshot
{
    /** The formats by IINA's numbers: their extension and their type's UTI. */
    public const FORMATS = [0 => ['png', 'public.png'], 1 => ['jpg', 'public.jpeg'], 2 => ['jpeg', 'public.jpeg'], 3 => ['webp', 'org.webmproject.webp'], 4 => ['jxl', 'public.jpeg-xl'], 5 => ['avif', 'public.avif']];

    /** The formats of FORMATS this system writes, as the settings offer them (IINA's in the order of their names). @return list<int> */
    public static function formats(): array
    {
        static $have = null;
        $have ??= PHP_OS_FAMILY === 'Darwin' ? ImageIO::writable() : ['public.png']; // elsewhere, the PNG the frame is
        $out = array_keys(array_filter(self::FORMATS, fn(array $f) => in_array($f[1], $have, true)));
        usort($out, fn(int $a, int $b) => self::FORMATS[$a][0] <=> self::FORMATS[$b][0]);
        return $out;
    }

    /** The frame $png in format $format (FORMATS'). */
    public static function encode(string $png, int $format): string
    {
        return $format === 0 ? $png : ImageIO::convert($png, self::FORMATS[$format][1] ?? throw new \InvalidArgumentException("no screenshot format $format"));
    }
}
