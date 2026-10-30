<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Terminal\Vt;

use FFI;
use FFI\CData;
use RuntimeException;

/**
 * A GhosttyTerminal (mygo's vt.Terminal): the screen, its scrollback, the
 * cursor and the modes, which the output of programs changes. Its
 * effects are called during write(); they must not call write().
 *
 * @internal
 */
final class Terminal
{
    // Terminal options and data (GhosttyTerminalOption, GhosttyTerminalData).
    private const OPT_USERDATA = 0, OPT_WRITE_PTY = 1, OPT_BELL = 2, OPT_TITLE_CHANGED = 5, OPT_SIZE = 6, OPT_COLOR_SCHEME = 7,
        OPT_DEVICE_ATTRIBUTES = 8, OPT_COLOR_FOREGROUND = 11, OPT_COLOR_BACKGROUND = 12, OPT_COLOR_CURSOR = 13, OPT_COLOR_PALETTE = 14,
        OPT_KITTY_IMAGE_LIMIT = 15, OPT_SELECTION = 21, OPT_DEFAULT_CURSOR_STYLE = 22, OPT_DEFAULT_CURSOR_BLINK = 23, OPT_PWD_CHANGED = 25,
        OPT_CLIPBOARD_WRITE = 26, OPT_SCROLLBACK_MAX_BYTES = 27, OPT_DESKTOP_NOTIFICATION = 29, OPT_PROGRESS_REPORT = 30,
        OPT_MODE_DEFAULT = 33, OPT_MODE = 34, OPT_TERMINFO_NAME = 37, OPT_CLIPBOARD_READ = 38, OPT_RESIZE_PULL_BACK = 40, OPT_RENDER_HOLD = 41, OPT_SEMANTIC_PROMPT = 42;
    private const DATA_COLS = 1, DATA_ROWS = 2, DATA_CURSOR_X = 3, DATA_CURSOR_Y = 4, DATA_ACTIVE_SCREEN = 6, DATA_SCROLLBAR = 9,
        DATA_MOUSE_TRACKING = 11, DATA_TITLE = 12, DATA_PWD = 13, DATA_SELECTION = 31, DATA_VIEWPORT_ACTIVE = 32, DATA_MODE = 37, DATA_MOUSE_SHAPE = 41;

    /** The steps of a command a shell reports with OSC 133 (GhosttySemanticPromptKind): its prompt drawn, its input started, its output started (it runs), it ended. */
    public const PROMPT_START = 1, INPUT_START = 2, OUTPUT_START = 3, COMMAND_END = 4;

    /** The shape of the cursor (mygo's vt.CursorStyle). */
    public const CURSOR_BAR = 0, CURSOR_BLOCK = 1, CURSOR_UNDERLINE = 2, CURSOR_BLOCK_HOLLOW = 3;

    // Modes (GhosttyMode): DEC private modes, or ANSI ones with bit 15.
    public const MODE_CURSOR_KEYS = 1, MODE_CURSOR_VISIBLE = 25, MODE_FOCUS_EVENT = 1004, MODE_ALT_SCROLL = 1007, MODE_BRACKETED_PASTE = 2004,
        /** A paste tells the program what the clipboard holds, which it reads itself (the Kitty clipboard protocol). */
        MODE_PASTE_EVENTS = 5522, MODE_SYNC_OUTPUT = 2026, MODE_GRAPHEMES = 2027,
        /** Has the terminal tell the program when its colors turn light or dark (CSI ? 997 ; 1 or 2 n). */
        MODE_COLOR_SCHEME = 2031;

    /** The formatter's format of text with escape sequences (GHOSTTY_FORMATTER_FORMAT_VT). */
    private const FORMAT_VT = 1;

    /** @internal the GhosttyTerminal */
    public ?CData $h;
    /** The callbacks the library holds, kept alive while it does. */
    private CData $cbs;
    /** Set while a clipboard read goes unanswered: the refusal the library writes for it is dropped. */
    private bool $silent = false;
    /** The clipboard read answered later: the library's refusal, which follows the read's effect, is held back and taken in it. */
    private ?DeferredClipboardRead $deferred = null;
    /** What the library writes while it answers a clipboard read, which writePTY then gets at once; null when it answers none. */
    private ?string $reply = null;

