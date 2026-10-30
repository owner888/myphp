<?php
declare(strict_types=1);

namespace MyPHP\Audio;

/**
 * The system's audio output, or one standing in for it (mygo's device):
 * once started it asks Output::render for what to play as it needs it,
 * until closed.
 * @internal
 */
interface Device
{
    public function start(): void;

    public function close(): void;

    /** How many frames it holds ahead of what is heard, as it opened; what it was written before is not among them. */
    public function held(): int;
}
