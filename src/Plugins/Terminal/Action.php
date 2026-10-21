<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Terminal;

/** What a key bound to it does (Keybind), as an action of Ghostty's keybind option (mygo's terminal.Action). */
enum Action: int
{
    /** Unbinds a key, which then goes to the program, as Ghostty's unbind. */
    case ActionNone = 0;
    /** Takes the key and does nothing, as Ghostty's ignore. */
    case ActionIgnore = 1;
    /** Copies the selection, as copy_to_clipboard; with a performable binding it also lets go of it, so that the key's next press reaches the program. */
    case ActionCopy = 2;
    /** Pastes the clipboard, as paste_from_clipboard. */
    case ActionPaste = 3;
    /** Selects the scrollback and the screen, as select_all. */
    case ActionSelectAll = 4;
    /** Clears the scrollback and the screen above the cursor's line, which moves to the top, as clear_screen; not on a full-screen program's screen. */
    case ActionClearScreen = 5;
    /** Zoom a step in or out, or back to the font's size (see Options::$onZoom). */
    case ActionIncreaseFontSize = 6;
    case ActionDecreaseFontSize = 7;
    case ActionResetFontSize = 8;
    /** Scroll the scrollback; not on a full-screen program's screen, which has none. */
    case ActionScrollPageUp = 9;
    case ActionScrollPageDown = 10;
    case ActionScrollToTop = 11;
    case ActionScrollToBottom = 12;
}
