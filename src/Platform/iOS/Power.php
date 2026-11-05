<?php
declare(strict_types=1);

namespace MyPHP\Platform\iOS;

use Closure;
use MyPHP\Native\iOS;
use MyPHP\Platform;

final class Power implements Platform\Power
{
    private int $keepAwakeCount = 0;

    public function watch(): void
    {
    }

    public function keepAwake(bool $display, string $reason): Closure
    {
        $this->keepAwakeCount++;
        $this->updateScreenState();

        $released = false;
        return function () use (&$released): void {
            if ($released) {
                return;
            }
            $released = true;
            $this->keepAwakeCount = max(0, $this->keepAwakeCount - 1);
            $this->updateScreenState();
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

    private function updateScreenState(): void
    {
        iOS::ffi()->myphp_ios_set_idle_timer_disabled($this->keepAwakeCount > 0 ? 1 : 0);
    }
}
