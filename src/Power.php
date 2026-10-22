<?php
declare(strict_types=1);

namespace MyPHP;

use Closure;
use MyPHP\Native\ObjC;

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
    private const NOTIFICATIONS = [
        'NSWorkspaceWillSleepNotification' => 'suspend',
        'NSWorkspaceDidWakeNotification' => 'resume',
        'com.apple.screenIsLocked' => 'lockScreen',
        'com.apple.screenIsUnlocked' => 'unlockScreen',
    ];

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

    private static function add(string $event, Closure $fn): Closure
    {
        $k = self::$next++;
        self::$listeners[$event][$k] = $fn;
        if (!self::$watching) {
            self::$watching = true;
            App::whenReady(self::watch(...));
        }
        return function () use ($event, $k): void { unset(self::$listeners[$event][$k]); };
    }

    /** @internal what the system told, by its notification's name */
    public static function notified(string $name): void
    {
        foreach (self::$listeners[self::NOTIFICATIONS[$name] ?? ''] ?? [] as $fn) {
            $fn();
        }
    }

    private static function watch(): void
    {
        ObjC::defineClass('MyPHPPowerObserver', 'NSObject', [
            'notified:' => ['v@:@', 'v(ppp)', function ($self, $cmd, $note) {
                self::notified(ObjC::phpStr(ObjC::msg('p:', $note, 'name')));
            }],
        ]);
        $observer = ObjC::msg('p:', ObjC::alloc('MyPHPPowerObserver'), 'init');
        ObjC::msg('p:', $observer, 'retain'); // the centers do not keep it
        $workspace = ObjC::msg('p:', ObjC::msg('p:', ObjC::cls('NSWorkspace'), 'sharedWorkspace'), 'notificationCenter');
        $distributed = ObjC::msg('p:', ObjC::cls('NSDistributedNotificationCenter'), 'defaultCenter');
        foreach (array_keys(self::NOTIFICATIONS) as $name) {
            $center = str_starts_with($name, 'com.apple.') ? $distributed : $workspace;
            ObjC::msg('v:pppp', $center, 'addObserver:selector:name:object:', $observer, ObjC::sel('notified:'), ObjC::str($name), null);
        }
    }
}
