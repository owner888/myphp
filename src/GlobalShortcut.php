<?php
declare(strict_types=1);

namespace MyPHP;

use Closure;
use MyPHP\Platform\Accelerator;
use RuntimeException;

/**
 * Keyboard shortcuts that work while the app is in the background, as
 * mygo's GlobalShortcut: register("CmdOrCtrl+Shift+Space", fn). Register
 * from App::whenReady(); the callback runs on the main thread.
 */
final class GlobalShortcut
{
    /** @var array<string, int> the canonical accelerator's id */
    private static array $byKey = [];
    /** @var array<int, Closure> */
    private static array $byId = [];
    private static int $next = 0;

    /** Calls $fn whenever $accelerator is pressed, even while the app is not focused. */
    public static function register(string $accelerator, Closure $fn): void
    {
        if (!App::running()) {
            throw new RuntimeException('myphp: GlobalShortcut::register needs the running app (App::whenReady)');
        }
        $key = (string)Accelerator::parse($accelerator);
        if (isset(self::$byKey[$key])) {
            throw new RuntimeException("myphp: shortcut $key is already registered");
        }
        $id = ++self::$next;
        App::backend()->registerHotkey($id, $accelerator);
        [self::$byKey[$key], self::$byId[$id]] = [$id, $fn];
    }

    public static function isRegistered(string $accelerator): bool
    {
        try {
            return isset(self::$byKey[(string)Accelerator::parse($accelerator)]);
        } catch (\UnexpectedValueException) {
            return false;
        }
    }

    public static function unregister(string $accelerator): void
    {
        try {
            $key = (string)Accelerator::parse($accelerator);
        } catch (\UnexpectedValueException) {
            return;
        }
        $id = self::$byKey[$key] ?? null;
        if ($id !== null) {
            unset(self::$byKey[$key], self::$byId[$id]);
            App::backend()->unregisterHotkey($id);
        }
    }

    public static function unregisterAll(): void
    {
        $ids = array_keys(self::$byId);
        [self::$byKey, self::$byId] = [[], []];
        foreach ($ids as $id) {
            App::backend()->unregisterHotkey($id);
        }
    }

    /** @internal the backend's hot key of $id was pressed */
    public static function pressed(int $id): void
    {
        (self::$byId[$id] ?? null)?->__invoke();
    }
}
