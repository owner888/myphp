<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Terminal;

/** What a secondary click does (Options::$rightClick), as Ghostty's right-click-action (mygo's terminal.RightClickAction). */
enum RightClickAction: int
{
    /** Shows the context menu: Copy, Paste, Select All and Clear Scrollback. */
    case RightClickContextMenu = 0;
    /** Lets go of the selection and pastes. */
    case RightClickPaste = 1;
    /** Copies the selection and lets go of it. */
    case RightClickCopy = 2;
    /** Copies the selection and lets go of it, or pastes when there is none, as Windows Terminal's secondary click. */
    case RightClickCopyOrPaste = 3;
    /** Does nothing. */
    case RightClickIgnore = 4;
}
