<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Terminal;

/** What copied text to the clipboard (Options::$onCopy, mygo's terminal.CopySource). */
enum CopySource: int
{
    /** The user's copy: ActionCopy's keys, the context menu's Copy, or a secondary click that copies (RightClickCopy, RightClickCopyOrPaste). */
    case CopyUser = 0;
    /** A selection Options::$copyOnSelect copied as it ended, or as Select All made it. */
    case CopySelect = 1;
    /** A program's write to the clipboard (OSC 52). */
    case CopyProgram = 2;
}
