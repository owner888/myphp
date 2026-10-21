<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Terminal;

use MyPHP\Plugins\Terminal\Vt\{Key as VtKey, KeyEvent};
use MyPHP\UI\Key;

/**
 * MyPHP's keys as Ghostty's (mygo's keys.go): its key, and the character
 * it types without modifiers on a US keyboard, which MyPHP's keys name by
 * their place (Key::A types an a).
 *
 * @internal
 */
final class Keys
{
    /** @var ?array<int, array{0: int, 1: int}> by Key code: Ghostty's key and the codepoint it types, 0 for none */
    private static ?array $keys = null;
    /** The keys of the symbols Shift types on a US keyboard. */
    private const SHIFTED = ['!' => Key::N1, '@' => Key::N2, '#' => Key::N3, '$' => Key::N4, '%' => Key::N5, '^' => Key::N6, '&' => Key::N7, '*' => Key::N8,
        '(' => Key::N9, ')' => Key::N0, '_' => Key::MINUS, '+' => Key::EQUAL, '<' => Key::COMMA, '>' => Key::PERIOD,
        '?' => Key::SLASH, ':' => Key::SEMICOLON, '"' => Key::QUOTE, '{' => Key::BRACKET_LEFT, '}' => Key::BRACKET_RIGHT,
        '|' => Key::BACKSLASH, '~' => Key::GRAVE];

    /** @return array<int, array{0: int, 1: int}> */
    private static function keys(): array
    {
        if (self::$keys !== null) {
            return self::$keys;
        }
        $k = [
            Key::RETURN => [VtKey::ENTER, 13], Key::ENTER => [VtKey::ENTER, 13], Key::ESCAPE => [VtKey::ESCAPE, 0], Key::DELETE => [VtKey::BACKSPACE, 0],
            Key::TAB => [VtKey::TAB, 9], Key::SPACE => [VtKey::SPACE, 32], Key::FORWARD_DELETE => [VtKey::DELETE, 0],
            Key::INSERT => [VtKey::INSERT, 0], Key::HOME => [VtKey::HOME, 0], Key::END => [VtKey::END, 0],
            Key::PAGE_UP => [VtKey::PAGE_UP, 0], Key::PAGE_DOWN => [VtKey::PAGE_DOWN, 0],
            Key::LEFT => [VtKey::ARROW_LEFT, 0], Key::RIGHT => [VtKey::ARROW_RIGHT, 0], Key::UP => [VtKey::ARROW_UP, 0], Key::DOWN => [VtKey::ARROW_DOWN, 0],
            Key::MINUS => [VtKey::MINUS, 0x2d], Key::EQUAL => [VtKey::EQUAL, 0x3d], Key::COMMA => [VtKey::COMMA, 0x2c],
            Key::PERIOD => [VtKey::PERIOD, 0x2e], Key::SLASH => [VtKey::SLASH, 0x2f], Key::SEMICOLON => [VtKey::SEMICOLON, 0x3b],
            Key::QUOTE => [VtKey::QUOTE, 0x27], Key::BRACKET_LEFT => [VtKey::BRACKET_LEFT, 0x5b], Key::BRACKET_RIGHT => [VtKey::BRACKET_RIGHT, 0x5d],
            Key::BACKSLASH => [VtKey::BACKSLASH, 0x5c], Key::GRAVE => [VtKey::BACKQUOTE, 0x60], Key::CONTEXT_MENU => [VtKey::CONTEXT_MENU, 0],
        ];
        foreach ([Key::F1, Key::F2, Key::F3, Key::F4, Key::F5, Key::F6, Key::F7, Key::F8, Key::F9, Key::F10, Key::F11, Key::F12] as $i => $f) {
            $k[$f] = [VtKey::F1 + $i, 0];
        }
        foreach ([Key::N0, Key::N1, Key::N2, Key::N3, Key::N4, Key::N5, Key::N6, Key::N7, Key::N8, Key::N9] as $i => $n) {
            $k[$n] = [VtKey::DIGIT0 + $i, 0x30 + $i];
        }
        foreach (str_split('ABCDEFGHIJKLMNOPQRSTUVWXYZ') as $i => $l) {
            $k[constant(Key::class . '::' . $l)] = [VtKey::A + $i, 0x61 + $i];
        }
        return self::$keys = $k;
    }

    /** Ghostty's key for $k, and the codepoint it types without modifiers (0 for keys that type none) (mygo's vtKey). @return array{0: int, 1: int} */
    public static function vt(int $k): array { return self::keys()[$k] ?? [VtKey::UNIDENTIFIED, 0]; }

    /** Whether a key types text, rather than moving or erasing: the text comes after it (mygo's typesText). */
    public static function typesText(int $k): bool
    {
        $r = self::vt($k)[1];
        return $r > 0x20 || $k === Key::SPACE;
    }

    /** The key that types $r, its unshifted codepoint, and whether it takes Shift, on a US keyboard, for text that came without its key (mygo's keyOfRune). @return array{0: int, 1: int, 2: bool} */
    public static function ofRune(int $r): array
    {
        if ($r < 0x80) {
            $lower = ord(strtolower(chr($r)));
            foreach (self::keys() as $k => [$vk, $u]) {
                if (($u === $lower && $u > 0x20) || ($k === Key::SPACE && $r === 0x20)) {
                    return [$vk, $u, $lower !== $r];
                }
            }
            if (isset(self::SHIFTED[chr($r)])) {
                [$vk, $u] = self::vt(self::SHIFTED[chr($r)]);
                return [$vk, $u, true];
            }
        }
        return [VtKey::UNIDENTIFIED, 0, false];
    }

    /** What a key types with Shift on a US keyboard (mygo's shiftedText). */
    public static function shiftedText(int $k, int $unshifted): string
    {
        foreach (self::SHIFTED as $r => $key) {
            if ($key === $k) { return (string)$r; }
        }
        if ($unshifted >= 0x61 && $unshifted <= 0x7a) {
            return chr($unshifted - 0x20);
        }
        return mb_chr($unshifted, 'UTF-8');
    }

    /** MyPHP's modifiers (Key::SHIFT…) as Ghostty's (mygo's vtMods). */
    public static function mods(int $m): int
    {
        return (($m & Key::SHIFT) !== 0 ? KeyEvent::SHIFT : 0) | (($m & Key::CONTROL) !== 0 ? KeyEvent::CTRL : 0)
            | (($m & Key::OPTION) !== 0 ? KeyEvent::ALT : 0) | (($m & Key::COMMAND) !== 0 ? KeyEvent::SUPER : 0);
    }
}
