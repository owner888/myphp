<?php
declare(strict_types=1);

namespace MyPHP\Native;

/** The system's appearance: dark or light, and its accent color. */
final class Appearance
{
    public static function isDark($nsApp): bool
    {
        $appearance = ObjC::msg('p:', $nsApp, 'effectiveAppearance');
        // Not arrayWithObjects:, which is variadic (its arguments go on the stack on arm64).
        $names = ObjC::msg('p:', ObjC::cls('NSMutableArray'), 'array');
        ObjC::msg('v:p', $names, 'addObject:', ObjC::str('NSAppearanceNameAqua'));
        ObjC::msg('v:p', $names, 'addObject:', ObjC::str('NSAppearanceNameDarkAqua'));
        $best = ObjC::msg('p:p', $appearance, 'bestMatchFromAppearancesWithNames:', $names);
        return $best !== null && ObjC::phpStr($best) === 'NSAppearanceNameDarkAqua';
    }

    /** The accent color as 0xRRGGBB, as the app's appearance draws it. */
    public static function accent($nsApp): int
    {
        $cls = ObjC::cls('NSAppearance');
        $prev = ObjC::msg('p:', $cls, 'currentDrawingAppearance');
        ObjC::msg('v:p', $cls, 'setCurrentAppearance:', ObjC::msg('p:', $nsApp, 'effectiveAppearance'));
        $color = ObjC::msg('p:', ObjC::cls('NSColor'), 'controlAccentColor');
        $srgb = ObjC::msg('p:p', $color, 'colorUsingColorSpace:', ObjC::msg('p:', ObjC::cls('NSColorSpace'), 'sRGBColorSpace'));
        ObjC::msg('v:p', $cls, 'setCurrentAppearance:', $prev);
        $c = ObjC::$c;
        $r = $c->new('double'); $g = $c->new('double'); $b = $c->new('double'); $a = $c->new('double');
        ObjC::msg('v:pppp', $srgb, 'getRed:green:blue:alpha:', \FFI::addr($r), \FFI::addr($g), \FFI::addr($b), \FFI::addr($a));
        $byte = fn(float $v) => (int)round(max(0, min(1, $v)) * 255);
        return ($byte($r->cdata) << 16) | ($byte($g->cdata) << 8) | $byte($b->cdata);
    }
}
