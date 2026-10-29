<?php
declare(strict_types=1);

namespace MyPHP\Transfer;

use MyPHP\Point;

/**
 * How a native drag ended (mygo's transfer.Result): the destination's effect, none for no drop; $canceled, that it
 * ended without a drop, let go over no target or given up; $escaped, that a canceled drag was given up (Escape,
 * Window::cancelDataDrag, or the source window closing) rather than let go over no target; $point, where it ended, in
 * the screen coordinates of Screen::cursorScreenPoint.
 */
final class Result
{
    public readonly Point $point;

    public function __construct(
        public readonly int $operation = Operation::NONE,
        public readonly bool $canceled = false,
        public readonly ?\Throwable $error = null,
        public readonly bool $escaped = false,
        ?Point $point = null,
    ) {
        $this->point = $point ?? new Point(0, 0);
    }
}
