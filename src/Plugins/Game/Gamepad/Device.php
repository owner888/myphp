<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Game\Gamepad;

/**
 * A pad as a platform knows it (mygo's device).
 *
 * @internal
 */
interface Device
{
    /** Identifies the device while it is connected. */
    public function key(): string;

    public function name(): string;

    /** Reads the device's controls, or null once it is gone. */
    public function read(): ?State;

    public function close(): void;
}
