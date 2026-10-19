<?php
declare(strict_types=1);

namespace MyPHP\UI;

use Closure;

/** How a node the pointer drags moves (Context::draggable), and where it comes to rest once let go (mygo's DraggableOptions). */
final class DraggableOptions
{
    public function __construct(
        /** The direction it moves in: Node::HORIZONTAL, VERTICAL, or, when 0, both. */
        public readonly int $axis = 0,
        /**
         * Holds its offset at rest within [x, y, w, h]: from x to x + w across
         * and y to y + h down. The pointer drags it past them less and less
         * far, as iOS holds content pulled past its edges (Motion::rubberband),
         * and it goes back within them once let go; $rigid stops it at them.
         * @var ?array{0: float, 1: float, 2: float, 3: float}
         */
        public readonly ?array $bounds = null,
        public readonly bool $rigid = false,
        /**
         * Chooses where it comes to rest once let go, from where the throw
         * would carry it (Motion::project, held within $bounds), as the nearest
         * of a sheet's heights or a pager's pages (Motion::nearest): it glides
         * there. Without it, it rests where the pointer lets it go. What it
         * returns along an axis the node does not move along is ignored.
         * @var ?Closure(array{0: float, 1: float}): array{0: float, 1: float}
         */
        public readonly ?Closure $snap = null,
        /** How it goes to rest, and to an offset the app sets: Animation::smooth()'s spring when null. A spring starts at the pointer's velocity as it lets go. */
        public readonly ?Animation $animation = null,
    ) {}
}
