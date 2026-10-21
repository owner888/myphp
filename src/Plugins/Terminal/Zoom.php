<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Terminal;

/** A change of the font's size the user asks for (Options::$onZoom, mygo's terminal.Zoom). */
final class Zoom
{
    public function __construct(
        /** How many steps, larger positive: a notch of the wheel rolled up, or a press of a key bound to ActionIncreaseFontSize, is 1. */
        public int $steps = 0,
        /** Asks for the size of the font back (ActionResetFontSize). */
        public bool $reset = false,
    ) {}
}
