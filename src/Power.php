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

    private static ?\FFI $io = null, $cg = null, $ps = null;

    /**
     * Keeps the Mac from sleeping while idle, and with $display the display
     * from turning off, as during a long task or while a video plays,
     * until the function it returns is called (mygo's KeepAwake):
     *
     *     $release = Power::keepAwake('Exporting the video', false);
     *     try { ... } finally { $release(); }
     *
     * $reason shows where the system lists what keeps it awake.
     */
    public static function keepAwake(string $reason, bool $display): Closure
    {
        self::needsApp();
        $opts = (1 << 20) | ($display ? 1 << 40 : 0); // NSActivityIdleSystemSleepDisabled, NSActivityIdleDisplaySleepDisabled
        $info = ObjC::msg('p:', ObjC::cls('NSProcessInfo'), 'processInfo');
        $token = ObjC::msg('p:', ObjC::msg('p:Lp', $info, 'beginActivityWithOptions:reason:', $opts, ObjC::str($reason)), 'retain');
        return function () use (&$token, $info): void {
            if ($token === null) {
                return; // once
            }
            ObjC::msg('v:p', $info, 'endActivity:', $token);
            ObjC::msg('v:', $token, 'release');
            $token = null;
        };
    }

    /** Whether the Mac runs on battery power (mygo's IsOnBattery). */
    public static function isOnBattery(): bool
    {
        self::$io ??= \FFI::cdef('void* IOPSCopyPowerSourcesInfo(void); void* IOPSGetProvidingPowerSourceType(void*); void CFRelease(void*);', '/System/Library/Frameworks/IOKit.framework/IOKit');
        $info = self::$io->IOPSCopyPowerSourcesInfo();
        if ($info === null) {
            return false;
        }
        $type = self::$io->IOPSGetProvidingPowerSourceType($info);
        $battery = $type !== null && ObjC::phpStr($type) === 'Battery Power';
        self::$io->CFRelease($info);
        return $battery;
    }

    /** @internal UI\MoviePlayer: the charge of the Mac's own battery in percent (IOKit's Current Capacity of its InternalBattery), null for a Mac of none. */
    public static function batteryLevel(): ?int
    {
        self::$ps ??= \FFI::cdef('void* IOPSCopyPowerSourcesInfo(void); void* IOPSCopyPowerSourcesList(void*); void* IOPSGetPowerSourceDescription(void*, void*); void CFRelease(void*);', '/System/Library/Frameworks/IOKit.framework/IOKit');
        $info = self::$ps->IOPSCopyPowerSourcesInfo();
        if ($info === null) {
            return null;
        }
        $list = self::$ps->IOPSCopyPowerSourcesList($info);
        $level = null;
        $sources = ObjC::import($list);
        for ($i = 0, $n = $sources === null ? 0 : ObjC::msg('L:', $sources, 'count'); $i < $n && $level === null; $i++) {
            $d = self::$ps->IOPSGetPowerSourceDescription($info, self::$ps->cast('void*', ObjC::addr(ObjC::msg('p:L', $sources, 'objectAtIndex:', $i))));
            $desc = $d === null ? null : ObjC::import($d);
            $type = $desc === null ? null : ObjC::msg('p:p', $desc, 'objectForKey:', ObjC::str('Type'));
            if ($type === null || ObjC::phpStr($type) !== 'InternalBattery') { continue; }
            $cap = ObjC::msg('p:p', $desc, 'objectForKey:', ObjC::str('Current Capacity'));
            $level = $cap === null ? null : ObjC::msg('l:', $cap, 'integerValue');
        }
        if ($list !== null) { self::$ps->CFRelease($list); }
        self::$ps->CFRelease($info);
        return $level;
    }

    /** How long, in seconds, the user has not used the keyboard or the mouse (mygo's IdleTime). */
    public static function idleTime(): float
    {
        self::needsApp();
        self::$cg ??= \FFI::cdef('double CGEventSourceSecondsSinceLastEventType(int, uint32_t);', '/System/Library/Frameworks/CoreGraphics.framework/CoreGraphics');
        return self::$cg->CGEventSourceSecondsSinceLastEventType(0, 0xffffffff); // the combined session state, any input event
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
