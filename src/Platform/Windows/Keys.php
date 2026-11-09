<?php
declare(strict_types=1);

namespace MyPHP\Platform\Windows;

use FFI;
use MyPHP\Platform\Linux\Keys as LinuxKeys;
use MyPHP\UI\Key;

/**
 * Win32's keys as the view on macOS gives them to Input, as Linux's Keys
 * gives GDK's: the macOS virtual key code of a key by its place on the
 * keyboard (its scancode, set 1, of an ANSI keyboard) or, for the keys
 * that type nothing, its virtual key (mygo's vkKey); the characters it
 * types on the layout now (ToUnicodeEx, leaving the layout's dead keys as
 * they are), AppKit's private-use characters for the function keys. The
 * Edit menu's commands and the text system's commands of the keys are
 * Linux's (Control there, Ctrl here, the shortcut modifier).
 */
final class Keys
{
    /** macOS key codes by scancode, of the keys not extended (bit 24 of the message's lParam). */
    private const SCANCODES = [
        0x01 => Key::ESCAPE, 0x02 => Key::N1, 0x03 => Key::N2, 0x04 => Key::N3, 0x05 => Key::N4, 0x06 => Key::N5, 0x07 => Key::N6, 0x08 => Key::N7,
        0x09 => Key::N8, 0x0A => Key::N9, 0x0B => Key::N0, 0x0C => Key::MINUS, 0x0D => Key::EQUAL, 0x0E => Key::DELETE, 0x0F => Key::TAB,
        0x10 => Key::Q, 0x11 => Key::W, 0x12 => Key::E, 0x13 => Key::R, 0x14 => Key::T, 0x15 => Key::Y, 0x16 => Key::U, 0x17 => Key::I, 0x18 => Key::O,
        0x19 => Key::P, 0x1A => Key::BRACKET_LEFT, 0x1B => Key::BRACKET_RIGHT, 0x1C => Key::RETURN, 0x1E => Key::A, 0x1F => Key::S, 0x20 => Key::D,
        0x21 => Key::F, 0x22 => Key::G, 0x23 => Key::H, 0x24 => Key::J, 0x25 => Key::K, 0x26 => Key::L, 0x27 => Key::SEMICOLON, 0x28 => Key::QUOTE,
        0x29 => Key::GRAVE, 0x2B => Key::BACKSLASH, 0x2C => Key::Z, 0x2D => Key::X, 0x2E => Key::C, 0x2F => Key::V, 0x30 => Key::B, 0x31 => Key::N,
        0x32 => Key::M, 0x33 => Key::COMMA, 0x34 => Key::PERIOD, 0x35 => Key::SLASH, 0x39 => Key::SPACE, 0x3B => Key::F1, 0x3C => Key::F2,
        0x3D => Key::F3, 0x3E => Key::F4, 0x3F => Key::F5, 0x40 => Key::F6, 0x41 => Key::F7, 0x42 => Key::F8, 0x43 => Key::F9, 0x44 => Key::F10,
        0x57 => Key::F11, 0x58 => Key::F12,
    ];

    /** macOS key codes by virtual key, for the keys that type nothing, whatever their place (the keypad's with Num Lock off too). */
    private const VKEYS = [
        0x21 => Key::PAGE_UP, 0x22 => Key::PAGE_DOWN, 0x23 => Key::END, 0x24 => Key::HOME, 0x25 => Key::LEFT, 0x26 => Key::UP, 0x27 => Key::RIGHT,
        0x28 => Key::DOWN, 0x2D => Key::INSERT, 0x2E => Key::FORWARD_DELETE, 0x5D => Key::CONTEXT_MENU, 0xA6 => Key::BACK, 0xA7 => Key::FORWARD,
        0x08 => Key::DELETE, 0x09 => Key::TAB, 0x1B => Key::ESCAPE,
    ];

    /** The modifier keys' virtual keys: Shift, Ctrl, Alt (each, and left and right), the Windows keys, Caps Lock. */
    private const MODIFIERS = [0x10, 0x11, 0x12, 0x14, 0x5B, 0x5C, 0xA0, 0xA1, 0xA2, 0xA3, 0xA4, 0xA5];

    /** The macOS key code of virtual key $vk at the message's lParam $lp (-1 for none: the keypad's digits, as Linux's), and the characters it types, AppKit's for the function keys. @return array{0: int, 1: string} */
    public static function of(int $vk, int $lp): array
    {
        $scan = ($lp >> 16) & 0xFF;
        $extended = ($lp & (1 << 24)) !== 0;
        if ($vk === 0x0D) {
            $code = $extended ? Key::ENTER : Key::RETURN; // the keypad's Enter is extended
        } elseif ($vk >= 0x70 && $vk <= 0x7B) {
            $code = [Key::F1, Key::F2, Key::F3, Key::F4, Key::F5, Key::F6, Key::F7, Key::F8, Key::F9, Key::F10, Key::F11, Key::F12][$vk - 0x70];
        } else {
            $code = self::VKEYS[$vk] ?? ($extended ? -1 : (self::SCANCODES[$scan] ?? -1));
        }
        $chars = LinuxKeys::functionChars($code);
        return [$code, $chars !== '' ? $chars : self::typed($vk, $scan, false)];
    }

    /** What the key types without Ctrl and Alt (charactersIgnoringModifiers, Shift kept), for shortcuts. */
    public static function plain(int $vk, int $lp, int $code, string $chars): string
    {
        return LinuxKeys::functionChars($code) !== '' ? $chars : self::typed($vk, ($lp >> 16) & 0xFF, true);
    }

    public static function isModifier(int $vk): bool
    {
        return in_array($vk, self::MODIFIERS, true);
    }

    /**
     * What the layout types for the key with the keys held now (ToUnicodeEx
     * of flag 4, which leaves its dead keys as they are); $plain: with Ctrl
     * and Alt let go. A chord of Ctrl, which types a control character, is
     * the key's character, as GDK's keyval is: Ctrl is Command here. ''
     * for a dead key or one that types nothing.
     */
    private static function typed(int $vk, int $scan, bool $plain): string
    {
        $u = Win32::user32();
        $state = $u->new('uint8_t[256]');
        $u->GetKeyboardState($state);
        $ctrl = ($state[0x11] & 0x80) !== 0;
        if ($plain) {
            foreach ([0x11, 0x12, 0xA2, 0xA3, 0xA4, 0xA5] as $k) { $state[$k] = 0; }
        }
        $layout = $u->GetKeyboardLayout(0);
        $buf = $u->new('uint16_t[8]');
        $n = $u->ToUnicodeEx($vk, $scan, $state, $buf, 8, 4, $layout);
        if ($n > 0 && $ctrl && !$plain && $buf[0] < 0x20) {
            foreach ([0x11, 0x12, 0xA2, 0xA3, 0xA4, 0xA5] as $k) { $state[$k] = 0; }
            $n = $u->ToUnicodeEx($vk, $scan, $state, $buf, 8, 4, $layout);
        }
        if ($n <= 0) {
            return '';
        }
        $s = (string)iconv('UTF-16LE', 'UTF-8', FFI::string($buf, $n * 2));
        return $s !== '' && ord($s[0]) < 0x20 ? '' : $s;
    }
}
