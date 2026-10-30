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
     * @param ?Closure(Clear): bool $onClear
     * @param ?Closure(string, CopySource): void $onCopy
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
        /** The colors, and those in dark windows when $darkTheme is set, as Ghostty's theme = light:…,dark:…; without either, the terminal follows the window's light or dark appearance, with Terminal::lightTheme() and Terminal::darkTheme(). Until a view shows the terminal, or useTheme() gives it other colors, programs asking its colors get $theme's. */
        public ?Theme $theme = null,
        public ?Theme $darkTheme = null,
        /** The shape of the cursor, which programs may change; $noBlink keeps it from blinking unless a program asks it to. */
        public CursorStyle $cursor = CursorStyle::CursorBlock,
        public bool $noBlink = false,
        /** About how many bytes of output the terminal keeps above the screen: 10 MB when 0, none when negative. */
        public int $scrollback = 0,
        /** Makes Option the Alt key of programs (Meta), rather than the key that types accented letters and symbols. */
        public bool $optionAsAlt = false,
        /** Copies what the pointer selects as the selection ends, and what Select All selects, as Ghostty's copy-on-select; Terminal::setCopyOnSelect changes it. */
        public CopyOnSelect $copyOnSelect = CopyOnSelect::CopyOnSelectNone,
        /** Bind keys to actions over Terminal::defaultKeybinds(), as Ghostty's keybind option: a binding replaces the defaults of its key and modifiers, and one to ActionNone unbinds them. $noDefaultKeybinds starts from none. */
        public array $keybinds = [],
        public bool $noDefaultKeybinds = false,
        /** What a secondary click does, as Ghostty's right-click-action. A program that takes the mouse gets the click instead, unless Shift is held. */
        public RightClickAction $rightClick = RightClickAction::RightClickContextMenu,
        /** Keeps programs from writing to the clipboard (OSC 52, OSC 5522), as Ghostty's clipboard-write = deny; reads go by $clipboardRead. */
        public bool $noClipboardWrite = false,
        /**
         * What programs get when they read the clipboard (OSC 52's "?", OSC
         * 5522): refused by default, as Ghostty's clipboard-read = deny, or
         * answered with what the clipboard holds, or the app asked first. The
         * clipboard is read and the answer written as the view builds its
         * next frame: a read of a terminal no view shows waits. What a paste
         * offered a program is answered whatever this is.
         */
        public ClipboardAccess $clipboardRead = ClipboardAccess::ClipboardDeny,
        /** Leaves the terminal's background undrawn, so that what is under the view shows through, as Ghostty's background-opacity; cells with a background of their own still draw it. */
        public bool $transparent = false,
        /** The room around the grid, in points, across ($x) and down ($y), as Ghostty's window-padding-x and window-padding-y: 6 across and 4 down when both are zero; a negative value leaves none. */
        public Padding $padding = new Padding,
        /** What the padding shows, as Ghostty's window-padding-color: the background, or the backgrounds of the cells nearest to it, so that a full-screen program's colors reach the edges of the view. */
        public PaddingColor $paddingColor = PaddingColor::PaddingBackground,
        /** The least contrast text has with its background, as Ghostty's minimum-contrast: a WCAG ratio from 1 (none, as when 0) to 21 (black on white). Text with less, as a program's light gray on a light theme, darkens or lightens as little as reaches it, keeping its hue; shapes (box drawing, blocks, Powerline's) keep their colors. Terminal::setMinimumContrast changes it. */
        public float $minimumContrast = 0.0,
        /** When a program sets the title, when the program (or Conn) ends, when it rings the bell, and when it asks for a desktop notification (OSC 9, OSC 777). */
        public ?Closure $onTitle = null,
        public ?Closure $onExit = null,
        public ?Closure $onBell = null,
        public ?Closure $onNotify = null,
        /** How a paste goes that a program reads itself (mode 5522): offered, then sending as the program reads, then sent; or not taken 5 seconds after the offer was written. */
        public ?Closure $onPasteStatus = null,
        /** Asks the app before a program reads the clipboard, with $clipboardRead ClipboardAsk: fn(ClipboardRequest $req), whose allow() or deny() answers it, then or later; a request left unanswered waits. Without it the terminal shows its own prompt in the view, one read at a time, with a line of what the program would read, and Allow and Deny. */
        public ?Closure $onClipboardRequest = null,
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
        /**
         * Sees the user's clear, before the terminal clears: ClearScreen for
         * ActionClearScreen's keys and the context menu's Clear. It reports
         * whether it took the clear; a clear taken clears nothing here. A
         * terminal that shows a server's takes it, for the server's
         * terminal to clear (Terminal::clearScreen), which the next frame
         * shows. It is not asked on a full-screen program's screen, which
         * ActionClearScreen leaves alone.
         */
        public ?Closure $onClear = null,
        /**
         * Runs after the terminal writes text to the clipboard: the user's
         * copy (ActionCopy's keys, the context menu, a secondary click), a
         * selection $copyOnSelect copied, or a program's (OSC 52), as its
         * CopySource tells. An app may say so, as Ghostty's
         * app-notifications = clipboard-copy does with a toast.
         */
        public ?Closure $onCopy = null,
        /**
         * When a command that finished tells the user, as Ghostty's
         * notify-on-command-finish: never, while the view has not the focus,
         * or always. The shell marks its commands (OSC 133), as fish and
         * Nushell do, or zsh and bash with a shell integration, or none is
         * told of.
         */
        public NotifyOnCommandFinish $notifyOnCommandFinish = NotifyOnCommandFinish::Never,
        /** How: the bell, a desktop notification through $onNotify, or both, as Ghostty's notify-on-command-finish-action. */
        public CommandFinishAction $notifyOnCommandFinishAction = new CommandFinishAction,
        /** How long, in seconds, a command must have run to tell of its end, as Ghostty's notify-on-command-finish-after. */
        public float $notifyOnCommandFinishAfter = 5.0,
        /** Starts zsh and bash, run alone as the user's shell is, with myphp's shell integration (ShellIntegration), which marks their prompts and commands (OSC 133), as Ghostty's shell-integration; false starts them as they are. */
        public bool $shellIntegration = true,
    ) {}
}
