<?php
declare(strict_types=1);

namespace MyPHP\Transfer;

use Closure;

/** How a native drag source drags (mygo's transfer.DragOptions). */
final class DragOptions
{
    public function __construct(
        /** The effects it allows, Operation::COPY and MOVE: copy when none. */
        public readonly int $operations = Operation::NONE,
        /** The image dragged, shown at one pixel per point, copied as the drag starts, and the point of it under the pointer, [x, y] in its pixels; for a node's drag, the node as the last frame showed it when null. */
        public readonly ?\MyPHP\UI\Image $preview = null,
        public readonly array $hotspot = [0, 0],
        /** Called once on the main thread with the Result, cancellation and failure included. Delete or remove the original only after a successful move: nothing is deleted for you. @var ?Closure(Result): void */
        public readonly ?Closure $done = null,
    ) {}
}