    /** A terminal of $cols columns and $rows rows (mygo's NewTerminal). */
    public function __construct(int $cols, int $rows, private readonly Effects $effects)
    {
        $f = Lib::ffi();
        $h = $f->new('void*');
        Lib::must($f->ghostty_terminal_new(null, FFI::addr($h), self::clamp16($cols), self::clamp16($rows)));
        $this->h = $h;
        $cbs = $f->new('MyPHPTerminalCallbacks');
        $e = $effects;
        $cbs->write_pty = function ($t, $u, $data, int $n) use ($e): void {
            if ($e->writePTY === null || $n <= 0) { return; }
            $p = FFI::string($data, $n);
            if (($d = $this->deferred) !== null) { // a deferred read: what the library writes now is its refusal, which the read's answer replaces
                $this->deferred = null;
                if ($d->setRefusal($p)) { return; }
            }
            if ($this->silent && str_starts_with($p, "\x1b]5522;type=read:status=EPERM")) {
                // The refusal of a read that goes unanswered: the library writes it as the read's callback returns without a reply.
                $this->silent = false;
                return;
            }
            if ($this->reply !== null) {
                $this->reply .= $p;
                return;
            }
            ($e->writePTY)($p);
        };
        $cbs->bell = function ($t, $u) use ($e): void { if ($e->bell !== null) { ($e->bell)(); } };
        $cbs->title_changed = function ($t, $u) use ($e): void { if ($e->titleChanged !== null) { ($e->titleChanged)(); } };
        $cbs->pwd_changed = function ($t, $u) use ($e): void { if ($e->pwdChanged !== null) { ($e->pwdChanged)(); } };
        $cbs->render_hold = function ($t, $u, bool $held) use ($e): void { if ($e->renderHold !== null) { ($e->renderHold)($held); } };
        $cbs->clipboard_write = function ($t, $u, CData $w): void { $this->clipboardWrite($w); };
        $cbs->clipboard_read = function ($t, $u, CData $r): void { $this->clipboardRead($r); };
        $cbs->size = function ($t, $u, CData $out) use ($e): bool {
            if ($e->size === null) { return false; }
            [$cols, $rows, $cw, $ch] = ($e->size)();
            $out->rows = self::clamp16($rows); $out->columns = self::clamp16($cols);
            $out->cell_width = max($cw, 1); $out->cell_height = max($ch, 1);
            return true;
        };
        $cbs->color_scheme = function ($t, $u, CData $out) use ($e): bool {
            if ($e->dark === null) { return false; }
            $out[0] = ($e->dark)() ? 1 : 0;
            return true;
        };
        $cbs->device_attributes = function ($t, $u, CData $da): bool {
            // As Ghostty answers: a VT220 with ANSI colors (DA1), of type 1 and version 10 (DA2).
            FFI::memset($da, 0, FFI::sizeof($da));
            $da->conformance = 62; $da->num_features = 1; $da->features[0] = 22; $da->device_type = 1; $da->firmware = 10;
            return true;
        };
        $cbs->desktop_notification = function ($t, $u, CData $n) use ($e): void {
            if ($e->notify !== null) { ($e->notify)(Lib::str($n->title), Lib::str($n->body)); }
        };
        $cbs->progress_report = function ($t, $u, CData $r) use ($e): void {
            if ($e->progress !== null) { ($e->progress)((int)$r->state, (int)$r->progress); }
        };
        $cbs->semantic_prompt = function ($t, $u, CData $ev) use ($e): void {
            if ($e->semanticPrompt !== null) { ($e->semanticPrompt)((int)$ev->kind, $ev->has_exit_code ? (int)$ev->exit_code : null); }
        };
        // A GhosttyMimeReader for pasteEvent(), which the library never calls (it reads nothing for an event); it gives no data if it did.
        $cbs->no_read = fn($u, $p, $n, $a, $b): bool => false;
        $this->cbs = $cbs;
        foreach ([[self::OPT_WRITE_PTY, 'write_pty'], [self::OPT_BELL, 'bell'], [self::OPT_TITLE_CHANGED, 'title_changed'], [self::OPT_PWD_CHANGED, 'pwd_changed'],
            [self::OPT_RENDER_HOLD, 'render_hold'], [self::OPT_CLIPBOARD_WRITE, 'clipboard_write'], [self::OPT_CLIPBOARD_READ, 'clipboard_read'], [self::OPT_SIZE, 'size'],
            [self::OPT_COLOR_SCHEME, 'color_scheme'], [self::OPT_DEVICE_ATTRIBUTES, 'device_attributes'], [self::OPT_DESKTOP_NOTIFICATION, 'desktop_notification'],
            [self::OPT_PROGRESS_REPORT, 'progress_report'], [self::OPT_SEMANTIC_PROMPT, 'semantic_prompt']] as [$opt, $field]) {
            $f->ghostty_terminal_set($this->h, $opt, $cbs->$field);
        }
        // Images of the Kitty graphics protocol are not drawn: refuse them, so that programs fall back to something else.
        $this->set(self::OPT_KITTY_IMAGE_LIMIT, 'uint64_t', 0);
    }

