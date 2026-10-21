<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Terminal\Vt;

use FFI;
use FFI\CData;

/**
 * Encodes keys as the terminal's modes ask (mygo's vt.KeyEncoder): legacy
 * sequences, xterm's modifyOtherKeys or the Kitty keyboard protocol; and
 * focus reports and pastes.
 *
 * @internal
 */
final class KeyEncoder
{
    private ?CData $h;
    private ?CData $ev;

    public function __construct()
    {
        $f = Lib::ffi();
        $this->h = $f->new('void*');
        $this->ev = $f->new('void*');
        Lib::must($f->ghostty_key_encoder_new(null, FFI::addr($this->h)));
        Lib::must($f->ghostty_key_event_new(null, FFI::addr($this->ev)));
    }

    public function free(): void
    {
        $f = Lib::ffi();
        if ($this->ev !== null) { $f->ghostty_key_event_free($this->ev); }
        if ($this->h !== null) { $f->ghostty_key_encoder_free($this->h); }
        $this->h = $this->ev = null;
    }

    /** Takes the terminal's modes, and whether Option is Alt on macOS. */
    public function sync(Terminal $t, bool $optionAsAlt): void
    {
        $f = Lib::ffi();
        $f->ghostty_key_encoder_setopt_from_terminal($this->h, $t->h);
        $v = $f->new('int32_t');
        $v->cdata = $optionAsAlt ? 1 : 0;
        $f->ghostty_key_encoder_setopt($this->h, 6, FFI::addr($v));
    }

    /** The bytes the program reads for $k; '' for keys that send nothing. */
    public function encode(KeyEvent $k): string
    {
        $f = Lib::ffi();
        $f->ghostty_key_event_set_action($this->ev, $k->action);
        $f->ghostty_key_event_set_key($this->ev, $k->key);
        $f->ghostty_key_event_set_mods($this->ev, $k->mods);
        $f->ghostty_key_event_set_consumed_mods($this->ev, $k->consumed);
        $f->ghostty_key_event_set_composing($this->ev, $k->composing);
        $f->ghostty_key_event_set_unshifted_codepoint($this->ev, $k->unshifted);
        // The event keeps the text's address until it is encoded.
        $text = $k->text !== '' ? Lib::buffer($k->text) : null;
        $f->ghostty_key_event_set_utf8($this->ev, $text === null ? null : Lib::ptr($text, 'const char*'), strlen($k->text));
        $n = $f->new('size_t');
        $buf = $f->new('char[128]');
        $r = $f->ghostty_key_encoder_encode($this->h, $this->ev, $buf, 128, FFI::addr($n));
        if ($r === Lib::OUT_OF_SPACE && $n->cdata > 0) {
            $size = (int)$n->cdata;
            $buf = $f->new("char[$size]");
            $r = $f->ghostty_key_encoder_encode($this->h, $this->ev, $buf, $size, FFI::addr($n));
        }
        $f->ghostty_key_event_set_utf8($this->ev, null, 0);
        return $r === Lib::SUCCESS ? FFI::string($buf, (int)$n->cdata) : '';
    }

    /** The report of the terminal gaining or losing the focus (mode 1004). */
    public static function encodeFocus(bool $gained): string
    {
        $f = Lib::ffi();
        $buf = $f->new('char[8]');
        $n = $f->new('size_t');
        return $f->ghostty_focus_encode($gained ? 0 : 1, $buf, 8, FFI::addr($n)) === Lib::SUCCESS ? FFI::string($buf, (int)$n->cdata) : '';
    }

    /**
     * $text as the program reads a paste: bracketed (mode 2004), or with
     * newlines as carriage returns, and without the control characters that
     * could run commands.
     */
    public static function encodePaste(string $text, bool $bracketed): string
    {
        if ($text === '') { return ''; }
        $f = Lib::ffi();
        $size = strlen($text) + 16;
        $data = Lib::buffer($text);
        $out = $f->new("char[$size]");
        $n = $f->new('size_t');
        $r = $f->ghostty_paste_encode(Lib::ptr($data, 'char*'), strlen($text), $bracketed, $out, $size, FFI::addr($n));
        if ($r === Lib::OUT_OF_SPACE && $n->cdata > 0) {
            $data = Lib::buffer($text); // encoding may have changed it in place
            $size = (int)$n->cdata;
            $out = $f->new("char[$size]");
            $r = $f->ghostty_paste_encode(Lib::ptr($data, 'char*'), strlen($text), $bracketed, $out, $size, FFI::addr($n));
        }
        return $r === Lib::SUCCESS ? FFI::string($out, (int)$n->cdata) : '';
    }

    /** Whether pasting $text cannot run a command by itself. */
    public static function pasteIsSafe(string $text): bool
    {
        if ($text === '') { return true; }
        $f = Lib::ffi();
        return $f->ghostty_paste_is_safe(Lib::ptr(Lib::buffer($text), 'const char*'), strlen($text));
    }
}
