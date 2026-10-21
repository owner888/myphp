<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Terminal\Vt;

use FFI;
use FFI\CData;

/** Encodes pointer events in the format and for the events the program asked for (mygo's vt.MouseEncoder). @internal */
final class MouseEncoder
{
    /** What the pointer did (mygo's MouseAction). */
    public const PRESS = 0, RELEASE = 1, MOTION = 2;
    /** Mouse buttons: 1 left, 2 right, 3 middle, 4 and 5 the wheel up and down, 6 and 7 left and right. */
    public const BUTTON_NONE = 0, BUTTON_LEFT = 1, BUTTON_RIGHT = 2, BUTTON_MIDDLE = 3, WHEEL_UP = 4, WHEEL_DOWN = 5, WHEEL_LEFT = 6, WHEEL_RIGHT = 7;

    private ?CData $h;
    private ?CData $ev;

    public function __construct()
    {
        $f = Lib::ffi();
        $this->h = $f->new('void*');
        $this->ev = $f->new('void*');
        Lib::must($f->ghostty_mouse_encoder_new(null, FFI::addr($this->h)));
        Lib::must($f->ghostty_mouse_event_new(null, FFI::addr($this->ev)));
        // Motion is reported once per cell, as xterm does.
        $v = $f->new('bool');
        $v->cdata = true;
        $f->ghostty_mouse_encoder_setopt($this->h, 4, FFI::addr($v));
    }

    public function free(): void
    {
        $f = Lib::ffi();
        if ($this->ev !== null) { $f->ghostty_mouse_event_free($this->ev); }
        if ($this->h !== null) { $f->ghostty_mouse_encoder_free($this->h); }
        $this->h = $this->ev = null;
    }

    /** Takes the terminal's mouse modes, the geometry of the screen in pixels, and whether a button is held. */
    public function sync(Terminal $t, int $width, int $height, int $cellWidth, int $cellHeight, int $padLeft, int $padTop, bool $pressed): void
    {
        $f = Lib::ffi();
        $f->ghostty_mouse_encoder_setopt_from_terminal($this->h, $t->h);
        $s = $f->new('GhosttyMouseEncoderSize');
        $s->size = FFI::sizeof($s);
        $s->screen_width = max($width, 1); $s->screen_height = max($height, 1);
        $s->cell_width = max($cellWidth, 1); $s->cell_height = max($cellHeight, 1);
        $s->padding_top = max($padTop, 0); $s->padding_left = max($padLeft, 0);
        $f->ghostty_mouse_encoder_setopt($this->h, 2, FFI::addr($s));
        $p = $f->new('bool');
        $p->cdata = $pressed;
        $f->ghostty_mouse_encoder_setopt($this->h, 3, FFI::addr($p));
    }

    /** The bytes the program reads for a pointer event at ($x, $y) pixels; '' when it asked for no such event. */
    public function encode(int $action, int $button, int $mods, float $x, float $y): string
    {
        $f = Lib::ffi();
        $f->ghostty_mouse_event_set_action($this->ev, $action);
        if ($button === self::BUTTON_NONE) {
            $f->ghostty_mouse_event_clear_button($this->ev);
        } else {
            $f->ghostty_mouse_event_set_button($this->ev, $button);
        }
        $f->ghostty_mouse_event_set_mods($this->ev, $mods);
        $pos = $f->new('GhosttyMousePosition');
        $pos->x = $x; $pos->y = $y;
        $f->ghostty_mouse_event_set_position($this->ev, $pos);
        $buf = $f->new('char[64]');
        $n = $f->new('size_t');
        if ($f->ghostty_mouse_encoder_encode($this->h, $this->ev, $buf, 64, FFI::addr($n)) !== Lib::SUCCESS) { return ''; }
        return FFI::string($buf, (int)$n->cdata);
    }
}
