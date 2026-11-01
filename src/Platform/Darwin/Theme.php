<?php
declare(strict_types=1);

namespace MyPHP\Platform\Darwin;

use MyPHP\{ThemePreferences, ThemeSource};
use MyPHP\Native\{Appearance, ObjC};
use MyPHP\Platform;

/** NSApp's appearance, its accent and the accessibility display options (mygo's darwin theme). */
final class Theme implements Platform\Theme
{
    public function isDark(): bool
    {
        return Appearance::isDark(self::nsApp());
    }

    public function setSource(ThemeSource $source): void
    {
        $name = match ($source) { ThemeSource::Light => 'NSAppearanceNameAqua', ThemeSource::Dark => 'NSAppearanceNameDarkAqua', ThemeSource::System => null };
        ObjC::msg('v:p', self::nsApp(), 'setAppearance:', $name === null ? null : ObjC::msg('p:p', ObjC::cls('NSAppearance'), 'appearanceNamed:', ObjC::str($name)));
    }

    public function preferences(): ThemePreferences
    {
        $ws = ObjC::msg('p:', ObjC::cls('NSWorkspace'), 'sharedWorkspace');
        return new ThemePreferences(
            accent: Appearance::accent(self::nsApp()),
            reduceMotion: (bool)ObjC::msg('b:', $ws, 'accessibilityDisplayShouldReduceMotion'),
            reduceTransparency: (bool)ObjC::msg('b:', $ws, 'accessibilityDisplayShouldReduceTransparency'),
            highContrast: (bool)ObjC::msg('b:', $ws, 'accessibilityDisplayShouldIncreaseContrast'),
        );
    }

    private static function nsApp()
    {
        return ObjC::msg('p:', ObjC::cls('NSApplication'), 'sharedApplication');
    }
}
