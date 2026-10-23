<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Game\Gamepad;

/** An axis of the sticks, from -1 to 1: right and down are positive, as on the screen (mygo's gamepad.Axis). */
enum Axis: int
{
    case LeftX = 0;
    case LeftY = 1;
    case RightX = 2;
    case RightY = 3;
}
