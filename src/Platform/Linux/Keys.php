<?php
declare(strict_types=1);

namespace MyPHP\Platform\Linux;

use MyPHP\UI\Key;

/**
 * GDK's keys as the view on macOS gives them to Input: the macOS virtual
 * key code of the key, by its place on the keyboard (the X key code, evdev's
 * plus 8, of an ANSI keyboard) or, for the keys that type nothing, its
 * keyval; the characters it types, AppKit's private-use characters for the
 * function keys; and the text system's command of a key, by AppKit's
 * selector, as GTK's text entries bind them.
 */
final class Keys
{
    /** macOS key codes by X key code. */
    private const CODES = [
        9 => Key::ESCAPE, 10 => Key::N1, 11 => Key::N2, 12 => Key::N3, 13 => Key::N4, 14 => Key::N5, 15 => Key::N6, 16 => Key::N7, 17 => Key::N8, 18 => Key::N9, 19 => Key::N0,
        20 => Key::MINUS, 21 => Key::EQUAL, 22 => Key::DELETE, 23 => Key::TAB, 24 => Key::Q, 25 => Key::W, 26 => Key::E, 27 => Key::R, 28 => Key::T, 29 => Key::Y,
        30 => Key::U, 31 => Key::I, 32 => Key::O, 33 => Key::P, 34 => Key::BRACKET_LEFT, 35 => Key::BRACKET_RIGHT, 36 => Key::RETURN, 38 => Key::A, 39 => Key::S,
        40 => Key::D, 41 => Key::F, 42 => Key::G, 43 => Key::H, 44 => Key::J, 45 => Key::K, 46 => Key::L, 47 => Key::SEMICOLON, 48 => Key::QUOTE, 49 => Key::GRAVE,
        51 => Key::BACKSLASH, 52 => Key::Z, 53 => Key::X, 54 => Key::C, 55 => Key::V, 56 => Key::B, 57 => Key::N, 58 => Key::M, 59 => Key::COMMA, 60 => Key::PERIOD,
        61 => Key::SLASH, 65 => Key::SPACE, 67 => Key::F1, 68 => Key::F2, 69 => Key::F3, 70 => Key::F4, 71 => Key::F5, 72 => Key::F6, 73 => Key::F7, 74 => Key::F8,
        75 => Key::F9, 76 => Key::F10, 95 => Key::F11, 96 => Key::F12, 104 => Key::ENTER, 110 => Key::HOME, 111 => Key::UP, 112 => Key::PAGE_UP, 113 => Key::LEFT,
        114 => Key::RIGHT, 115 => Key::END, 116 => Key::DOWN, 117 => Key::PAGE_DOWN, 118 => Key::INSERT, 119 => Key::FORWARD_DELETE, 135 => Key::CONTEXT_MENU,
    ];

    /** macOS key codes by keyval, for the keys that type nothing, whatever key code the keyboard gives them (the keypad's too). */
    private const KEYVALS = [
        0xff0d => Key::RETURN, 0xff8d => Key::ENTER, 0xff1b => Key::ESCAPE, 0xff08 => Key::DELETE, 0xff09 => Key::TAB, 0xfe20 => Key::TAB,
        0xffff => Key::FORWARD_DELETE, 0xff9f => Key::FORWARD_DELETE, 0xff63 => Key::INSERT, 0xff50 => Key::HOME, 0xff95 => Key::HOME,
        0xff57 => Key::END, 0xff9c => Key::END, 0xff55 => Key::PAGE_UP, 0xff9a => Key::PAGE_UP, 0xff56 => Key::PAGE_DOWN, 0xff9b => Key::PAGE_DOWN,
        0xff51 => Key::LEFT, 0xff96 => Key::LEFT, 0xff52 => Key::UP, 0xff97 => Key::UP, 0xff53 => Key::RIGHT, 0xff98 => Key::RIGHT,
        0xff54 => Key::DOWN, 0xff99 => Key::DOWN, 0xff67 => Key::CONTEXT_MENU, 0x1008ff26 => Key::BACK, 0x1008ff27 => Key::FORWARD,
    ];

    /** AppKit's characters of the keys that type none (NSUpArrowFunctionKey…), by macOS key code, and of Return, Tab, Delete and Escape. */
    private const CHARS = [
        Key::UP => 0xF700, Key::DOWN => 0xF701, Key::LEFT => 0xF702, Key::RIGHT => 0xF703, Key::F1 => 0xF704, Key::F2 => 0xF705, Key::F3 => 0xF706,
        Key::F4 => 0xF707, Key::F5 => 0xF708, Key::F6 => 0xF709, Key::F7 => 0xF70A, Key::F8 => 0xF70B, Key::F9 => 0xF70C, Key::F10 => 0xF70D,
        Key::F11 => 0xF70E, Key::F12 => 0xF70F, Key::INSERT => 0xF727, Key::FORWARD_DELETE => 0xF728, Key::HOME => 0xF729, Key::END => 0xF72B,
        Key::PAGE_UP => 0xF72C, Key::PAGE_DOWN => 0xF72D, Key::RETURN => 0x0D, Key::ENTER => 0x03, Key::TAB => 0x09, Key::DELETE => 0x7F, Key::ESCAPE => 0x1B,
    ];

