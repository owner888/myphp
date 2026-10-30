<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Terminal;

/** When a command that finished tells the user (Options::$notifyOnCommandFinish), as Ghostty's notify-on-command-finish. */
enum NotifyOnCommandFinish: int
{
    /** Never. */
    case Never = 0;
    /** While the terminal's view has not the focus. */
    case Unfocused = 1;
    /** Whatever has the focus. */
    case Always = 2;
}
