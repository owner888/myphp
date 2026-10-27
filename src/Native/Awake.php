<?php
declare(strict_types=1);

namespace MyPHP\Native;

/**
 * Keeps the display awake, its screen saver off, while something plays,
 * as IINA's SleepPreventer: a ProcessInfo activity of idle display sleep
 * disabled, begun and ended (`pmset -g assertions` lists it while it
 * lasts).
 *
 * @internal Media\Player
 */
final class Awake
{
    /** NSActivityIdleDisplaySleepDisabled. */
    private const IDLE_DISPLAY_SLEEP_DISABLED = 1 << 40;

    private $token = null;

    public function __construct(private readonly string $reason) {}

    /** Keeps the display awake until release(); held already, nothing more. */
    public function hold(): void
    {
        if ($this->token !== null) {
            return;
        }
        ObjC::boot(['Foundation']);
        $info = ObjC::msg('p:', ObjC::cls('NSProcessInfo'), 'processInfo');
        $this->token = ObjC::msg('p:', ObjC::msg('p:Lp', $info, 'beginActivityWithOptions:reason:', self::IDLE_DISPLAY_SLEEP_DISABLED, ObjC::str($this->reason)), 'retain');
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
