<?php
declare(strict_types=1);

namespace MyPHP\Native;

/**
 * Keeps the display awake, its screen saver off, while something plays
 * (or only the system, setDisplay), as IINA's SleepPreventer: a ProcessInfo activity of idle display sleep
 * disabled, begun and ended (`pmset -g assertions` lists it while it
 * lasts).
 *
 * @internal Media\Player
 */
final class Awake
{
    /** NSActivityIdleDisplaySleepDisabled, NSActivityIdleSystemSleepDisabled. */
    private const IDLE_DISPLAY_SLEEP_DISABLED = 1 << 40, IDLE_SYSTEM_SLEEP_DISABLED = 1 << 20;

    private $token = null;
    /** Whether the display stays awake, else only the system (IINA's SleepPreventer's allowScreenSaver). */
    private bool $display = true;

    public function __construct(private readonly string $reason) {}

    /** Keeps the display awake until release(); held already, nothing more. */
    public function hold(): void
    {
        if ($this->token !== null) {
            return;
        }
        ObjC::boot(['Foundation']);
        $info = ObjC::msg('p:', ObjC::cls('NSProcessInfo'), 'processInfo');
        $this->token = ObjC::msg('p:', ObjC::msg('p:Lp', $info, 'beginActivityWithOptions:reason:', $this->display ? self::IDLE_DISPLAY_SLEEP_DISABLED : self::IDLE_SYSTEM_SLEEP_DISABLED, ObjC::str($this->reason)), 'retain');
    }

    /** Keeps the display awake ($display), its screen saver off, or only the system, the screen saver allowed; held, taken again so. */
    public function setDisplay(bool $display): void
    {
        if ($display === $this->display) {
            return;
        }
        $this->display = $display;
        if ($this->token !== null) {
            $this->release();
            $this->hold();
        }
    }

    /** Lets the display sleep again. */
    public function release(): void
    {
        if ($this->token === null) {
            return;
        }
        ObjC::msg('v:p', ObjC::msg('p:', ObjC::cls('NSProcessInfo'), 'processInfo'), 'endActivity:', $this->token);
        ObjC::msg('v:', $this->token, 'release');
        $this->token = null;
    }

    public function held(): bool
    {
        return $this->token !== null;
    }

    public function __destruct()
    {
        $this->release();
    }
}
