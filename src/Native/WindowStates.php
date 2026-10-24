<?php
declare(strict_types=1);

namespace MyPHP\Native;

use MyPHP\{App, PathName, Rectangle, WindowState, WindowStateStore};

/**
 * The states windows' state keys remember (mygo's windowStates), loaded
 * from the WindowStateStore on first use: App::setWindowStateStore's, else
 * window-state.json in PathName::UserData. A save that fails keeps them
 * for the next one, at the latest as the app quits.
 *
 * @internal
 */
final class WindowStates
{
    public static ?WindowStateStore $store = null;
    /** @var ?array<string, WindowState> */
    private static ?array $byKey = null;
    private static bool $dirty = false;

    public static function get(string $key): ?WindowState
    {
        self::load();
        return self::$byKey[$key] ?? null;
    }

    public static function set(string $key, WindowState $st): void
    {
        self::load();
        if ((self::$byKey[$key] ?? null) != $st) {
            self::$byKey[$key] = $st;
            self::$dirty = true;
        }
    }

    public static function save(): void
    {
        if (!self::$dirty) {
            return;
        }
        try {
            self::store()->saveWindowStates(self::$byKey);
            self::$dirty = false;
        } catch (\Throwable $e) {
            error_log("myphp: window state: {$e->getMessage()}");
        }
    }

    /** Whether enough of $r shows in a work area of $workAreas to reach it (mygo's onDisplay). @param list<Rectangle> $workAreas */
    public static function onDisplay(Rectangle $r, array $workAreas): bool
    {
        foreach ($workAreas as $a) {
            $w = min($a->x + $a->width, $r->x + $r->width) - max($a->x, $r->x);
            $h = min($a->y + $a->height, $r->y + $r->height) - max($a->y, $r->y);
            if ($w >= min(100, $r->width) && $h >= min(50, $r->height)) {
                return true;
            }
        }
        return false;
    }

    /** @internal forgets what was loaded, for tests */
    public static function reset(): void
    {
        self::$byKey = null;
        self::$dirty = false;
    }

    private static function load(): void
    {
        if (self::$byKey !== null) {
            return;
        }
        try {
            $states = self::store()->loadWindowStates();
        } catch (\Throwable $e) {
            $states = null; // a damaged file is ignored, and replaced by the next save
        }
        self::$byKey = $states ?? [];
    }

    private static function store(): WindowStateStore
    {
        return self::$store ?? new WindowStateFile(App::path(PathName::UserData) . '/window-state.json');
    }
}
