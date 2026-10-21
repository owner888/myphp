<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Terminal\Vt;

/** The cursor of a frame (mygo's vt.Cursor). @internal */
final class Cursor
{
    public function __construct(
        /** The cursor is in the viewport, at column $x of row $y; $wideTail: on the second half of a wide character. */
        public bool $inView = false,
        public int $x = 0,
        public int $y = 0,
        public bool $wideTail = false,
        /** Programs show it, it blinks, the program reads a password. */
        public bool $visible = false,
        public bool $blinking = false,
        public bool $password = false,
        public int $style = Terminal::CURSOR_BLOCK,
    ) {}
}