    /** The no-read callback of the paste reader. @internal */
    public function noRead(): CData { return $this->cbs->no_read; }

    /** Sets an option to a value of C type $type. */
    private function set(int $opt, string $type, mixed $v): void
    {
        $c = Lib::ffi()->new($type);
        $c->cdata = $v;
        Lib::ffi()->ghostty_terminal_set($this->h, $opt, FFI::addr($c));
    }

    /** Sets an option to a struct or array, or to nothing. */
    private function setTo(int $opt, ?CData $value): void
    {
        Lib::ffi()->ghostty_terminal_set($this->h, $opt, $value === null ? null : FFI::addr($value));
    }

    /** Reads data of C type $type into a new value, or null when the library has none. */
    private function get(int $data, string $type): ?CData
    {
        $c = Lib::ffi()->new($type);
        return Lib::ffi()->ghostty_terminal_get($this->h, $data, FFI::addr($c)) === Lib::SUCCESS ? $c : null;
    }

    /** Frees the terminal. */
    public function free(): void
    {
        if ($this->h === null) { return; }
        Lib::ffi()->ghostty_terminal_free($this->h);
        $this->h = null;
    }

    /** Processes output of the program. */
    public function write(string $p): void
    {
        if ($p === '') { return; }
        $b = Lib::buffer($p);
        $this->deferred = null; // a read deferred whose refusal never came is stale
        Lib::ffi()->ghostty_terminal_vt_write($this->h, $b, strlen($p));
        $this->deferred = null;
        // A read that writes no refusal, as OSC 52's, leaves silent set; it is for that read alone.
        $this->silent = false;
    }

    /** Sets the size of the screen in cells, and of a cell in pixels. */
    public function resize(int $cols, int $rows, int $cellWidth, int $cellHeight): void
    {
        Lib::must(Lib::ffi()->ghostty_terminal_resize($this->h, self::clamp16($cols), self::clamp16($rows), max($cellWidth, 1), max($cellHeight, 1)));
    }

    /** The size of the screen in cells. @return array{0: int, 1: int} */
    public function size(): array
    {
        return [(int)($this->get(self::DATA_COLS, 'uint16_t')?->cdata ?? 0), (int)($this->get(self::DATA_ROWS, 'uint16_t')?->cdata ?? 0)];
    }

    /** The cursor's column and row in the active screen, the bottom of the scrollback. @return array{0: int, 1: int} */
    public function cursor(): array
    {
        return [(int)($this->get(self::DATA_CURSOR_X, 'uint16_t')?->cdata ?? 0), (int)($this->get(self::DATA_CURSOR_Y, 'uint16_t')?->cdata ?? 0)];
    }

    /** Resets the terminal fully (RIS). */
    public function reset(): void { Lib::ffi()->ghostty_terminal_reset($this->h); }

