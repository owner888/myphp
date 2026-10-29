<?php
declare(strict_types=1);

namespace MyPHP;

use InvalidArgumentException;
use MyPHP\Native\ObjC;
use WeakReference;

/**
 * An image a menu item shows beside its label (MenuItem::$icon,
 * setIcon(), mygo's MenuIcon), made once and given to any number of
 * items, tray menus' included. It fits a box of 16 by 16 points, keeping
 * its aspect: give it at least 32 by 32 pixels, for Retina displays. A
 * checkbox or radio item shows its check as well. Items showing the same
 * image share one NSImage, made the first time a menu shows it and let go
 * with the icon.
 */
final class MenuIcon
{
    /** The box, in points, the icon is drawn to fit (mygo's MenuIconSize). */
    public const SIZE = 16;

    /** @var array<string, WeakReference<self>> the icons by their image, so that those of the same image share it */
    private static array $made = [];
    /** The NSImage, once a menu showed the icon. */
    private mixed $native = null;

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

    /** @internal Menu: the NSImage, made the first time, fit to SIZE points; null for an image AppKit cannot read. */
    public function image(): mixed
    {
        if ($this->native === null) {
            $data = ObjC::msg('p:sL', ObjC::cls('NSData'), 'dataWithBytes:length:', $this->png, strlen($this->png));
            $img = ObjC::msg('p:p', ObjC::alloc('NSImage'), 'initWithData:', $data);
            if ($img === null) {
                return $this->native = false;
            }
            $size = ObjC::msg('z:', $img, 'size');
            if ($size->w > 0 && $size->h > 0) {
                $k = self::SIZE / max($size->w, $size->h);
                $s = ObjC::$c->new('CGSize');
                [$s->w, $s->h] = [$size->w * $k, $size->h * $k];
                ObjC::msg('v:z', $img, 'setSize:', $s);
            }
            ObjC::msg('v:b', $img, 'setTemplate:', $this->template);
            $this->native = $img;
        }
        return $this->native === false ? null : $this->native;
    }

    public function __destruct()
    {
        if ($this->native !== null && $this->native !== false) {
            ObjC::msg('v:', $this->native, 'release');
        }
    }
}