    /** The modifier keys' keyvals and the GDK masks they set: Shift, Control, Alt, Meta, Super, Hyper. */
    private const MODIFIERS = [0xffe1 => 1, 0xffe2 => 1, 0xffe3 => 4, 0xffe4 => 4, 0xffe9 => 8, 0xffea => 8, 0xffe7 => 1 << 28, 0xffe8 => 1 << 28,
        0xffeb => 1 << 26, 0xffec => 1 << 26, 0xffed => 1 << 27, 0xffee => 1 << 27, 0xffe5 => 2];

    /** The macOS key code of a key, and the characters it types (AppKit's for the function keys). @return array{0: int, 1: string} */
    public static function of(int $keyval, int $xcode): array
    {
        $code = self::KEYVALS[$keyval] ?? self::CODES[$xcode] ?? ($keyval >= 0xffbe && $keyval <= 0xffc9 ? [Key::F1, Key::F2, Key::F3, Key::F4, Key::F5, Key::F6, Key::F7, Key::F8, Key::F9, Key::F10, Key::F11, Key::F12][$keyval - 0xffbe] : -1);
        if (isset(self::KEYVALS[$keyval]) || ($keyval >= 0xffbe && $keyval <= 0xffc9)) {
            return [$code, isset(self::CHARS[$code]) ? mb_chr(self::CHARS[$code], 'UTF-8') : ''];
        }
        $u = Gtk::ffi()->gdk_keyval_to_unicode($keyval);
        return [$code, $u === 0 ? '' : mb_chr($u, 'UTF-8')];
    }

    public static function isModifier(int $keyval): bool
    {
        return isset(self::MODIFIERS[$keyval]);
    }

    /** The GDK mask a modifier key sets as it is pressed, which its own event's state does not have yet. */
    public static function modifierMask(int $keyval): int
    {
        return self::MODIFIERS[$keyval] ?? 0;
    }

    /** Whether a key's characters type: not a control character nor one of AppKit's function keys. */
    public static function types(string $chars): bool
    {
        return $chars !== '' && ord($chars[0]) >= 0x20 && ord($chars[0]) !== 0x7f && preg_match('/[\x{F700}-\x{F8FF}]/u', $chars) !== 1;
    }

    /** The Edit menu's command of Control (as Command) and a key: Cut, Copy, Paste, Select All, Undo, Redo (and Control+Y); null for none. */
    public static function editCommand(int $code, int $mods): ?string
    {
        $shift = ($mods & Key::SHIFT) !== 0;
        if (($mods & (Key::OPTION | Key::CONTROL)) !== 0) {
            return null;
        }
        return match ($code) {
            Key::X => $shift ? null : 'cut', Key::C => $shift ? null : 'copy', Key::V => $shift ? 'pasteAndMatchStyle' : 'paste',
            Key::A => $shift ? null : 'selectAll', Key::Z => $shift ? 'redo' : 'undo', Key::Y => $shift ? null : 'redo',
            default => null,
        };
    }

    /**
     * The text system's command of a key that types nothing, by AppKit's
     * selector, as GTK's entries bind the keys: Control (as Command) moves
     * by words and to the document's ends, Shift extends the selection.
     */
    public static function selector(int $code, int $mods): ?string
    {
        $shift = ($mods & Key::SHIFT) !== 0;
        $word = ($mods & Key::COMMAND) !== 0;
        $move = fn(string $s): string => $shift ? substr($s, 0, -1) . 'AndModifySelection:' : $s;
        return match ($code) {
            Key::LEFT => $move($word ? 'moveWordLeft:' : 'moveLeft:'),
            Key::RIGHT => $move($word ? 'moveWordRight:' : 'moveRight:'),
            Key::UP => $move($word ? 'moveToBeginningOfParagraph:' : 'moveUp:'),
            Key::DOWN => $move($word ? 'moveToEndOfParagraph:' : 'moveDown:'),
            Key::HOME => $move($word ? 'moveToBeginningOfDocument:' : 'moveToBeginningOfLine:'),
            Key::END => $move($word ? 'moveToEndOfDocument:' : 'moveToEndOfLine:'),
            Key::PAGE_UP => $move('pageUp:'),
            Key::PAGE_DOWN => $move('pageDown:'),
            Key::DELETE => $word ? 'deleteWordBackward:' : 'deleteBackward:',
            Key::FORWARD_DELETE => $word ? 'deleteWordForward:' : 'deleteForward:',
            Key::RETURN, Key::ENTER => 'insertNewline:',
            Key::TAB => $shift ? 'insertBacktab:' : 'insertTab:',
            Key::ESCAPE => 'cancelOperation:',
            default => null,
        };
    }
}
