<?php
declare(strict_types=1);

namespace MyPHP\Platform\Windows;

use Closure;
use FFI;
use MyPHP\Platform;

/**
 * Power and the session on Windows (mygo's windows power): sleep and
 * waking come to the app's window as WM_POWERBROADCAST, locking as
 * WM_WTSSESSION_CHANGE once registered; staying awake is the thread's
 * execution state, kept while anyone asks.
 */
final class Power implements Platform\Power
{
    private bool $watching = false;
    /** How many keep the system, and the display, awake. */
    private int $system = 0, $display = 0;

    public function __construct(private readonly Backend $b) {}

    public function watch(): void
    {
        if ($this->watching || $this->b->hwnd() === null) {
            return;
        }
        $this->watching = true;
        FFI::cdef('int32_t WTSRegisterSessionNotification(void *hwnd, uint32_t flags);', 'wtsapi32.dll')
            ->WTSRegisterSessionNotification($this->b->hwnd(), 0); // NOTIFY_FOR_THIS_SESSION
    }

    /** @internal a message of the app's window: WM_POWERBROADCAST or WM_WTSSESSION_CHANGE */
    public function message(int $msg, int $wp): void
    {
        if (!$this->watching) {
            return;
        }
        $event = $msg === Win32::WM_POWERBROADCAST
            ? match ($wp) { 0x4 => 'suspend', 0x12 => 'resume', default => null } // PBT_APMSUSPEND, PBT_APMRESUMEAUTOMATIC
            : match ($wp) { 0x7 => 'lockScreen', 0x8 => 'unlockScreen', default => null }; // WTS_SESSION_LOCK, WTS_SESSION_UNLOCK
        if ($event !== null) {
            $this->b->h->powerEvent($event);
        }
    }

    public function keepAwake(bool $display, string $reason): Closure
    {
        $this->system++;
        if ($display) { $this->display++; }
        $this->apply();
        $done = false;
        return function () use ($display, &$done): void {
            if ($done) { return; }
            $done = true;
            $this->system--;
            if ($display) { $this->display--; }
            $this->apply();
        };
    }

    private function apply(): void
    {
        Win32::kernel32()->SetThreadExecutionState(0x80000000 | ($this->system > 0 ? 0x1 : 0) | ($this->display > 0 ? 0x2 : 0)); // ES_CONTINUOUS, ES_SYSTEM_REQUIRED, ES_DISPLAY_REQUIRED
    }

    private function status(): ?FFI\CData
    {
        $k = Win32::kernel32();
        $s = $k->new('SYSTEM_POWER_STATUS');
        return $k->GetSystemPowerStatus(FFI::addr($s)) ? $s : null;
    }

    public function onBattery(): bool
    {
        return $this->status()?->ACLineStatus === 0;
    }

    public function idleTime(): float
    {
        $u = Win32::user32();
        $info = $u->new('LASTINPUTINFO');
        $info->cbSize = FFI::sizeof($info);
        if (!$u->GetLastInputInfo(FFI::addr($info))) {
            return 0.0;
        }
        return (((Win32::kernel32()->GetTickCount() - $info->dwTime) & 0xFFFFFFFF)) / 1000;
    }

    /** The battery's charge, null without one (BATTERY_FLAG_NO_BATTERY) or where Windows does not know it. */
    public function batteryLevel(): ?int
    {
        $s = $this->status();
        return $s === null || ($s->BatteryFlag & 0x80) !== 0 || $s->BatteryLifePercent === 255 ? null : $s->BatteryLifePercent;
    }
}
