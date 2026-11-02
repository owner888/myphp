<?php
declare(strict_types=1);

namespace MyPHP\Platform;

use UnexpectedValueException;

/**
 * An Electron style keyboard accelerator, as "CmdOrCtrl+Shift+Z", parsed
 * into a platform neutral form (mygo's internal/accelerator).
 *
 * @internal
 */
final class Accelerator
{
    /** Modifier keys; SUPER is Command on macOS and the Windows/Super key elsewhere. */
    public const SUPER = 1, CTRL = 2, ALT = 4, SHIFT = 8;

    private const NAMED = [
        'plus' => '+', 'space' => 'Space', 'tab' => 'Tab', 'capslock' => 'CapsLock', 'numlock' => 'NumLock', 'scrolllock' => 'ScrollLock',
        'backspace' => 'Backspace', 'delete' => 'Delete', 'del' => 'Delete', 'insert' => 'Insert', 'ins' => 'Insert',
        'return' => 'Enter', 'enter' => 'Enter', 'up' => 'Up', 'down' => 'Down', 'left' => 'Left', 'right' => 'Right',
        'home' => 'Home', 'end' => 'End', 'pageup' => 'PageUp', 'pagedown' => 'PageDown', 'escape' => 'Escape', 'esc' => 'Escape',
        'volumeup' => 'VolumeUp', 'volumedown' => 'VolumeDown', 'volumemute' => 'VolumeMute',
        'medianexttrack' => 'MediaNextTrack', 'mediaprevioustrack' => 'MediaPreviousTrack', 'mediastop' => 'MediaStop', 'mediaplaypause' => 'MediaPlayPause',
        'printscreen' => 'PrintScreen', 'numdec' => 'NumDec', 'numadd' => 'NumAdd', 'numsub' => 'NumSub', 'nummult' => 'NumMult', 'numdiv' => 'NumDiv',
    ];

    /**
     * @param int $modifiers a set of SUPER, CTRL, ALT, SHIFT
     * @param string $key a single lower case character ("a", "1", ","), or a
     *     named key: F1-F24, Enter, Tab, Space, Backspace, Delete, Insert,
     *     Escape, Up, Down, Left, Right, Home, End, PageUp, PageDown,
     *     VolumeUp, VolumeDown, VolumeMute, MediaNextTrack,
     *     MediaPreviousTrack, MediaStop, MediaPlayPause, PrintScreen,
     *     Num0-Num9, NumDec, NumAdd, NumSub, NumMult, NumDiv, CapsLock,
     *     NumLock, ScrollLock
     */
    public function __construct(public readonly int $modifiers, public readonly string $key) {}

    /** Whether all of $m are held. */
    public function has(int $m): bool
    {
        return ($this->modifiers & $m) === $m;
    }

    /** The accelerator in canonical form, as "Super+Shift+Z". */
    public function __toString(): string
    {
        $parts = [];
        foreach ([self::SUPER => 'Super', self::CTRL => 'Ctrl', self::ALT => 'Alt', self::SHIFT => 'Shift'] as $m => $name) {
            if ($this->has($m)) { $parts[] = $name; }
        }
        $parts[] = mb_strlen($this->key) === 1 ? mb_strtoupper($this->key) : $this->key;
        return implode('+', $parts);
    }

    /** X11's keysym names of the keys that are not their own (mygo's keysyms). */
    private const KEYSYMS = [
        '!' => 'exclam', '"' => 'quotedbl', '#' => 'numbersign', '$' => 'dollar', '%' => 'percent',
        '&' => 'ampersand', "'" => 'apostrophe', '(' => 'parenleft', ')' => 'parenright', '*' => 'asterisk',
        '+' => 'plus', ',' => 'comma', '-' => 'minus', '.' => 'period', '/' => 'slash', ':' => 'colon',
        ';' => 'semicolon', '<' => 'less', '=' => 'equal', '>' => 'greater', '?' => 'question', '@' => 'at',
        '[' => 'bracketleft', '\\' => 'backslash', ']' => 'bracketright', '^' => 'asciicircum',
        '_' => 'underscore', '`' => 'grave', '{' => 'braceleft', '|' => 'bar', '}' => 'braceright', '~' => 'asciitilde',
        'Enter' => 'Return', 'Tab' => 'Tab', 'Space' => 'space', 'Backspace' => 'BackSpace',
        'Delete' => 'Delete', 'Insert' => 'Insert', 'Escape' => 'Escape',
        'Up' => 'Up', 'Down' => 'Down', 'Left' => 'Left', 'Right' => 'Right',
        'Home' => 'Home', 'End' => 'End', 'PageUp' => 'Page_Up', 'PageDown' => 'Page_Down',
        'VolumeUp' => 'XF86AudioRaiseVolume', 'VolumeDown' => 'XF86AudioLowerVolume', 'VolumeMute' => 'XF86AudioMute',
        'MediaNextTrack' => 'XF86AudioNext', 'MediaPreviousTrack' => 'XF86AudioPrev',
        'MediaStop' => 'XF86AudioStop', 'MediaPlayPause' => 'XF86AudioPlay',
        'PrintScreen' => 'Print', 'CapsLock' => 'Caps_Lock', 'NumLock' => 'Num_Lock', 'ScrollLock' => 'Scroll_Lock',
        'NumDec' => 'KP_Decimal', 'NumAdd' => 'KP_Add', 'NumSub' => 'KP_Subtract',
        'NumMult' => 'KP_Multiply', 'NumDiv' => 'KP_Divide',
    ];