    /**
     * Sets the default colors, which programs may change: the foreground,
     * the background, the cursor's (null for the text's) and the palette of
     * 256 colors, each 0xRRGGBB.
     *
     * @param list<int> $palette
     */
    public function setColors(int $fg, int $bg, ?int $cursor, array $palette): void
    {
        $f = Lib::ffi();
        $rgb = function (int $c) use ($f): CData {
            $v = $f->new('GhosttyColorRgb');
            $v->r = ($c >> 16) & 0xff; $v->g = ($c >> 8) & 0xff; $v->b = $c & 0xff;
            return $v;
        };
        $this->setTo(self::OPT_COLOR_FOREGROUND, $rgb($fg));
        $this->setTo(self::OPT_COLOR_BACKGROUND, $rgb($bg));
        $this->setTo(self::OPT_COLOR_CURSOR, $cursor === null ? null : $rgb($cursor));
        $p = $f->new('GhosttyColorRgb[256]');
        foreach ($palette as $i => $c) {
            $p[$i]->r = ($c >> 16) & 0xff; $p[$i]->g = ($c >> 8) & 0xff; $p[$i]->b = $c & 0xff;
        }
        $this->setTo(self::OPT_COLOR_PALETTE, $p);
    }

    /** Limits the scrollback to about $n bytes. */
    public function setScrollback(int $n): void { $this->set(self::OPT_SCROLLBACK_MAX_BYTES, 'size_t', max($n, 0)); }

    /** Sets the name of the terminfo entry the terminal reports (XTGETTCAP TN). */
    public function setTerminfoName(string $name): void
    {
        $f = Lib::ffi();
        $b = Lib::buffer($name);
        $s = $f->new('GhosttyString');
        $s->ptr = Lib::ptr($b, 'const uint8_t*');
        $s->len = strlen($name);
        $this->setTo(self::OPT_TERMINFO_NAME, $s);
    }

    /** Sets the cursor that programs reset to (DECSCUSR 0). */
    public function setDefaultCursor(int $style, bool $blink): void
    {
        $this->set(self::OPT_DEFAULT_CURSOR_STYLE, 'int32_t', $style);
        $this->set(self::OPT_DEFAULT_CURSOR_BLINK, 'bool', $blink);
    }

    private function str(int $data): string
    {
        $s = $this->get($data, 'GhosttyString');
        return $s === null ? '' : Lib::str($s);
    }

    /** The title programs set (OSC 0, OSC 2). */
    public function title(): string { return $this->str(self::DATA_TITLE); }

    /** The working directory the shell reported (OSC 7), as it reported it: a file URL, or a path. */
    public function pwd(): string { return $this->str(self::DATA_PWD); }

    /** Whether mode $m is set. */
    public function mode(int $m): bool
    {
        $f = Lib::ffi();
        $c = $f->new('GhosttyTerminalModeConfig');
        $c->mode = $m;
        return $f->ghostty_terminal_get($this->h, self::DATA_MODE, FFI::addr($c)) === Lib::SUCCESS && $c->value;
    }

    /** Sets mode $m. */
    public function setMode(int $m, bool $v): void
    {
        $c = Lib::ffi()->new('GhosttyTerminalModeConfig');
        $c->mode = $m; $c->value = $v;
        $this->setTo(self::OPT_MODE, $c);
    }

    /** Sets mode $m, and the value a reset restores. */
    public function setModeDefault(int $m, bool $v): void
    {
        $c = Lib::ffi()->new('GhosttyTerminalModeConfig');
        $c->mode = $m; $c->value = $v;
        $this->setTo(self::OPT_MODE_DEFAULT, $c);
    }

    /** Whether a program asked for mouse events. */
    public function mouseTracking(): bool { return (bool)($this->get(self::DATA_MOUSE_TRACKING, 'bool')?->cdata ?? false); }

    /** The pointer shape a program asked for (OSC 22), a GhosttyMouseShape. */
    public function mouseShape(): int { return (int)($this->get(self::DATA_MOUSE_SHAPE, 'int32_t')?->cdata ?? 0); }

    /** Whether the alternate screen shows, as in full-screen programs. */
    public function altScreen(): bool { return ($this->get(self::DATA_ACTIVE_SCREEN, 'int32_t')?->cdata ?? 0) === 1; }

