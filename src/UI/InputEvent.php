<?php
declare(strict_types=1);

namespace MyPHP\UI;

/**
 * Input an element takes as it comes (mygo's InputEvent, Node::handleInput):
 * keys, text and edit commands while the element has the keyboard focus,
 * the pointer pressed on it, moving over it or scrolling over it.
 */
final class InputEvent
{
    /** A key with the modifiers held; a key that types text comes before the text. */
    public const KEY_DOWN = 1;
    /** Text typed, or committed by an input method. */
    public const TEXT = 3;
    /** An edit command of the menus, as "copy", "cut", "paste", "selectAll", "undo", "redo" or "delete". */
    public const COMMAND = 5;
    /** The pointer's button pressed or released at x, y, with $clicks the count of quick successive presses (2 for a double click). Once the element takes a POINTER_DOWN, the pointer's moves and its release come to it wherever they are. */
    public const POINTER_DOWN = 6, POINTER_UP = 7;
    /** The pointer at x, y, with $button held, or -1 when none is. */
    public const POINTER_MOVE = 8;
    /** Scrolls by dx, dy points at x, y; $precise marks touchpads, which scroll by pixels rather than by lines. */
    public const SCROLL = 9;

    public function __construct(
        public int $kind,
        /** The key's virtual key code (Key), by position on an ANSI keyboard, whatever the layout types. */
        public int $key = 0,
        public int $mods = 0,
        /** Text of TEXT and COMMAND, and of KEY_DOWN the characters the key types. */
        public string $text = '',
        /** The pointer, relative to the element's box. */
        public float $x = 0.0, public float $y = 0.0,
        /** 0 the primary button, 1 the secondary, 2 the middle. */
        public int $button = 0,
        public int $clicks = 0,
        public float $dx = 0.0, public float $dy = 0.0,
        public bool $precise = false,
    ) {}
}
