<?php
declare(strict_types=1);

namespace MyPHP;

use Closure;

/**
 * Power and session changes (mygo's Power): the system going to sleep and
 * waking up, the screen locking and unlocking. Listeners run on the main
 * thread; the system is watched from the first one, once the app is
 * ready.
 */
final class Power
{
    /** @var array<string, array<int, Closure>> by event */
    private static array $listeners = [];
    private static int $next = 0;
    private static bool $watching = false;

    /** Calls $fn when the system is about to sleep (mygo's OnSuspend); returns a function that stops it. */
    public static function onSuspend(Closure $fn): Closure
    {
        return self::add('suspend', $fn);
    }

    /** Calls $fn when the system woke up (mygo's OnResume): timers stop while it sleeps, so what is due is late. */
    public static function onResume(Closure $fn): Closure
    {
        return self::add('resume', $fn);
    }

    /** Calls $fn when the screen locks (mygo's OnLockScreen). */
    public static function onLockScreen(Closure $fn): Closure
    {
        return self::add('lockScreen', $fn);
    }

    /** Calls $fn when the screen unlocks (mygo's OnUnlockScreen). */
    public static function onUnlockScreen(Closure $fn): Closure
    {
        return self::add('unlockScreen', $fn);
    }

    /**
     * Keeps the computer from sleeping while idle, and with $display the
     * display from turning off, as during a long task or while a video
     * plays, until the function it returns is called (mygo's KeepAwake):
     *
     *     $release = Power::keepAwake('Exporting the video', false);
     *     try { ... } finally { $release(); }
     *
     * $reason shows where the system lists what keeps it awake.
     */
    public static function keepAwake(string $reason, bool $display): Closure
    {
        self::needsApp();
        return App::backend()->power()->keepAwake($display, $reason);
    }

    /** Whether the computer runs on battery power (mygo's IsOnBattery). */
    public static function isOnBattery(): bool
    {
        return App::backend()->power()->onBattery();
    }

    /** @internal UI\MediaPlayer: the charge of the computer's own battery in percent, null for one of none. */
    public static function batteryLevel(): ?int
    {
        return App::backend()->power()->batteryLevel();
    }

    /** How long, in seconds, the user has not used the keyboard or the mouse (mygo's IdleTime). */
    public static function idleTime(): float
    {
        self::needsApp();
        return App::backend()->power()->idleTime();
    }

    private static function needsApp(): void
    {
        if (!App::running()) {
            throw new \RuntimeException('myphp: Power needs the running app (App::whenReady)');
        }
    }

    private static function add(string $event, Closure $fn): Closure
    {
        $k = self::$next++;
        self::$listeners[$event][$k] = $fn;
        if (!self::$watching) {
            self::$watching = true;
            App::whenReady(static fn() => App::backend()->power()->watch());
        }
        return function () use ($event, $k): void { unset(self::$listeners[$event][$k]); };
    }

    /** @internal the backend's event: "suspend", "resume", "lockScreen" or "unlockScreen" */
    public static function event(string $event): void
    {
        foreach (self::$listeners[$event] ?? [] as $fn) {
            $fn();
        }
    }
}
