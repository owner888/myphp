<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Loading;

/**
 * How long an indicator has run, in nanoseconds of the frames' clock:
 * since $start, or $held while paused; and the shape it draws with, kept
 * from frame to frame (mygo's loading timer).
 *
 * @internal Loading
 */
final class Timer
{
    public int $held = 0;
    public bool $paused = false;
    public Shape $sh;

    public function __construct(public int $start)
    {
        $this->sh = new Shape();
    }
}
