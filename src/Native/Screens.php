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

    /** The displays: their bounds and work areas (the frame and visible frame), the primary first. @return list<array{bounds: Rectangle, workArea: Rectangle, primary: bool}> */
    public static function displays(): array
    {
        $screens = ObjC::msg('p:', ObjC::cls('NSScreen'), 'screens');
        $out = [];
        for ($i = 0, $n = ObjC::msg('L:', $screens, 'count'); $i < $n; $i++) {
            $s = ObjC::msg('p:L', $screens, 'objectAtIndex:', $i);
            $out[] = ['bounds' => self::fromMac(ObjC::msg('r:', $s, 'frame')), 'workArea' => self::fromMac(ObjC::msg('r:', $s, 'visibleFrame')), 'primary' => $i === 0];
        }
        return $out;
    }
}
