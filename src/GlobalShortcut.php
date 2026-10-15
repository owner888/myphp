<?php
declare(strict_types=1);

namespace MyPHP;

use Closure;
use MyPHP\Native\Carbon;
use RuntimeException;

/**
 * Keyboard shortcuts that work while the app is in the background, as
 * mygo's GlobalShortcut: register("CmdOrCtrl+Shift+Space", fn). Register
 * from App::whenReady(); the callback runs on the main thread.
 */
final class GlobalShortcut
{
    /** Accelerator keys to macOS virtual key codes (ANSI layout). */
    private const KEYS = [
        'a' => 0x00, 's' => 0x01, 'd' => 0x02, 'f' => 0x03, 'h' => 0x04, 'g' => 0x05, 'z' => 0x06, 'x' => 0x07,
        'c' => 0x08, 'v' => 0x09, 'b' => 0x0B, 'q' => 0x0C, 'w' => 0x0D, 'e' => 0x0E, 'r' => 0x0F, 'y' => 0x10,
        't' => 0x11, '1' => 0x12, '2' => 0x13, '3' => 0x14, '4' => 0x15, '6' => 0x16, '5' => 0x17, '=' => 0x18,
        '9' => 0x19, '7' => 0x1A, '-' => 0x1B, '8' => 0x1C, '0' => 0x1D, ']' => 0x1E, 'o' => 0x1F, 'u' => 0x20,
        '[' => 0x21, 'i' => 0x22, 'p' => 0x23, 'l' => 0x25, 'j' => 0x26, "'" => 0x27, 'k' => 0x28, ';' => 0x29,
        '\\' => 0x2A, ',' => 0x2B, '/' => 0x2C, 'n' => 0x2D, 'm' => 0x2E, '.' => 0x2F, '`' => 0x32,
        'enter' => 0x24, 'return' => 0x24, 'tab' => 0x30, 'space' => 0x31, 'backspace' => 0x33, 'escape' => 0x35, 'esc' => 0x35,
        'delete' => 0x75, 'home' => 0x73, 'end' => 0x77, 'pageup' => 0x74, 'pagedown' => 0x79, 'insert' => 0x72,
        'left' => 0x7B, 'right' => 0x7C, 'down' => 0x7D, 'up' => 0x7E,
        'f1' => 0x7A, 'f2' => 0x78, 'f3' => 0x63, 'f4' => 0x76, 'f5' => 0x60, 'f6' => 0x61, 'f7' => 0x62, 'f8' => 0x64,
        'f9' => 0x65, 'f10' => 0x6D, 'f11' => 0x67, 'f12' => 0x6F, 'f13' => 0x69, 'f14' => 0x6B, 'f15' => 0x71,
        'f16' => 0x6A, 'f17' => 0x40, 'f18' => 0x4F, 'f19' => 0x50, 'f20' => 0x5A,
        'volumeup' => 0x48, 'volumedown' => 0x49, 'volumemute' => 0x4A,
    ];
    /** @var array<string, int> normalized accelerator => hot key id */
    private static array $ids = [];

    /** Calls $fn whenever $accelerator is pressed, even while the app is not focused. */
    public static function register(string $accelerator, Closure $fn): void
    {
        [$key, $code, $mods] = self::parse($accelerator);
        if (isset(self::$ids[$key])) {
            throw new RuntimeException("shortcut $accelerator is already registered");
        }
        self::$ids[$key] = Carbon::register($code, $mods, $fn);
    }

    public static function isRegistered(string $accelerator): bool
    {
        return isset(self::$ids[self::parse($accelerator)[0]]);
    }

    public static function unregister(string $accelerator): void
    {
        $key = self::parse($accelerator)[0];
        if (isset(self::$ids[$key])) {
            Carbon::unregister(self::$ids[$key]);
            unset(self::$ids[$key]);
        }
    }

    public static function unregisterAll(): void
    {
        foreach (self::$ids as $id) {
            Carbon::unregister($id);
        }
        self::$ids = [];
    }

    /** @return array{0: string, 1: int, 2: int} normalized name, key code, Carbon modifiers */
    private static function parse(string $accelerator): array
    {
        $mods = 0; $key = null;
        foreach (explode('+', $accelerator) as $part) {
            $p = strtolower(trim($part));
            switch ($p) {
                case 'cmd': case 'command': case 'super': case 'meta': case 'cmdorctrl': case 'commandorcontrol': $mods |= Carbon::CMD; break;
                case 'ctrl': case 'control': $mods |= Carbon::CONTROL; break;
                case 'alt': case 'option': $mods |= Carbon::OPTION; break;
                case 'shift': $mods |= Carbon::SHIFT; break;
                case 'plus': $p = '='; $mods |= Carbon::SHIFT; // no break
                default:
                    if (!isset(self::KEYS[$p])) {
                        throw new RuntimeException("key \"$part\" cannot be used in a global shortcut");
                    }
                    $key = $p;
            }
        }
        if ($key === null) {
            throw new RuntimeException("shortcut \"$accelerator\" names no key");
        }
        return ["$mods+$key", self::KEYS[$key], $mods];
    }
}
