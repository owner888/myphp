<?php
declare(strict_types=1);

namespace MyPHP\Native;

use MyPHP\Rectangle;

/**
 * The displays (NSScreen), in the coordinates windows are placed in: points
 * from the top-left corner of the primary screen, which AppKit's frames
 * are flipped by (mygo's rectFromMac and rectToMac).
 */
final class Screens
{
    /** The height of the primary screen. */
    public static function primaryHeight(): float
    {
        $screens = ObjC::msg('p:', ObjC::cls('NSScreen'), 'screens');
        return ObjC::msg('L:', $screens, 'count') === 0 ? 0.0 : ObjC::msg('r:', ObjC::msg('p:L', $screens, 'objectAtIndex:', 0), 'frame')->h;
    }

    public static function fromMac($r): Rectangle
    {
        return new Rectangle((int)round($r->x), (int)round(self::primaryHeight() - $r->y - $r->h), (int)round($r->w), (int)round($r->h));
    }

    public static function toMac(Rectangle $r)
    {
        return ObjC::rect($r->x, self::primaryHeight() - $r->y - $r->height, $r->width, $r->height);
    }

    private static ?\FFI $cg = null;

    /**
     * The displays, the primary first (mygo's darwin Displays): their
     * CGDirectDisplayID, name, bounds and work areas (the frame and
     * visible frame), scale, rotation, and whether they are the Mac's own.
     * @return list<array{id: int, label: string, bounds: Rectangle, workArea: Rectangle, scale: float, rotation: int, internal: bool, primary: bool}>
     */
    public static function displays(): array
    {
        self::$cg ??= \FFI::cdef('double CGDisplayRotation(uint32_t); int CGDisplayIsBuiltin(uint32_t);', '/System/Library/Frameworks/CoreGraphics.framework/CoreGraphics');
        $screens = ObjC::msg('p:', ObjC::cls('NSScreen'), 'screens');
        $key = ObjC::str('NSScreenNumber');
        $out = [];
        for ($i = 0, $n = ObjC::msg('L:', $screens, 'count'); $i < $n; $i++) {
            $s = ObjC::msg('p:L', $screens, 'objectAtIndex:', $i);
            $id = ObjC::msg('i:', ObjC::msg('p:p', ObjC::msg('p:', $s, 'deviceDescription'), 'objectForKey:', $key), 'unsignedIntValue');
            $id &= 0xffffffff;
            $out[] = [
                'id' => $id,
                'label' => ObjC::msg('b:p', $s, 'respondsToSelector:', ObjC::sel('localizedName')) ? ObjC::phpStr(ObjC::msg('p:', $s, 'localizedName')) : '',
                'bounds' => self::fromMac(ObjC::msg('r:', $s, 'frame')), 'workArea' => self::fromMac(ObjC::msg('r:', $s, 'visibleFrame')),
                'scale' => ObjC::msg('d:', $s, 'backingScaleFactor'),
                'rotation' => (int)round(self::$cg->CGDisplayRotation($id)), 'internal' => self::$cg->CGDisplayIsBuiltin($id) !== 0,
                'primary' => $i === 0,
            ];
        }
        return $out;
    }
}
