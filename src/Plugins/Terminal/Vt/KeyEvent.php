<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Terminal\Vt;

/** A key to encode for the program (mygo's vt.KeyEvent). @internal */
final class KeyEvent
{
    /** What happened to the key (mygo's KeyAction). */
    public const RELEASE = 0, PRESS = 1, REPEAT = 2;
    /** Modifier keys (GhosttyMods): the same bits as mygo's. */
    public const SHIFT = 1, CTRL = 2, ALT = 4, SUPER = 8;

    public function __construct(
        public int $action = self::PRESS,
        public int $key = Key::UNIDENTIFIED,
        public int $mods = 0,
        /** The modifiers that made $text, as Shift an "A". */
        public int $consumed = 0,
        /** What the key types, without what Ctrl or Alt change; empty for keys that type nothing. */
        public string $text = '',
        /** The character the key types without modifiers, a codepoint. */
        public int $unshifted = 0,
        /** An input method is composing. */
        public bool $composing = false,
    ) {}
}
