<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Terminal;

/** Where a selection is copied as it ends (Options::$copyOnSelect, mygo's terminal.CopyOnSelect). */
enum CopyOnSelect: int
{
    /** Copies nothing, as Ghostty's none. */
    case CopyOnSelectNone = 0;
    /** Copies to the clipboard, as Ghostty's clipboard. */
    case CopyOnSelectClipboard = 1;
}
