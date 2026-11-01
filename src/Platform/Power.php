<?php
declare(strict_types=1);

namespace MyPHP\Platform;

use Closure;

/** Power and session events, and keeping the computer awake (mygo's platform.Power). */
interface Power
{
    /** Starts reporting AppHandler::powerEvent. */
    public function watch(): void;

    /** Keeps the system, with $display the display too, from sleeping while idle until the function it returns is called. */
    public function keepAwake(bool $display, string $reason): Closure;

    public function onBattery(): bool;

    /** Seconds since the last input. */
    public function idleTime(): float;

    /** The charge of the computer's own battery in percent, null without one (myphp's, for the player). */
    public function batteryLevel(): ?int;
}
