<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Terminal;

/** The shape of the cursor (mygo's terminal.CursorStyle). */
enum CursorStyle: int
{
    case CursorBlock = 0;
    case CursorBar = 1;
    case CursorUnderline = 2;
}
