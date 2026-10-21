<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Terminal\Vt;

/**
 * A GhosttyKey (mygo's vt.Key): a key by its place on the keyboard, as the
 * W3C's KeyboardEvent.code names them, in the order of GhosttyKey.
 *
 * @internal
 */
final class Key
{
    public const UNIDENTIFIED = 0;
    public const BACKQUOTE = 1;
    public const BACKSLASH = 2;
    public const BRACKET_LEFT = 3;
    public const BRACKET_RIGHT = 4;
    public const COMMA = 5;
    public const DIGIT0 = 6;
    public const DIGIT1 = 7;
    public const DIGIT2 = 8;
    public const DIGIT3 = 9;
    public const DIGIT4 = 10;
    public const DIGIT5 = 11;
    public const DIGIT6 = 12;
    public const DIGIT7 = 13;
    public const DIGIT8 = 14;
    public const DIGIT9 = 15;
    public const EQUAL = 16;
    public const INTL_BACKSLASH = 17;
    public const INTL_RO = 18;
    public const INTL_YEN = 19;
    public const A = 20;
    public const B = 21;
    public const C = 22;
    public const D = 23;
    public const E = 24;
    public const F = 25;
    public const G = 26;
    public const H = 27;
    public const I = 28;
    public const J = 29;
    public const K = 30;
    public const L = 31;
    public const M = 32;
    public const N = 33;
    public const O = 34;
    public const P = 35;
    public const Q = 36;
    public const R = 37;
    public const S = 38;
    public const T = 39;
    public const U = 40;
    public const V = 41;
    public const W = 42;
    public const X = 43;
    public const Y = 44;
    public const Z = 45;
    public const MINUS = 46;
    public const PERIOD = 47;
    public const QUOTE = 48;
    public const SEMICOLON = 49;
    public const SLASH = 50;
    public const ALT_LEFT = 51;
    public const ALT_RIGHT = 52;
    public const BACKSPACE = 53;
    public const CAPS_LOCK = 54;
    public const CONTEXT_MENU = 55;
    public const CONTROL_LEFT = 56;
    public const CONTROL_RIGHT = 57;
    public const ENTER = 58;
    public const META_LEFT = 59;
    public const META_RIGHT = 60;
    public const SHIFT_LEFT = 61;
    public const SHIFT_RIGHT = 62;
    public const SPACE = 63;
    public const TAB = 64;
    public const CONVERT = 65;
    public const KANA_MODE = 66;
    public const NON_CONVERT = 67;
    public const DELETE = 68;
    public const END = 69;
    public const HELP = 70;
    public const HOME = 71;
    public const INSERT = 72;
    public const PAGE_DOWN = 73;
    public const PAGE_UP = 74;
    public const ARROW_DOWN = 75;
    public const ARROW_LEFT = 76;
    public const ARROW_RIGHT = 77;
    public const ARROW_UP = 78;
    /** Escape and the function keys follow the 41 keys of the numpad. */
    public const ESCAPE = 120, F1 = 121;
}
