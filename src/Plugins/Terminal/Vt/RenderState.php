<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Terminal\Vt;

use FFI;
use FFI\CData;

/**
 * A GhosttyRenderState (mygo's vt.RenderState): what a frame draws of a
 * terminal, copied from it by an update, with the rows that changed since
 * the last frame marked dirty.
 *
 * @internal
 */
final class RenderState
{
    private const DATA_COLS = 1, DATA_ROWS = 2, DATA_DIRTY = 3, DATA_ROW_ITERATOR = 4, DATA_CURSOR = 18, DATA_COLORS = 19;
    private const ROW_DIRTY = 1, ROW_CELLS = 3, ROW_SELECTION = 4, ROW_CELLS_RAW = 5;
    private const CELL_STYLE = 2, CELL_GRAPHEMES_LEN = 3, CELL_GRAPHEMES_BUF = 4;
    /** How much of a frame changed. */
    public const CLEAN = 0, PARTIAL = 1, FULL = 2;

    private ?CData $h = null;
    /** The row iterator, and the cells of its row. */
    private ?CData $rows = null;
    private ?CData $cells = null;

    public function __construct()
    {
        $f = Lib::ffi();
        $this->h = $f->new('void*');
        Lib::must($f->ghostty_render_state_new(null, FFI::addr($this->h)));
        $this->rows = $f->new('void*');
        $this->cells = $f->new('void*');
        try {
            Lib::must($f->ghostty_render_state_row_iterator_new(null, FFI::addr($this->rows)));
            Lib::must($f->ghostty_render_state_row_cells_new(null, FFI::addr($this->cells)));
        } catch (\RuntimeException $e) {
            $this->free();
            throw $e;
        }
    }

    /** Frees the render state. */
    public function free(): void
    {
        $f = Lib::ffi();
        if ($this->cells !== null && !FFI::isNull($this->cells)) { $f->ghostty_render_state_row_cells_free($this->cells); }
        if ($this->rows !== null && !FFI::isNull($this->rows)) { $f->ghostty_render_state_row_iterator_free($this->rows); }
        if ($this->h !== null && !FFI::isNull($this->h)) { $f->ghostty_render_state_free($this->h); }
        $this->h = $this->rows = $this->cells = null;
    }

    /** Copies the terminal's state, the only step that needs the terminal; endUpdate() finishes the update without it. */
    public function beginUpdate(Terminal $t): void { Lib::must(Lib::ffi()->ghostty_render_state_begin_update($this->h, $t->h)); }

    public function endUpdate(): void { Lib::ffi()->ghostty_render_state_end_update($this->h); }

    /** Updates the render state from the terminal in one step. */
    public function update(Terminal $t): void
    {
        $this->beginUpdate($t);
        $this->endUpdate();
    }

    /** Marks everything drawn. */
    public function clean(): void { Lib::ffi()->ghostty_render_state_clean($this->h); }

    private function get(int $data, string $type): CData
    {
        $c = Lib::ffi()->new($type);
        Lib::ffi()->ghostty_render_state_get($this->h, $data, FFI::addr($c));
        return $c;
    }

    /** How much changed since the last clean(): CLEAN, PARTIAL or FULL. */
    public function dirty(): int { return (int)$this->get(self::DATA_DIRTY, 'int32_t')->cdata; }

    /** The size of the viewport in cells. @return array{0: int, 1: int} */
    public function size(): array { return [(int)$this->get(self::DATA_COLS, 'uint16_t')->cdata, (int)$this->get(self::DATA_ROWS, 'uint16_t')->cdata]; }

    /** The colors of the frame. */
    public function colors(): Colors
    {
        $c = Lib::ffi()->new('GhosttyRenderStateColors');
        $c->size = FFI::sizeof($c);
        Lib::ffi()->ghostty_render_state_get($this->h, self::DATA_COLORS, FFI::addr($c));
        $rgb = fn(CData $v): int => ($v->r << 16) | ($v->g << 8) | $v->b;
        $pal = [];
        for ($i = 0; $i < 256; $i++) { $pal[] = $rgb($c->palette[$i]); }
        return new Colors($rgb($c->background), $rgb($c->foreground), $rgb($c->cursor), (bool)$c->cursor_has_value, $pal);
    }

    /** The cursor of the frame. */
    public function cursor(): Cursor
    {
        $c = Lib::ffi()->new('GhosttyRenderStateCursor');
        $c->size = FFI::sizeof($c);
        Lib::ffi()->ghostty_render_state_get($this->h, self::DATA_CURSOR, FFI::addr($c));
        return new Cursor((bool)$c->viewport_has_value, (int)$c->viewport_x, (int)$c->viewport_y, (bool)$c->wide_tail,
            (bool)$c->visible, (bool)$c->blinking, (bool)$c->password_input, (int)$c->visual_style);
    }