    /** Where the viewport is in the scrollback: [total rows, the viewport's first, how many it shows]. @return array{0: int, 1: int, 2: int} */
    public function scrollbar(): array
    {
        $s = $this->get(self::DATA_SCROLLBAR, 'GhosttyTerminalScrollbar');
        return $s === null ? [0, 0, 0] : [(int)$s->total, (int)$s->offset, (int)$s->len];
    }

    /** Whether the viewport follows the active screen, rather than showing the scrollback. */
    public function atBottom(): bool
    {
        $v = $this->get(self::DATA_VIEWPORT_ACTIVE, 'bool');
        return $v === null || $v->cdata;
    }

    private function scrollViewport(int $tag, int $value): void
    {
        $v = Lib::ffi()->new('GhosttyTerminalScrollViewport');
        $v->tag = $tag; $v->value[0] = $value;
        Lib::ffi()->ghostty_terminal_scroll_viewport($this->h, $v);
    }

    /** Moves the viewport to the top of the scrollback. */
    public function scrollToTop(): void { $this->scrollViewport(0, 0); }

    /** Moves the viewport to the active screen. */
    public function scrollToBottom(): void { $this->scrollViewport(1, 0); }

    /** Moves the viewport by $rows, negative up. */
    public function scrollBy(int $rows): void { $this->scrollViewport(2, $rows); }

    /** Moves the viewport to show row $row first. */
    public function scrollToRow(int $row): void { $this->scrollViewport(3, max($row, 0)); }

    /** The cell at column $x of row $y of the viewport, or null. */
    public function cellAt(int $x, int $y): ?CData
    {
        $f = Lib::ffi();
        $ref = $f->new('GhosttyGridRef');
        $ref->size = FFI::sizeof($ref);
        $p = $f->new('GhosttyPoint');
        $p->tag = 1;
        $p->value->x = max(0, min($x, 0xFFFF)); $p->value->y = max($y, 0);
        return $f->ghostty_terminal_grid_ref($this->h, $p, FFI::addr($ref)) === Lib::SUCCESS ? $ref : null;
    }

    /** Sets whether growing the screen pulls rows back from the scrollback. */
    public function setResizePullBack(bool $v): void { $this->set(self::OPT_RESIZE_PULL_BACK, 'bool', $v); }

    /** The text of row $y of the viewport, as runes, and the column of each. @return array{0: list<string>, 1: list<int>} */
    public function rowText(int $y): array
    {
        $f = Lib::ffi();
        [$c] = $this->size();
        $text = []; $cols = [];
        $buf = $f->new('uint32_t[16]');
        $n = $f->new('size_t');
        $raw = $f->new('uint64_t');
        for ($x = 0; $x < $c; $x++) {
            $ref = $this->cellAt($x, $y);
            if ($ref === null) { break; }
            if ($f->ghostty_grid_ref_cell(FFI::addr($ref), FFI::addr($raw)) === Lib::SUCCESS) {
                $w = Lib::bits((int)$raw->cdata, 'wide');
                if ($w === Cell::SPACER_TAIL || $w === Cell::SPACER_HEAD) { continue; }
            }
            if ($f->ghostty_grid_ref_graphemes(FFI::addr($ref), $buf, 16, FFI::addr($n)) !== Lib::SUCCESS || $n->cdata === 0) {
                $text[] = ' '; $cols[] = $x;
                continue;
            }
            for ($k = 0; $k < $n->cdata; $k++) {
                $text[] = mb_chr($buf[$k], 'UTF-8'); $cols[] = $x;
            }
        }
        return [$text, $cols];
    }

    /** The URI of the hyperlink (OSC 8) of a cell, or ''. */
    public function hyperlink(CData $ref): string
    {
        $f = Lib::ffi();
        $n = $f->new('size_t');
        $buf = $f->new('uint8_t[512]');
        $r = $f->ghostty_grid_ref_hyperlink_uri(FFI::addr($ref), $buf, 512, FFI::addr($n));
        if ($r === Lib::OUT_OF_SPACE && $n->cdata > 0) {
            $size = (int)$n->cdata;
            $buf = $f->new("uint8_t[$size]");
            $r = $f->ghostty_grid_ref_hyperlink_uri(FFI::addr($ref), $buf, $size, FFI::addr($n));
        }
        return $r === Lib::SUCCESS ? Lib::bytes($buf, (int)$n->cdata) : '';
    }

