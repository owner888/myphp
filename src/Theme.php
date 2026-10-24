<?php
declare(strict_types=1);

namespace MyPHP;

use Closure;
use MyPHP\Native\{Appearance, ObjC};

/**
 * The system's appearance (mygo's Theme): light or dark, the accent color
 * and the accessibility settings, and an override of the appearance.
 * Pages follow it through prefers-color-scheme, and the accent and the
 * text scale through the --myphp-accent and --myphp-text-scale CSS
 * variables and window.myphp.preferences; native UI through UI\Theme.
 */
final class Theme
{
    private static ThemeSource $source = ThemeSource::System;
    private static ?ThemePreferences $prefs = null;
    /** @var array<int, Closure> */
    private static array $onUpdated = [];
    private static int $next = 0;

    /** Whether the app has a dark appearance now (mygo's IsDark). */
    public static function isDark(): bool
    {
        return Appearance::isDark(self::nsApp());
    }

    /** The accent color and the accessibility settings of the desktop, read once until they change (mygo's Preferences). */
    public static function preferences(): ThemePreferences
    {
        if (self::$prefs === null) {
            $ws = ObjC::msg('p:', ObjC::cls('NSWorkspace'), 'sharedWorkspace');
            self::$prefs = new ThemePreferences(
                accent: Appearance::accent(self::nsApp()),
                reduceMotion: (bool)ObjC::msg('b:', $ws, 'accessibilityDisplayShouldReduceMotion'),
                reduceTransparency: (bool)ObjC::msg('b:', $ws, 'accessibilityDisplayShouldReduceTransparency'),
                highContrast: (bool)ObjC::msg('b:', $ws, 'accessibilityDisplayShouldIncreaseContrast'),
            );
        }
        return self::$prefs;
    }

    /** The appearance setSource() set (mygo's Source). */
    public static function source(): ThemeSource
    {
        return self::$source;
    }

    /** Forces a light or dark appearance, or follows the system's again (mygo's SetSource); before App::run too, which starts the app so. */
    public static function setSource(ThemeSource $s): void
    {
        self::$source = $s;
        if (App::running()) {
            self::apply();
        }
    }

    /** Calls $fn when the appearance, or the desktop's preferences, change (mygo's OnUpdated). Returns a function that removes it. */
    public static function onUpdated(Closure $fn): Closure
    {
        $k = self::$next++;
        self::$onUpdated[$k] = $fn;
        return function () use ($k): void { unset(self::$onUpdated[$k]); };
    }

    /** @internal gives NSApp the appearance of the source */
    public static function apply(): void
    {
        $name = match (self::$source) { ThemeSource::Light => 'NSAppearanceNameAqua', ThemeSource::Dark => 'NSAppearanceNameDarkAqua', ThemeSource::System => null };
        ObjC::msg('v:p', self::nsApp(), 'setAppearance:', $name === null ? null : ObjC::msg('p:p', ObjC::cls('NSAppearance'), 'appearanceNamed:', ObjC::str($name)));
    }

    /** @internal the appearance or the preferences changed: those read before are forgotten */
    public static function forget(): void
    {
        self::$prefs = null;
    }

    /** @internal tells the listeners the appearance or the preferences changed, once what follows them did */
    public static function changed(): void
    {
        foreach (self::$onUpdated as $fn) {
            $fn();
        }
    }

    private static function nsApp()
    {
        if (!App::running()) {
            throw new \RuntimeException('myphp: Theme needs the running app (App::whenReady)');
        }
        return ObjC::msg('p:', ObjC::cls('NSApplication'), 'sharedApplication');
    }
}
