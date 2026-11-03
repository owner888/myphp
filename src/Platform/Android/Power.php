<?php
declare(strict_types=1);

namespace MyPHP\Platform\Android;

use Closure;
use MyPHP\Native\Android;
use MyPHP\Platform;

/**
 * Android Power service: keep screen awake via FLAG_KEEP_SCREEN_ON.
 */
final class Power implements Platform\Power
{
    private int $awakeCount = 0;

    public function watch(): void {}

    public function keepAwake(bool $display, string $reason): Closure
    {
        $this->awakeCount++;
        if ($this->awakeCount === 1) {
            Android::ffi()->myphp_set_keep_screen_on(1);
        }

        $released = false;
        return function () use (&$released): void {
            if ($released) {
                return;
            }
            $released = true;
            $this->awakeCount = max(0, $this->awakeCount - 1);
            if ($this->awakeCount === 0) {
                Android::ffi()->myphp_set_keep_screen_on(0);
            }
        };
    }

    public function onBattery(): bool
    {
        return true;
    }

    public function idleTime(): float
    {
        return 0.0;
    }

    public function batteryLevel(): ?int
    {
        return null;
    }
}
