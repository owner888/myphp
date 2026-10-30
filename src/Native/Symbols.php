<?php
declare(strict_types=1);

namespace MyPHP\Native;

use FFI;
use RuntimeException;

/**
 * SF Symbols, the system's icons (macOS 11 and later), as pixels: an
 * NSImage of the symbol, configured with a point size and a weight, drawn
 * into a CGBitmapContext at $scale pixels a point in one color.
 *
 * @internal Image::symbol, UI\MoviePlayerSettings
 */
final class Symbols
{
    private const RGBA_PREMULTIPLIED_BIG = 1 | (4 << 12); // R G B A in memory
    private static ?FFI $c = null;
    private static $space = null;

    private static function c(): FFI
    {
        if (self::$c === null) {
            ObjC::boot(['Foundation', 'AppKit']);
            self::$c = FFI::cdef('
                void* CGColorSpaceCreateDeviceRGB(void);
                void* CGBitmapContextCreate(void*, unsigned long, unsigned long, unsigned long, unsigned long, void*, unsigned int);
                void* CGBitmapContextGetData(void*);
                void  CGContextRelease(void*);
            ', '/System/Library/Frameworks/CoreGraphics.framework/CoreGraphics');
            self::$space = self::$c->CGColorSpaceCreateDeviceRGB();
        }
        return self::$c;
    }

    /** Whether the system has symbol $name (IINA's NSImage.sf takes the first of several it has). */
    public static function exists(string $name): bool
    {
        self::c();
        return ObjC::msg('p:pp', ObjC::cls('NSImage'), 'imageWithSystemSymbolName:accessibilityDescription:', ObjC::str($name), null) !== null;
    }

    /**
     * Symbol $name at $size points and $weight (NSFontWeight: -0.8
     * ultra-light to 0.62 black, 0 regular), in $rgb: [w, h, premultiplied
     * RGBA] in pixels, its size the symbol's own at that point size.
     *
     * @return array{0: int, 1: int, 2: string}
     */
    public static function draw(string $name, float $size, float $weight, int $rgb, float $scale): array
    {
        $c = self::c();
        $img = ObjC::msg('p:pp', ObjC::cls('NSImage'), 'imageWithSystemSymbolName:accessibilityDescription:', ObjC::str($name), null)
            ?? throw new RuntimeException("myphp: no SF Symbol \"$name\"");
        $config = ObjC::msg('p:dd', ObjC::cls('NSImageSymbolConfiguration'), 'configurationWithPointSize:weight:', $size, $weight);
        $img = ObjC::msg('p:p', $img, 'imageWithSymbolConfiguration:', $config);
        $s = ObjC::msg('z:', $img, 'size');
        $w = max(1, (int)ceil($s->w * $scale)); $h = max(1, (int)ceil($s->h * $scale));
        $ctx = $c->CGBitmapContextCreate(null, $w, $h, 8, $w * 4, self::$space, self::RGBA_PREMULTIPLIED_BIG) ?? throw new RuntimeException('CGBitmapContextCreate');
        $g = ObjC::msg('p:pb', ObjC::cls('NSGraphicsContext'), 'graphicsContextWithCGContext:flipped:', ObjC::import($ctx), false);
        ObjC::msg('v:', ObjC::cls('NSGraphicsContext'), 'saveGraphicsState');
        ObjC::msg('v:p', ObjC::cls('NSGraphicsContext'), 'setCurrentContext:', $g);
        ObjC::msg('v:rrld', $img, 'drawInRect:fromRect:operation:fraction:', ObjC::rect(0, 0, $w, $h), ObjC::rect(0, 0, 0, 0), 2, 1.0); // NSCompositingOperationSourceOver; the zero rect is all of it
        ObjC::msg('v:', ObjC::cls('NSGraphicsContext'), 'restoreGraphicsState');
        $px = FFI::string($c->CGBitmapContextGetData($ctx), $w * $h * 4);
        $c->CGContextRelease($ctx);
        // A template image draws black in its alpha: the color, premultiplied by it.
        [$r, $gr, $b] = [($rgb >> 16) & 255, ($rgb >> 8) & 255, $rgb & 255];
        $out = '';
        for ($i = 3, $n = strlen($px); $i < $n; $i += 4) {
            $a = ord($px[$i]);
            $out .= chr(intdiv($r * $a + 127, 255)) . chr(intdiv($gr * $a + 127, 255)) . chr(intdiv($b * $a + 127, 255)) . chr($a);
        }
        return [$w, $h, $out];
    }
}
