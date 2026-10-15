<?php
declare(strict_types=1);

namespace MyPHP;

/**
 * What WindowOptions::$stateKey remembers of a window — its frame — in
 * window-state.json under App::userDataPath(), as mygo's window state
 * store. Frames are captured as windows move and resize, and saved when
 * a window closes and when the app quits.
 */
final class WindowState
{
    private const FILE = 'window-state.json';
    private static ?array $states = null;
    private static bool $dirty = false;

    private static function load(): void
    {
        if (self::$states !== null) {
            return;
        }
        $path = App::userDataPath() . '/' . self::FILE;
        $json = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;
        self::$states = is_array($json) ? $json : [];
    }

    /** @return array{x: float, y: float, w: float, h: float}|null */
    public static function get(string $key): ?array
    {
        self::load();
        $s = self::$states[$key] ?? null;
        return is_array($s) && isset($s['x'], $s['y'], $s['w'], $s['h']) ? $s : null;
    }

    public static function set(string $key, float $x, float $y, float $w, float $h): void
    {
        self::load();
        $state = ['x' => $x, 'y' => $y, 'w' => $w, 'h' => $h];
        if ((self::$states[$key] ?? null) !== $state) {
            self::$states[$key] = $state;
            self::$dirty = true;
        }
    }

    public static function save(): void
    {
        if (!self::$dirty) {
            return;
        }
        file_put_contents(App::userDataPath() . '/' . self::FILE, json_encode(self::$states, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        self::$dirty = false;
    }
}
