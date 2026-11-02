<?php
declare(strict_types=1);

namespace MyPHP\Platform;

/** Keeps the display awake, its screen saver off, while something plays (Media::awake), or only the system (setDisplay). */
interface Awake
{
    /** Keeps the display awake until release(); held already, nothing more. */
    public function hold(): void;

    /** Keeps the display awake ($display), or only the system; held, taken again so. */
    public function setDisplay(bool $display): void;

    /** Lets the display sleep again. */
    public function release(): void;

    public function held(): bool;
}
