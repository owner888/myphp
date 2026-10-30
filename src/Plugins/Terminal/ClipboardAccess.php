<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Terminal;

/** What programs get when they read the clipboard (Options::$clipboardRead, mygo's terminal.ClipboardAccess). */
enum ClipboardAccess: int
{
    /** Refuses a read: an empty clipboard for OSC 52, an EPERM status for the Kitty protocol's OSC 5522. Reads stay refused until an app opts in. */
    case ClipboardDeny = 0;
    /** Answers a read with what the clipboard holds. */
    case ClipboardAllow = 1;
    /** Asks the app first: Options::$onClipboardRequest when set, else the terminal's own prompt in the view. */
    case ClipboardAsk = 2;
}