    /** Selects $s, a GhosttySelection, or nothing. */
    public function setSelection(?CData $s): void { $this->setTo(self::OPT_SELECTION, $s); }

    /**
     * Moves the selection's end to $ref, keeping its anchor, also of a
     * selection made backwards; false with no selection (mygo's
     * ExtendSelection). $ref is of a fresh snapshot: references do not
     * outlive output, scrolling or resizing.
     */
    public function extendSelection(CData $ref): bool
    {
        $f = Lib::ffi();
        $s = $f->new('GhosttySelection');
        $s->size = FFI::sizeof($s);
        if ($f->ghostty_terminal_get($this->h, self::DATA_SELECTION, FFI::addr($s)) !== Lib::SUCCESS) { return false; }
        [$s->end, $s->rectangle] = [$ref, false];
        return $f->ghostty_terminal_set($this->h, self::OPT_SELECTION, FFI::addr($s)) === Lib::SUCCESS;
    }

    /** A selection of everything, scrollback included, or null. */
    public function selectAll(): ?CData
    {
        $f = Lib::ffi();
        $s = $f->new('GhosttySelection');
        $s->size = FFI::sizeof($s);
        return $f->ghostty_terminal_select_all($this->h, FFI::addr($s)) === Lib::SUCCESS ? $s : null;
    }

    /** The text of the selection, with soft-wrapped lines joined and trailing spaces trimmed, as Ghostty copies it; null for none. */
    public function selectionText(): ?string
    {
        $f = Lib::ffi();
        $o = $f->new('GhosttyTerminalSelectionFormatOptions');
        $o->size = FFI::sizeof($o); $o->unwrap = true; $o->trim = true;
        $p = $f->new('uint8_t*'); $n = $f->new('size_t');
        if ($f->ghostty_terminal_selection_format_alloc($this->h, null, $o, FFI::addr($p), FFI::addr($n)) !== Lib::SUCCESS) { return null; }
        return $this->taken($p, $n);
    }

    /** The bytes the library allocated at $p, freed. */
    private function taken(CData $p, CData $n): string
    {
        if (FFI::isNull($p)) { return ''; }
        $s = FFI::string($p, (int)$n->cdata);
        Lib::ffi()->ghostty_free(null, $p, (int)$n->cdata);
        return $s;
    }

    /** The text of the screen and its scrollback, with soft-wrapped lines joined and trailing spaces trimmed. */
    public function text(): string
    {
        $o = $this->formatterOptions();
        $o->unwrap = true; $o->trim = true;
        return $this->format($o);
    }

    /**
     * The terminal's state as escape sequences, which restore it in a
     * terminal of the same size: the scrollback and the screen with their
     * styles and hyperlinks, then the modes, the scrolling region, the tab
     * stops, the working directory, the keyboard's flags and the cursor.
     * The palette is left out, as the terminal it restores in has colors of
     * its own.
     */
    public function vt(): string
    {
        $o = $this->formatterOptions();
        $o->emit = self::FORMAT_VT;
        $x = $o->extra;
        $x->modes = $x->scrolling_region = $x->tabstops = $x->pwd = $x->keyboard = true;
        $x->screen->cursor = $x->screen->style = $x->screen->hyperlink = $x->screen->protection = $x->screen->kitty_keyboard = $x->screen->charsets = true;
        return $this->padRows($this->format($o));
    }

