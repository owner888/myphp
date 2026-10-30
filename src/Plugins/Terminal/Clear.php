<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Terminal;

/** What the user clears (Options::$onClear, mygo's terminal.Clear). */
enum Clear: int
{
    /** Clears the scrollback and the screen above the cursor's line (ActionClearScreen and the context menu's Clear, Terminal::clearScreen). */
    case ClearScreen = 0;
}