    /** The key's X11 keysym name, as XStringToKeysym takes it (mygo's Keysym). */
    public function keysym(): string
    {
        if (isset(self::KEYSYMS[$this->key])) {
            return self::KEYSYMS[$this->key];
        }
        if (preg_match('/^Num(\d)$/', $this->key, $m) === 1) {
            return 'KP_' . $m[1];
        }
        if (mb_strlen($this->key) === 1 && mb_ord($this->key) > 0x7e) {
            return sprintf('U%04X', mb_ord($this->key));
        }
        return $this->key; // letters, digits and F1-F24
    }

    /** The accelerator as a trigger of the XDG shortcuts specification, as "CTRL+SHIFT+k", which desktop portals take (mygo's XDGTrigger). */
    public function xdgTrigger(): string
    {
        $parts = [];
        foreach ([self::CTRL => 'CTRL', self::ALT => 'ALT', self::SHIFT => 'SHIFT', self::SUPER => 'LOGO'] as $m => $name) {
            if ($this->has($m)) { $parts[] = $name; }
        }
        $parts[] = $this->keysym();
        return implode('+', $parts);
    }

    /** Parses $s for the system $os (PHP_OS_FAMILY's): "CmdOrCtrl" is Super (Command) on macOS, Ctrl elsewhere. */
    public static function parse(string $s, string $os = PHP_OS_FAMILY): self
    {
        if (trim($s) === '') {
            throw new UnexpectedValueException('accelerator: empty accelerator');
        }
        $parts = explode('+', $s);
        if (str_ends_with($s, '++')) {
            $parts = [...array_slice($parts, 0, -2), '+'];
        } elseif ($s === '+') {
            $parts = ['+'];
        }
        $mods = 0; $key = '';
        foreach ($parts as $i => $raw) {
            $p = trim($raw);
            if ($p === '') {
                throw new UnexpectedValueException("accelerator: invalid accelerator \"$s\"");
            }
            $last = $i === count($parts) - 1;
            $m = self::modifier($p, $os);
            if ($m !== null && !$last) {
                $mods |= $m;
                continue;
            }
            if (!$last) {
                throw new UnexpectedValueException("accelerator: unknown modifier \"$p\" in \"$s\"");
            }
            $key = self::key($p) ?? throw new UnexpectedValueException("accelerator: unknown key \"$p\" in \"$s\"");
        }
        return new self($mods, $key);
    }

    private static function modifier(string $p, string $os): ?int
    {
        return match (strtolower($p)) {
            'command', 'cmd', 'super', 'meta', 'win' => self::SUPER,
            'control', 'ctrl' => self::CTRL,
            'commandorcontrol', 'cmdorctrl' => $os === 'Darwin' ? self::SUPER : self::CTRL,
            'alt', 'option', 'altgr' => self::ALT,
            'shift' => self::SHIFT,
            default => null,
        };
    }

    private static function key(string $p): ?string
    {
        $lower = strtolower($p);
        if (mb_strlen($p) === 1) {
            return mb_strtolower($p);
        }
        if (isset(self::NAMED[$lower])) {
            return self::NAMED[$lower];
        }
        if (preg_match('/^f([1-9][0-9]?)$/', $lower, $m) === 1 && (int)$m[1] <= 24) {
            return 'F' . $m[1];
        }
        if (preg_match('/^num([0-9])$/', $lower, $m) === 1) {
            return 'Num' . $m[1];
        }
        return null;
    }
}
