<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Game\Gamepad;

/** A game controller, from when it connects until it disconnects; connected again, it is a Pad anew (mygo's gamepad.Pad). */
final class Pad
{
    /** @internal */
    public bool $connected = true;

    /** @internal */
    public function __construct(public readonly Device $dev, private readonly string $name) {}

    /** The pad's name, as its maker gives it. */
    public function name(): string
    {
        return $this->name;
    }

    /** Whether the pad is still connected. */
    public function connected(): bool
    {
        return $this->connected;
    }

    /** Reads the pad's controls now; a pad no longer connected reads nothing pressed. */
    public function state(): State
    {
        if (!$this->connected) {
            return new State;
        }
        $s = $this->dev->read();
        if ($s === null) {
            Gamepad::disconnect($this);
            return new State;
        }
        return $s;
    }
}
