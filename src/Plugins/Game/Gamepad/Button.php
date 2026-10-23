<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Game\Gamepad;

/** A button of the standard layout, named by place (mygo's gamepad.Button): South is A on an Xbox controller, Cross on a PlayStation one, B on a Nintendo one; in the order of the W3C's standard gamepad. */
enum Button: int
{
    case South = 0;
    case East = 1;
    case West = 2;
    case North = 3;
    case LeftShoulder = 4;
    case RightShoulder = 5;
    /** Analog: its value is from 0 to 1. */
    case LeftTrigger = 6;
    case RightTrigger = 7;
    /** View, Select, Share, Minus. */
    case Back = 8;
    case Start = 9;
    case LeftStickButton = 10;
    case RightStickButton = 11;
    case Up = 12;
    case Down = 13;
    case Left = 14;
    case Right = 15;
    /** The Xbox, PlayStation or Home button, where the system leaves it. */
    case Guide = 16;
}
