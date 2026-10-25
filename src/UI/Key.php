<?php
declare(strict_types=1);

namespace MyPHP\UI;

/** Keys for Context::shortcut(): the macOS virtual key codes of the keys that have no character, and the modifier masks. */
final class Key
{
    public const RETURN = 36, TAB = 48, SPACE = 49, DELETE = 51, ESCAPE = 53, ENTER = 76, HOME = 115, PAGE_UP = 116,
        FORWARD_DELETE = 117, END = 119, PAGE_DOWN = 121, LEFT = 123, RIGHT = 124, DOWN = 125, UP = 126;
    /** The keys with characters, by their place on an ANSI keyboard (kVK_ANSI_*), for bindings that name keys whatever the layout types (the editor's). */
    public const A = 0, S = 1, D = 2, F = 3, H = 4, G = 5, Z = 6, X = 7, C = 8, V = 9, B = 11, Q = 12, W = 13, E = 14, R = 15, Y = 16, T = 17,
        N1 = 18, N2 = 19, N3 = 20, N4 = 21, N6 = 22, N5 = 23, EQUAL = 24, N9 = 25, N7 = 26, MINUS = 27, N8 = 28, N0 = 29, BRACKET_RIGHT = 30, O = 31,
        U = 32, BRACKET_LEFT = 33, I = 34, P = 35, L = 37, J = 38, QUOTE = 39, K = 40, SEMICOLON = 41, BACKSLASH = 42, COMMA = 43, SLASH = 44,
        N = 45, M = 46, PERIOD = 47, GRAVE = 50, F5 = 96, F6 = 97, F7 = 98, F3 = 99, F8 = 100, F9 = 101, F11 = 103, F10 = 109, F12 = 111,
        INSERT = 114, F4 = 118, F2 = 120, F1 = 122;
    /** The back and forward buttons of a mouse (mygo's KeyBack and KeyForward), which no key of macOS has a code for: the view sends them for buttons 3 and 4. */
    public const BACK = 1000, FORWARD = 1001;
    /** The menu key of a PC keyboard (kVK_ContextualMenu), which opens the focused node's context menu. */
    public const CONTEXT_MENU = 110;
    public const SHIFT = 1 << 17, CONTROL = 1 << 18, OPTION = 1 << 19, COMMAND = 1 << 20;
    public const MODIFIERS = self::SHIFT | self::CONTROL | self::OPTION | self::COMMAND;

    /** What the key at $code types on a US layout, for shortcuts on layouts that type no Latin letter there, or null (mygo's KeyByPosition). */
    public static function usChar(int $code): ?string
    {
        static $chars = [self::A => 'a', self::S => 's', self::D => 'd', self::F => 'f', self::H => 'h', self::G => 'g', self::Z => 'z', self::X => 'x', self::C => 'c', self::V => 'v',
            self::B => 'b', self::Q => 'q', self::W => 'w', self::E => 'e', self::R => 'r', self::Y => 'y', self::T => 't', self::N1 => '1', self::N2 => '2', self::N3 => '3',
            self::N4 => '4', self::N6 => '6', self::N5 => '5', self::EQUAL => '=', self::N9 => '9', self::N7 => '7', self::MINUS => '-', self::N8 => '8', self::N0 => '0',
            self::BRACKET_RIGHT => ']', self::O => 'o', self::U => 'u', self::BRACKET_LEFT => '[', self::I => 'i', self::P => 'p', self::L => 'l', self::J => 'j', self::QUOTE => "'",
            self::K => 'k', self::SEMICOLON => ';', self::BACKSLASH => '\\', self::COMMA => ',', self::SLASH => '/', self::N => 'n', self::M => 'm', self::PERIOD => '.', self::GRAVE => '`'];
        return $chars[$code] ?? null;
    }
}
