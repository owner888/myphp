<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Terminal;

/** The room around a terminal's grid, in points (Options::$padding, mygo's terminal.Padding). */
final class Padding
{
    public function __construct(
        /** Across, on the left and the right. */
        public float $x = 0.0,
        /** Down, above and below. */
        public float $y = 0.0,
    ) {}
}
