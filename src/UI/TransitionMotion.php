<?php
declare(strict_types=1);

namespace MyPHP\UI;

/**
 * Where a node appearing comes from, or where one going goes, from its
 * place in the layout (mygo's Motion, which is the name of the helpers
 * here): an ElementTransition's enter and exit.
 */
final class TransitionMotion
{
    public function __construct(
        /** Moves it by as many points. */
        public readonly float $x = 0.0,
        public readonly float $y = 0.0,
        /** How opaque it is there: 0, as by default, transparent, and 1 opaque. */
        public readonly float $opacity = 0.0,
        /** Makes it nothing along its parent's direction: no height in a column, no width in a row; its content keeps its layout, cut off. */
        public readonly bool $collapse = false,
        /** How large it is there, around its transform origin: 0.9 a tenth smaller; 0, as by default, keeps it at its size, as 1 does. */
        public readonly float $scale = 0.0,
        /** Turns it by as many degrees clockwise. */
        public readonly float $rotate = 0.0,
    ) {}

    /** Whether it makes a node appear or go even without motion: it fades, and collapses nothing, which would move its siblings. */
    public function fades(): bool
    {
        return $this->opacity < 1 && !$this->collapse;
    }
}