    /**
     * $b, a VT snapshot, with the blank rows at the end of the screen the
     * formatter leaves out (mygo's padRows). Without them, the rows written
     * scroll fewer into the scrollback of the terminal they are replayed
     * in: what was scrollback shows on the screen, as after clear, and the
     * cursor, on a blank row under the last one written, lands on that row,
     * whose text what is written next overwrites. The rows end where the
     * snapshot places the cursor, one "\r\n" after the other.
     */
    private function padRows(string $b): string
    {
        [, $rows] = $this->size();
        [$total] = $this->scrollbar();
        if ($total <= $rows || $this->altScreen()) {
            return $b; // no scrollback, or a full-screen program's screen, which has none: the cursor's position alone places it
        }
        [$x, $y] = $this->cursor();
        $end = strrpos($b, sprintf("\x1b[%d;%dH", $y + 1, $x + 1));
        if ($end === false) {
            return $b;
        }
        $pad = $total - (substr_count(substr($b, 0, $end), "\r\n") + 1);
        return $pad <= 0 ? $b : substr($b, 0, $end) . str_repeat("\r\n", $pad) . substr($b, $end);
    }

    private function formatterOptions(): CData
    {
        $o = Lib::ffi()->new('GhosttyFormatterTerminalOptions');
        $o->size = FFI::sizeof($o);
        $o->extra->size = FFI::sizeof($o->extra);
        $o->extra->screen->size = FFI::sizeof($o->extra->screen);
        return $o;
    }

    private function format(CData $o): string
    {
        $f = Lib::ffi();
        $fmt = $f->new('void*');
        if ($f->ghostty_formatter_terminal_new(null, FFI::addr($fmt), $this->h, $o) !== Lib::SUCCESS) { return ''; }
        try {
            $p = $f->new('uint8_t*'); $n = $f->new('size_t');
            if ($f->ghostty_formatter_format_alloc($fmt, null, FFI::addr($p), FFI::addr($n)) !== Lib::SUCCESS) { return ''; }
            return $this->taken($p, $n);
        } finally {
            $f->ghostty_formatter_free($fmt);
        }
    }

    /** Compresses some of the scrollback, as when the terminal is idle, and reports whether more is left to compress. */
    public function compress(): bool
    {
        $f = Lib::ffi();
        $r = $f->new('int32_t');
        return $f->ghostty_terminal_compress($this->h, 0, FFI::addr($r)) === Lib::SUCCESS && $r->cdata === 1;
    }

    /** A token that changes when the scrollback may compress. */
    public function activity(): int
    {
        $f = Lib::ffi();
        $v = $f->new('uint64_t');
        $f->ghostty_terminal_compression_activity($this->h, FFI::addr($v));
        return (int)$v->cdata;
    }

    /**
     * Tells the program what the clipboard offers a paste, by MIME type, for
     * it to read what it wants (mode 5522, the Kitty clipboard protocol);
     * the program's reads come to the effects' clipboardRead, granted. It
     * reports whether the event was sent: it is not when the mode is not
     * set or the terminal has no clipboardRead, when the paste is the
     * embedder's to encode (KeyEncoder::encodePaste).
     *
     * @param list<string> $mimes
     */
    public function pasteEvent(array $mimes): bool
    {
        if (!$this->mode(self::MODE_PASTE_EVENTS) || $this->effects->clipboardRead === null) { return false; }
        $f = Lib::ffi();
        $n = count($mimes);
        $keep = [];
        $cs = $f->new('GhosttyString[' . max($n, 1) . ']');
        foreach ($mimes as $i => $m) {
            $keep[] = $b = Lib::buffer($m);
            $cs[$i]->ptr = Lib::ptr($b, 'const uint8_t*');
            $cs[$i]->len = strlen($m);
        }
        $p = $f->new('GhosttyPaste');
        $p->size = FFI::sizeof($p);
        $p->source = 0; // the clipboard
        $p->mimes_len = $n;
        if ($n > 0) {
            $p->mimes = $f->cast('const GhosttyString*', FFI::addr($cs[0]));
            // The library asks a reader for data only when it pastes text itself, which it does not for an event; one is still required.
            $p->reader->read = $f->cast('void*', $this->cbs->no_read);
        }
        $written = $f->new('bool');
        $r = $f->ghostty_terminal_paste($this->h, FFI::addr($p), FFI::addr($written));
        return $r === Lib::SUCCESS && $written->cdata;
    }

