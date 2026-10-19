<?php
declare(strict_types=1);

namespace MyPHP\Native;

/**
 * The Dock tile's progress under the app icon (Window::setProgressBar), as
 * mygo's dock.go: NSProgressIndicator does not draw in a Dock tile, which
 * renders its content view offscreen, so the bar is two custom NSBoxes,
 * which draw themselves.
 */
final class Dock
{
    /** The tile's view, the icon in it, and the bar's track and fill, while one shows. */
    private static $view = null, $icon = null, $track = null, $fill = null;

    /** Shows $value (0 to 1) done in $state ('normal', 'indeterminate', 'paused', 'error'), or, for '', no progress. */
    public static function setProgress(string $state, float $value): void
    {
        $app = ObjC::msg('p:', ObjC::cls('NSApplication'), 'sharedApplication');
        $tile = ObjC::msg('p:', $app, 'dockTile');
        if ($state === '') {
            if (self::$view !== null) {
                ObjC::msg('v:p', $tile, 'setContentView:', null);
                foreach ([self::$view, self::$icon, self::$track, self::$fill] as $v) { ObjC::msg('v:', $v, 'release'); }
                self::$view = self::$icon = self::$track = self::$fill = null;
                ObjC::msg('v:', $tile, 'display');
            }
            return;
        }
        $size = ObjC::msg('z:', $tile, 'size');
        $height = 14.0; $inset = 6.0;
        if (self::$view === null) {
            $frame = ObjC::rect(0, 0, $size->w, $size->h);
            self::$view = ObjC::msg('p:r', ObjC::alloc('NSView'), 'initWithFrame:', $frame);
            self::$icon = ObjC::msg('p:r', ObjC::alloc('NSImageView'), 'initWithFrame:', $frame);
            ObjC::msg('v:p', self::$view, 'addSubview:', self::$icon);
            $box = function (array $fill, array $border, float $width) use ($height) {
                $v = ObjC::msg('p:', ObjC::alloc('NSBox'), 'init');
                ObjC::msg('v:L', $v, 'setBoxType:', 4); // NSBoxCustom
                ObjC::msg('v:L', $v, 'setTitlePosition:', 0); // NSNoTitle
                ObjC::msg('v:p', $v, 'setFillColor:', self::color(...$fill));
                ObjC::msg('v:p', $v, 'setBorderColor:', self::color(...$border));
                ObjC::msg('v:d', $v, 'setBorderWidth:', $width);
                ObjC::msg('v:d', $v, 'setCornerRadius:', $height / 2);
                ObjC::msg('v:p', self::$view, 'addSubview:', $v);
                return $v;
            };
            self::$track = $box([40, 40, 40, 200], [160, 160, 160, 255], 1.5);
            self::$fill = $box([51, 153, 255, 255], [51, 153, 255, 255], 0);
            ObjC::msg('v:p', $tile, 'setContentView:', self::$view);
        }
        ObjC::msg('v:p', self::$icon, 'setImage:', ObjC::msg('p:', $app, 'applicationIconImage'));
        $tw = $size->w - 2 * $inset;
        ObjC::msg('v:r', self::$track, 'setFrame:', ObjC::rect($inset, $inset, $tw, $height));
        if ($state === 'indeterminate') {
            $value = 1.0;
        }
        ObjC::msg('v:b', self::$fill, 'setHidden:', $value <= 0);
        ObjC::msg('v:r', self::$fill, 'setFrame:', ObjC::rect($inset, $inset, max($height, $tw * $value), $height));
        ObjC::msg('v:', $tile, 'display');
    }

    private static function color(int $r, int $g, int $b, int $a)
    {
        return ObjC::msg('p:dddd', ObjC::cls('NSColor'), 'colorWithSRGBRed:green:blue:alpha:', $r / 255, $g / 255, $b / 255, $a / 255);
    }
}
