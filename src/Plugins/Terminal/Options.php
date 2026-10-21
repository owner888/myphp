<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Terminal;

use Closure;

/** Configure a terminal (mygo's terminal.Options). */
final class Options
{
    /**
     * @param list<string> $command
     * @param list<string> $env
     * @param list<Keybind> $keybinds
     * @param ?Closure(string): void $onTitle
     * @param ?Closure(int): void $onExit
     * @param ?Closure(): void $onBell
     * @param ?Closure(string, string): void $onNotify
     * @param ?Closure(PasteProgress): void $onPasteStatus
     * @param ?Closure(string): bool $openLink
     * @param ?Closure(\MyPHP\Transfer\Data): bool $onPaste
     * @param ?Closure(int, int): bool $onKey
     * @param ?Closure(int): bool $onScroll
     * @param ?Closure(Zoom): bool $onZoom
     */
    public function __construct(
        /** The program the terminal runs and its arguments. Empty runs the user's shell as a login shell, as terminal apps do: $SHELL, else /bin/zsh on macOS and /bin/sh elsewhere. */
        public array $command = [],
        /** The working directory of the program: the user's home directory when empty. */
        public string $dir = '',
        /** Adds to the environment of the program, as "KEY=value". The terminal sets TERM=xterm-256color and COLORTERM=truecolor, and LANG to a UTF-8 locale when no locale is set, as when the app starts from the Finder. */
        public array $env = [],
        /** Connects the terminal to something other than a program, as a remote shell; $command, $dir and $env are then ignored. */
        public ?Conn $conn = null,
        /** The font of the text: the system's monospaced font at 13 points when unset. */
        public Font $font = new Font,
        /** The colors, and those in dark windows when $darkTheme is set, as Ghostty's theme = light:…,dark:…; without either, the terminal follows the window's light or dark appearance, with Terminal::lightTheme() and Terminal::darkTheme(). */
        public ?Theme $theme = null,
        public ?Theme $darkTheme = null,
        /** The shape of the cursor, which programs may change; $noBlink keeps it from blinking unless a program asks it to. */
        public CursorStyle $cursor = CursorStyle::CursorBlock,
        public bool $noBlink = false,
        /** About how many bytes of output the terminal keeps above the screen: 10 MB when 0, none when negative. */
        public int $scrollback = 0,
        /** Makes Option the Alt key of programs (Meta), rather than the key that types accented letters and symbols. */
        public bool $optionAsAlt = false,
        /** Copies what the pointer selects as the selection ends, as Ghostty's copy-on-select. */
        public CopyOnSelect $copyOnSelect = CopyOnSelect::CopyOnSelectNone,
        /** Bind keys to actions over Terminal::defaultKeybinds(), as Ghostty's keybind option: a binding replaces the defaults of its key and modifiers, and one to ActionNone unbinds them. $noDefaultKeybinds starts from none. */
        public array $keybinds = [],
        public bool $noDefaultKeybinds = false,
        /** What a secondary click does, as Ghostty's right-click-action. A program that takes the mouse gets the click instead, unless Shift is held. */
        public RightClickAction $rightClick = RightClickAction::RightClickContextMenu,
        /** Keeps programs from writing to the clipboard (OSC 52, OSC 5522), as Ghostty's clipboard-write = deny; their reads go unanswered, but for what a paste offers them. */
        public bool $noClipboardWrite = false,
        /** Leaves the terminal's background undrawn, so that what is under the view shows through, as Ghostty's background-opacity; cells with a background of their own still draw it. */
        public bool $transparent = false,
        /** When a program sets the title, when the program (or Conn) ends, when it rings the bell, and when it asks for a desktop notification (OSC 9, OSC 777). */
        public ?Closure $onTitle = null,
        public ?Closure $onExit = null,
        public ?Closure $onBell = null,
        public ?Closure $onNotify = null,
        /** How a paste goes that a program reads itself (mode 5522): offered, then sending as the program reads, then sent; or not taken 5 seconds after the offer was written. */
        public ?Closure $onPasteStatus = null,
        /** Takes what a Command+click opens: a hyperlink (OSC 8), a URL printed as text, or, only with it set, a file path printed as text; it reports whether it opened the link. A hyperlink or a URL it leaves, or every one without it, opens with the system, and a path it leaves does nothing. */
        public ?Closure $openLink = null,
        /** Sees a paste of the clipboard before the program gets it, with every format the clipboard holds, and reports whether it took it. */
        public ?Closure $onPaste = null,
        /** Sees a key press, its modifiers and key (Key::*), before the keybinds and before the terminal encodes it, and reports whether it took it. */
        public ?Closure $onKey = null,
        /** Sees the wheel scroll the view by whole rows, negative up, before the terminal scrolls its scrollback, and reports whether it took it. */
        public ?Closure $onScroll = null,
        /** Sees a zoom: the wheel with Command held, or a key bound to a font size action; it reports whether it took it. */
        public ?Closure $onZoom = null,
    ) {}
}
