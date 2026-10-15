<?php
declare(strict_types=1);

namespace MyPHP\UI;

/** Keys for Context::shortcut(): the macOS virtual key codes of the keys that have no character, and the modifier masks. */
final class Key
{
    public const RETURN = 36, TAB = 48, SPACE = 49, DELETE = 51, ESCAPE = 53, ENTER = 76, HOME = 115, PAGE_UP = 116,
        FORWARD_DELETE = 117, END = 119, PAGE_DOWN = 121, LEFT = 123, RIGHT = 124, DOWN = 125, UP = 126;
    public const SHIFT = 1 << 17, CONTROL = 1 << 18, OPTION = 1 << 19, COMMAND = 1 << 20;
    public const MODIFIERS = self::SHIFT | self::CONTROL | self::OPTION | self::COMMAND;
}
