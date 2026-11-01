<?php
declare(strict_types=1);

namespace MyPHP\Platform\Darwin;

use Closure;
use MyPHP\Native\ObjC;
use MyPHP\Platform;

/** NSWorkspace's sleep and wake, the screen's lock, NSProcessInfo's activities and IOKit's power sources (mygo's darwin power.go). */
final class Power implements Platform\Power
{
    private const NOTIFICATIONS = [
        'NSWorkspaceWillSleepNotification' => 'suspend',
        'NSWorkspaceDidWakeNotification' => 'resume',
        'com.apple.screenIsLocked' => 'lockScreen',
        'com.apple.screenIsUnlocked' => 'unlockScreen',
    ];
    private static ?\FFI $io = null, $cg = null, $ps = null;
    private bool $watching = false;

    public function __construct(private readonly Backend $b) {}

    public function watch(): void
    {
        if ($this->watching) {
            return;
        }
        $this->watching = true;
        $h = $this->b->h;
        ObjC::defineClass('MyPHPPowerObserver', 'NSObject', [
            'notified:' => ['v@:@', 'v(ppp)', function ($self, $cmd, $note) use ($h) {
                $event = self::NOTIFICATIONS[ObjC::phpStr(ObjC::msg('p:', $note, 'name'))] ?? null;
                if ($event !== null) { $h->powerEvent($event); }
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

    public function keepAwake(bool $display, string $reason): Closure
    {
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

    public function onBattery(): bool
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

    public function batteryLevel(): ?int
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

    public function idleTime(): float
    {
        self::$cg ??= \FFI::cdef('double CGEventSourceSecondsSinceLastEventType(int, uint32_t);', '/System/Library/Frameworks/CoreGraphics.framework/CoreGraphics');
        return self::$cg->CGEventSourceSecondsSinceLastEventType(0, 0xffffffff); // the combined session state, any input event
    }
}
