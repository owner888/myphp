<?php
declare(strict_types=1);

namespace MyPHP;

use InvalidArgumentException;
use WeakReference;

/**
 * An image a menu item shows beside its label (MenuItem::$icon,
 * setIcon(), mygo's MenuIcon), made once and given to any number of
 * items, tray menus' included. It fits a box of 16 by 16 points, keeping
 * its aspect: give it at least 32 by 32 pixels, for Retina displays. A
 * checkbox or radio item shows its check as well. Items showing the same
 * image share what the backend makes of it (an NSImage), made the first
 * time a menu shows it and let go with the icon.
 */
final class MenuIcon
{
    /** The box, in points, the icon is drawn to fit (mygo's MenuIconSize). */
    public const SIZE = 16;

    /** @var array<string, WeakReference<self>> the icons by their image, so that those of the same image share it */
    private static array $made = [];
    /** What a backend made of the icon, once a menu showed it, and what lets it go. */
    private mixed $native = null;
    private bool $decoded = false;
    private ?\Closure $free = null;

    private function __construct(public readonly string $png, public readonly bool $template) {}

    /**
     * The icon of PNG image $png (mygo's NewMenuIcon): in its own colors,
     * as a flag; with $template, a black and transparent image shown in the
     * color of the menu's text, white in a dark menu and on the highlight,
     * as macOS tints template images. Throws for data that is no PNG image.
     */
    public static function of(string $png, bool $template = false): self
    {
        if (strlen($png) < 24 || !str_starts_with($png, "\x89PNG\r\n\x1a\n") || substr($png, 12, 4) !== 'IHDR') {
            throw new InvalidArgumentException('myphp: a menu icon is a PNG image');
        }
        $key = ($template ? 't' : 'c') . hash('xxh128', $png);
        $ic = (self::$made[$key] ?? null)?->get();
        if ($ic !== null) {
            return $ic;
        }
        if (count(self::$made) > 64) {
            self::$made = array_filter(self::$made, fn(WeakReference $w) => $w->get() !== null);
        }
        $ic = new self($png, $template);
        self::$made[$key] = WeakReference::create($ic);
        return $ic;
    }

    /**
     * The icon of the system's symbol $name (an SF Symbol's name, as Image::symbol draws it), as a desktop's menu shows
     * a SwiftUI Button's systemImage: a template on macOS, which tints it, in the theme's text elsewhere, where the
     * menu draws the image as it is; null for a name the system has no symbol of. Not mygo's.
     */
    public static function symbol(string $name): ?self
    {
        $symbols = \MyPHP\App::backend()->symbols();
        if (!$symbols->exists($name)) {
            return null;
        }
        $mac = PHP_OS_FAMILY === 'Darwin';
        [$w, $h, $rgba] = $symbols->draw($name, self::SIZE, 0.0, $mac ? 0x000000 : \MyPHP\App::theme()->text, 2.0);
        $raw = '';
        for ($y = 0, $stride = $w * 4; $y < $h; $y++) { // straight alpha, as a PNG has it, of the premultiplied pixels
            $row = substr($rgba, $y * $stride, $stride);
            for ($x = 0; $x < $stride; $x += 4) {
                $a = ord($row[$x + 3]);
                if ($a > 0 && $a < 255) { for ($k = 0; $k < 3; $k++) { $row[$x + $k] = chr(min(255, intdiv(ord($row[$x + $k]) * 255, $a))); } }
            }
            $raw .= "\0" . $row;
        }
        $chunk = fn(string $type, string $data): string => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        return self::of("\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', $w, $h, 8, 6, 0, 0, 0)) . $chunk('IDAT', \MyPHP\Native\Zlib::deflate($raw, 15, 6)) . $chunk('IEND', ''), $mac);
    }

    /**
     * @internal what $make made of the icon the first time it was asked (mygo's
     * Native): a backend's decoding of it, kept for as long as the icon is,
     * $free called with it as the icon goes; null for an image that cannot
     * be decoded, kept too.
     */
    public function native(\Closure $make, ?\Closure $free = null): mixed
    {
        if (!$this->decoded) {
            [$this->native, $this->decoded, $this->free] = [$make(), true, $free];
        }
        return $this->native;
    }

    public function __destruct()
    {
        if ($this->native !== null && $this->free !== null) {
            ($this->free)($this->native);
        }
    }
}