    private function clipboardWrite(CData $w): void
    {
        $f = Lib::ffi();
        $reply = $f->new('GhosttyClipboardWriteReply');
        $reply->size = FFI::sizeof($reply);
        $reply->result = 1; // denied
        if ($this->effects->clipboard !== null) {
            // No contents clears the clipboard; otherwise the first plain text, else the first text of another kind, is written.
            $n = (int)$w->contents_len;
            $text = ''; $found = $n === 0;
            for ($i = 0; $i < $n; $i++) {
                $c = $w->contents[$i];
                $mime = Lib::str($c->mime);
                if (str_starts_with($mime, 'text/plain') || (str_starts_with($mime, 'text/') && !$found)) {
                    $text = Lib::str($c->data); $found = true;
                }
            }
            if (!$found) {
                $reply->result = 2; // unsupported
            } elseif (($this->effects->clipboard)($text, $w->location !== 0)) {
                $reply->result = 0;
            }
        }
        ($w->reply)($w, FFI::addr($reply));
    }

    private function clipboardRead(CData $r): void
    {
        if ($this->effects->clipboardRead === null) { return; } // refused by the library
        $f = Lib::ffi();
        $in = new ClipboardRead([], (bool)$r->list, $r->location !== 0, (bool)$r->granted, (bool)$r->can_remember);
        for ($i = 0, $n = (int)$r->mimes_len; $i < $n; $i++) { $in->mimes[] = Lib::str($r->mimes[$i]); }
        $out = ($this->effects->clipboardRead)($in);
        if ($out === null) { // refused, and its refusal dropped: the read of a terminal that is not the one to answer
            $this->silent = true;
            return;
        }
        if (($d = $out->deferred) !== null) { // answered later: the refusal the library writes as this returns is held back (answerClipboard)
            [$d->mimes, $d->list, $d->primary] = [$in->mimes, $in->list, $in->primary];
            $this->deferred = $d;
            return;
        }
        $reply = $f->new('GhosttyClipboardReadReply');
        $reply->size = FFI::sizeof($reply);
        $reply->result = 1; // denied
        $keep = [];
        $str = function (CData $dst, string $s) use ($f, &$keep): void {
            if ($s === '') { return; }
            $keep[] = $b = Lib::buffer($s);
            $dst->ptr = Lib::ptr($b, 'const uint8_t*');
            $dst->len = strlen($s);
        };
        $contents = [];
        foreach ($in->mimes as $m) {
            if (($out->contents[$m] ?? '') !== '') { $contents[$m] = $out->contents[$m]; }
        }
        $cs = $f->new('GhosttyClipboardContent[' . max(count($contents), 1) . ']');
        $i = 0;
        foreach ($contents as $m => $data) {
            $str($cs[$i]->mime, $m);
            $str($cs[$i]->data, $data);
            $i++;
        }
        $av = $f->new('GhosttyString[' . max(count($out->available), 1) . ']');
        foreach ($out->available as $k => $m) { $str($av[$k], $m); }
        if ($contents !== [] || ($in->list && $out->available !== [])) {
            $reply->result = 0;
            if ($contents !== []) {
                $reply->contents = $f->cast('const GhosttyClipboardContent*', FFI::addr($cs[0]));
                $reply->contents_len = count($contents);
            }
            if ($out->available !== []) {
                $reply->available = $f->cast('const GhosttyString*', FFI::addr($av[0]));
                $reply->available_len = count($out->available);
            }
        }
        $this->reply = '';
        ($r->reply)($r, FFI::addr($reply));
        $written = $this->reply;
        $this->reply = null;
        if ($written !== '' && $this->effects->writePTY !== null) { ($this->effects->writePTY)($written); }
    }

    /** Answers a read the clipboardRead effect deferred (mygo's AnswerClipboard): $allowed serves $contents by MIME type and $available for the types listing, else the refusal the library wrote and the binding held back is written. A read whose refusal never came says nothing. @param array<string, string> $contents @param list<string> $available */
    public function answerClipboard(DeferredClipboardRead $d, bool $allowed, array $contents = [], array $available = []): void
    {
        if ($this->effects->writePTY === null || $d->refusal === '') {
            return;
        }
        ($this->effects->writePTY)($allowed ? $d->answer($contents, $available) : $d->refusal);
    }

    private static function clamp16(int $v): int { return max(1, min($v, 0xFFFF)); }
}