    /** Starts going through the rows of the frame, top to bottom, with nextRow(). */
    public function rows(): void { Lib::ffi()->ghostty_render_state_get($this->h, self::DATA_ROW_ITERATOR, FFI::addr($this->rows)); }

    /** Moves to the next row, and reports whether there is one. */
    public function nextRow(): bool { return Lib::ffi()->ghostty_render_state_row_iterator_next($this->rows); }

    private function rowGet(int $data, CData $out): bool
    {
        return Lib::ffi()->ghostty_render_state_row_get($this->rows, $data, FFI::addr($out)) === Lib::SUCCESS;
    }

    /** Whether the row changed since the last clean(). */
    public function rowDirty(): bool
    {
        $v = Lib::ffi()->new('bool');
        return $this->rowGet(self::ROW_DIRTY, $v) && $v->cdata;
    }

    /** The selected columns of the row, from start to end inclusive, or null. @return ?array{0: int, 1: int} */
    public function rowSelection(): ?array
    {
        $s = Lib::ffi()->new('GhosttyRenderStateRowSelection');
        $s->size = FFI::sizeof($s);
        return $this->rowGet(self::ROW_SELECTION, $s) ? [(int)$s->start_x, (int)$s->end_x] : null;
    }

    /** The cells of the row, valid until the next update. @return list<Cell> */
    public function rowCells(): array
    {
        $v = Lib::ffi()->new('GhosttyCellsView');
        if (!$this->rowGet(self::ROW_CELLS_RAW, $v) || $v->ptr === null) { return []; }
        $out = [];
        for ($i = 0, $n = (int)$v->len; $i < $n; $i++) {
            $c = (int)$v->ptr[$i];
            $cell = new Cell;
            $tag = Lib::bits($c, 'tag');
            if ($tag === 0 || $tag === 1) { // a codepoint, of a grapheme cluster for 1
                $cell->codepoint = Lib::bits($c, 'codepoint');
                $cell->grapheme = $tag === 1;
            } elseif ($tag === 2) {
                $cell->hasBackground = $cell->palette = true;
                $cell->background = Lib::bits($c, 'paletteIndex');
            } else {
                $cell->hasBackground = true;
                $cell->background = (Lib::bits($c, 'r') << 16) | (Lib::bits($c, 'g') << 8) | Lib::bits($c, 'b');
            }
            $cell->style = Lib::bits($c, 'styleID');
            $cell->wide = Lib::bits($c, 'wide');
            $cell->hyperlink = Lib::bits($c, 'hyperlink') !== 0;
            $out[] = $cell;
        }
        // The cells of the row for the style and grapheme lookups.
        $this->rowGet(self::ROW_CELLS, $this->cells);
        return $out;
    }

    /** The style of cell $x of the row rowCells() read. */
    public function cellStyle(int $x): Style
    {
        $f = Lib::ffi();
        $s = $f->new('GhosttyStyle');
        $s->size = FFI::sizeof($s);
        if ($f->ghostty_render_state_row_cells_select($this->cells, $x) === Lib::SUCCESS) {
            $f->ghostty_render_state_row_cells_get($this->cells, self::CELL_STYLE, FFI::addr($s));
        }
        $color = fn(CData $c): Color => match ((int)$c->tag) {
            Color::PALETTE => new Color(Color::PALETTE, (int)$c->value[0]),
            Color::RGB => new Color(Color::RGB, 0, ($c->value[0] << 16) | ($c->value[1] << 8) | $c->value[2]),
            default => new Color,
        };
        return new Style($color($s->fg_color), $color($s->bg_color), $color($s->underline_color), (bool)$s->bold, (bool)$s->italic, (bool)$s->faint,
            (bool)$s->blink, (bool)$s->inverse, (bool)$s->invisible, (bool)$s->strikethrough, (bool)$s->overline, (int)$s->underline);
    }

    /** The codepoints of cell $x of the row rowCells() read. @return list<int> */
    public function cellGraphemes(int $x): array
    {
        $f = Lib::ffi();
        if ($f->ghostty_render_state_row_cells_select($this->cells, $x) !== Lib::SUCCESS) { return []; }
        $n = $f->new('uint32_t');
        $f->ghostty_render_state_row_cells_get($this->cells, self::CELL_GRAPHEMES_LEN, FFI::addr($n));
        if ($n->cdata === 0) { return []; }
        $cps = $f->new('uint32_t[' . $n->cdata . ']');
        $f->ghostty_render_state_row_cells_get($this->cells, self::CELL_GRAPHEMES_BUF, $cps);
        $out = [];
        for ($i = 0; $i < $n->cdata; $i++) { $out[] = (int)$cps[$i]; }
        return $out;
    }
}
