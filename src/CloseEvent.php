<?php
declare(strict_types=1);

namespace MyPHP;

/** Given to a window's 'close' listeners before it closes; cancel() keeps it open. */
final class CloseEvent
{
    public bool $canceled = false;

    public function __construct(public readonly Window $window) {}

    public function cancel(): void
    {
        $this->canceled = true;
    }
}
