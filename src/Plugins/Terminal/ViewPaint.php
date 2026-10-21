<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Terminal;

use MyPHP\Plugins\Terminal\Vt\{Cell, Color, RenderState, Style as VtStyle, Terminal as VtTerminal};
use MyPHP\Text\{Glyph, Layout, Line, Params, Style};
use MyPHP\UI\{DisplayList, Path, TextPainter};

/**
 * Drawing the terminal (mygo's paint.go): the grid sized to the node, the
 * rows that changed made again, then backgrounds, the selection, text,
 * lines, the cursor, an input method's composition and the scroll bar.
 *
 * @internal part of View
 */
trait ViewPaint
{
    /** Padding around the grid, in points. */
    private const PAD_X = 6, PAD_Y = 4;
    /** A line of a mouse wheel, as the window's events have it, in points. */
    private const WHEEL_LINE = 10.0;
    private const ASCII = " !\"#$%&'()*+,-./0123456789:;<=>?@ABCDEFGHIJKLMNOPQRSTUVWXYZ[\\]^_`abcdefghijklmnopqrstuvwxyz{|}~";
    private const DECO_STRIKE = 10, DECO_OVERLINE = 11;
    /** How many shaped texts are kept. */
    private const SHAPED = 4096;

    private ?Params $noParams = null;

    /** Sizes the grid for a box of the node at a scale. @param array{0: float, 1: float, 2: float, 3: float} $r */
    private function layout(array $r, float $scale): void
    {
        $fk = $this->t->font()->key($scale);
        if ($fk !== $this->font) {
            $this->font = $fk;
            $this->scale = $scale;
            $this->fonts = [];
            for ($i = 0; $i < 4; $i++) {
                $w = $fk['weight'];
                if (($i & 1) !== 0) { $w = min(max($w + 300, 700), 900); }
                $this->fonts[] = new Style($fk['family'], $fk['size'], $w, ($i & 2) !== 0, features: $fk['features']);
            }
            [$ascent, $descent, $lineHeight] = $this->sys->metrics($this->fonts[0]);
            $this->shaped = [];
            $adv = 0.0;
            foreach ($this->shape(self::ASCII, 0, 1.0) as $g) { $adv = max($adv, $g->advance); }
            $this->cellW = max((int)ceil($adv * $scale - 0.05), 1);
            $this->cellH = max((int)ceil($lineHeight * $fk['lineHeight'] * $scale - 0.05), 1);
            $this->baseline = (int)round(($this->cellH - ($ascent + $descent) * $scale) / 2 + $ascent * $scale);
            $this->lines = [];
        }
        $this->ox = (int)round(self::PAD_X * $scale);
        $this->oy = (int)round(self::PAD_Y * $scale);
        $w = (int)round($r[2] * $scale) - 2 * $this->ox;
        $h = (int)round($r[3] * $scale) - 2 * $this->oy;
        $this->cols = max(intdiv(max($w, 0), $this->cellW), 1);
        $this->rows = max(intdiv(max($h, 0), $this->cellH), 1);
    }

    /** Updates the rows that changed and draws the terminal. @param array{0: float, 1: float, 2: float, 3: float} $r */
    public function paint(DisplayList $list, TextPainter $text, array $r): void
    {
        $t = $this->t;
        $this->layout($r, $text->scale());
        if ($t->term === null || $t->rs === null) {
            if (!$t->opts->transparent) { $list->rect($r[0], $r[1], $r[2], $r[3], $this->theme->background); }
            return;
        }
        $t->resizeGrid([$this->cols, $this->rows, $this->cellW, $this->cellH]);
        if ($this->ticking && $this->gesture !== null) {
            $this->autoscroll();
        }
        // A synchronized update shows when it ends, or after a second.
        if ($t->held && microtime(true) - $t->heldSince > 1.0) {
            $t->term->setMode(VtTerminal::MODE_SYNC_OUTPUT, false);
            $t->held = false;
        }
        $rs = $t->rs;
        if (!$t->held) {
            $rs->beginUpdate($t->term);
        }
        $this->scrollbar = $t->term->scrollbar();
        if (!$t->held) {
            $rs->endUpdate();
        }
        $colors = $rs->colors();
        $full = $rs->dirty() === RenderState::FULL || $this->colors === null || !$colors->equals($this->colors);
        $this->colors = $colors;
        $this->cursor = $rs->cursor();
        [$cols, $rows] = $rs->size();
        if (count($this->lines) !== $rows) {
            $this->lines = array_fill(0, $rows, null);
            $full = true;
        }
        $rs->rows();
        for ($y = 0; $y < $rows && $rs->nextRow(); $y++) {
            if ($full || $this->lines[$y] === null || $rs->rowDirty()) {
                $this->lines[$y] = $this->buildRow($rs, $cols);
            }
        }
        $rs->clean();
        $this->draw($list, $text, $r);
    }

    /** A color of the emulator, opaque. @return array{0: int, 1: float} */
    private static function opaque(int $rgb): array { return [$rgb, 1.0]; }

    /** A cell's style with its colors resolved (mygo's resolved): fg, bg, hasBg, ul, hasUl, variant (1 bold, 2 italic), invisible, underline, strike, overline. */
    private function resolve(VtStyle $s): array
    {
        $pal = $this->colors->palette;
        $col = fn(Color $c, int $def): array => match ($c->kind) {
            Color::PALETTE => [self::opaque($pal[$c->index] ?? 0), true],
            Color::RGB => [self::opaque($c->rgb), true],
            default => [self::opaque($def), false],
        };
        [$fg] = $col($s->fg, $this->colors->foreground);
        [$bg, $hasBg] = $col($s->bg, $this->colors->background);
        [$ul, $hasUl] = $col($s->underlineColor, $this->colors->foreground);
        if ($s->inverse) {
            [$fg, $bg, $hasBg] = [$bg, $fg, true];
        }
        if ($s->faint) { $fg = [$fg[0], 0.5]; }
        return ['fg' => $fg, 'bg' => $bg, 'hasBg' => $hasBg, 'ul' => $ul, 'hasUl' => $hasUl, 'variant' => ($s->bold ? 1 : 0) | ($s->italic ? 2 : 0),
            'invisible' => $s->invisible, 'underline' => $s->underline, 'strike' => $s->strikethrough, 'overline' => $s->overline];
    }

    /** The cache of the render state's current row. */
    private function buildRow(RenderState $rs, int $cols): RowCache
    {
        $line = new RowCache;
        $cells = $rs->rowCells();
        if (($sel = $rs->rowSelection()) !== null) {
            [$line->selA, $line->selB] = $sel;
            $line->sel = true;
        }
        $def = ['fg' => self::opaque($this->colors->foreground), 'bg' => self::opaque($this->colors->background), 'hasBg' => false, 'ul' => self::opaque($this->colors->foreground),
            'hasUl' => false, 'variant' => 0, 'invisible' => false, 'underline' => 0, 'strike' => false, 'overline' => false];
        $styles = [];
        $run = ['text' => [], 'cols' => [], 'widths' => [], 'fg' => $def['fg'], 'variant' => 0];
        $flush = function () use (&$run, $line): void {
            $this->shapeRun($line, $run['text'], $run['cols'], $run['widths'], $run['fg'], $run['variant']);
            $run['text'] = $run['cols'] = $run['widths'] = [];
        };
        for ($x = 0, $n = min(count($cells), $cols); $x < $n; $x++) {
            /** @var Cell $c */
            $c = $cells[$x];
            if ($c->wide === Cell::SPACER_TAIL || $c->wide === Cell::SPACER_HEAD) {
                continue;
            }
            $w = 1;
            if ($c->wide === Cell::WIDE) {
                $w = 2;
                $line->wide[] = $x;
            }
            $st = $c->style === 0 ? $def : ($styles[$c->style] ??= $this->resolve($rs->cellStyle($x)));
            if ($c->hasBackground) {
                $st['bg'] = self::opaque($c->palette ? ($this->colors->palette[$c->background] ?? 0) : $c->background);
                $st['hasBg'] = true;
            }
            if ($st['hasBg'] && $st['bg'] !== $def['bg']) {
                $k = count($line->bgs) - 1;
                if ($k >= 0 && $line->bgs[$k][1] === $x && $line->bgs[$k][2] === $st['bg']) {
                    $line->bgs[$k][1] = $x + $w;
                } else {
                    $line->bgs[] = [$x, $x + $w, $st['bg']];
                }
            }
            if ($st['underline'] !== 0) { $this->addDeco($line, $x, $x + $w, $st['underline'], $st['hasUl'] ? $st['ul'] : $st['fg']); }
            if ($st['strike']) { $this->addDeco($line, $x, $x + $w, self::DECO_STRIKE, $st['fg']); }
            if ($st['overline']) { $this->addDeco($line, $x, $x + $w, self::DECO_OVERLINE, $st['fg']); }
            $cp = $c->codepoint;
            if ($cp === 0 || $st['invisible']) { $cp = 0x20; }
            if (Box::drawn($cp)) {
                $line->boxes[] = [$x, $cp, $st['fg']];
                $cp = 0x20;
            }
            if ($run['text'] !== [] && ($st['variant'] !== $run['variant'] || $st['fg'] !== $run['fg'])) {
                if ($cp === 0x20) {
                    // A space draws nothing: it can join any run.
                    $st['variant'] = $run['variant']; $st['fg'] = $run['fg'];
                } else {
                    $flush();
                }
            }
            if ($run['text'] === []) {
                $run['fg'] = $st['fg']; $run['variant'] = $st['variant'];
            }
            $buf = $c->grapheme && $cp !== 0x20 ? $rs->cellGraphemes($x) : [];
            if ($buf === []) { $buf = [$cp]; }
            foreach ($buf as $i => $rn) {
                $run['text'][] = $rn;
                $run['cols'][] = $x;
                $run['widths'][] = $i === 0 ? $w : 0; // the runes after the first share its cell
            }
        }
        $flush();
        return $line;
    }

    /** @param array{0: int, 1: float} $c */
    private function addDeco(RowCache $line, int $x0, int $x1, int $kind, array $c): void
    {
        $k = count($line->decos) - 1;
        if ($k >= 0 && $line->decos[$k][1] === $x0 && $line->decos[$k][2] === $kind && $line->decos[$k][3] === $c) {
            $line->decos[$k][1] = $x1;
            return;
        }
        $line->decos[] = [$x0, $x1, $kind, $c];
    }

    /** Shapes a run of cells and places its glyphs in their cells. @param list<int> $text @param list<int> $cols @param list<int> $widths @param array{0: int, 1: float} $fg */
    private function shapeRun(RowCache $line, array $text, array $cols, array $widths, array $fg, int $variant): void
    {
        // Trailing spaces draw nothing.
        while ($text !== [] && $text[count($text) - 1] === 0x20) {
            array_pop($text); array_pop($cols); array_pop($widths);
        }
        if ($text === []) {
            return;
        }
        $str = self::str($text);
        $shaped = $this->shape($str, $variant, 1.0);
        $cw = $this->cellW / $this->scale;
        $glyphs = []; $gcols = [];
        $nc = count($cols);
        $colOf = fn(Glyph $g): int => $cols[max(0, min($g->cluster, $nc - 1))];
        // The glyphs of a cell, or of the cells a ligature spans, keep their places relative to each other and are centered in the cells, as the glyphs of fallback fonts, wider or narrower than a cell, are. Those too wide, as emoji are, shrink to fit, as in Ghostty.
        for ($i = 0, $ng = count($shaped); $i < $ng;) {
            $first = max(0, min($shaped[$i]->cluster, $nc - 1));
            $col = $cols[$first];
            $last = $first;
            $x0 = $shaped[$i]->x; $x1 = $shaped[$i]->x + $shaped[$i]->advance;
            $j = $i;
            while (true) {
                $g = $shaped[$j];
                $last = max($last, min($g->cluster + max($g->runes, 1), $nc) - 1);
                $x0 = min($x0, $g->x); $x1 = max($x1, $g->x + $g->advance);
                if ($j + 1 >= $ng || $colOf($shaped[$j + 1]) !== $col) {
                    break;
                }
                $j++;
            }
            $span = 0;
            for ($k = $first; $k <= $last; $k++) { $span += $widths[$k]; }
            $room = max($span, 1) * $cw;
            $group = array_slice($shaped, $i, $j - $i + 1);
            if ($x1 > $x0 && ($f = $room / ($x1 - $x0)) < 0.95) {
                // Too wide: the same text, smaller.
                $small = $this->shape(self::str(array_slice($text, $first, $last - $first + 1)), $variant, max($f, 0.5));
                if ($small !== []) {
                    $group = $small;
                    $x0 = $small[0]->x; $x1 = $small[0]->x + $small[0]->advance;
                    foreach ($small as $g) { $x0 = min($x0, $g->x); $x1 = max($x1, $g->x + $g->advance); }
                }
            }
            $off = ($room - ($x1 - $x0)) / 2;
            if (abs($off) < 0.5 / $this->scale) { $off = 0.0; }
            $cellX = $col * $cw;
            foreach ($group as $g) {
                $p = clone $g;
                $p->x = $cellX + $off + ($g->x - $x0);
                $p->y = 0.0;
                $glyphs[] = $p;
                $gcols[] = $col;
            }
            $i = $j + 1;
        }
        $line->runs[] = [$this->glyphLayout($glyphs), $gcols, $fg];
    }

    /** @param list<int> $runes */
    private static function str(array $runes): string
    {
        return implode('', array_map(fn(int $r) => ($c = mb_chr($r, 'UTF-8')) === false ? "\u{FFFD}" : $c, $runes)); // not ?:, which takes "0" for false
    }

    /** Glyphs as a layout of one line, for TextPainter::paintLayout(): their x from the left of the grid, their baseline at 0. @param list<Glyph> $glyphs */
    private function glyphLayout(array $glyphs): Layout
    {
        $l = new Layout($this->noParams ??= new Params(''), []);
        $line = new Line(height: $this->cellH / $this->scale);
        $line->glyphs = $glyphs;
        $l->lines = [$line];
        return $l;
    }

    /** Text shaped in a variant of the font, at a fraction of its size, from the cache. @return list<Glyph> */
    private function shape(string $text, int $variant, float $scale): array
    {
        $k = "$variant\0$scale\0$text";
        if (isset($this->shaped[$k])) {
            $g = $this->shaped[$k];
            unset($this->shaped[$k]);
            return $this->shaped[$k] = $g; // the most recent last
        }
        $style = $this->fonts[$variant & 3];
        if ($scale != 1.0) { $style = $style->with(['size' => $style->size * $scale]); }
        $l = $this->sys->layout(new Params($text, $style, noWrap: true));
        $g = $l->lines[0]->glyphs ?? [];
        if (count($this->shaped) >= self::SHAPED) {
            unset($this->shaped[array_key_first($this->shaped)]);
        }
        return $this->shaped[$k] = $g;
    }

    /** Draws the rows and the cursor. @param array{0: float, 1: float, 2: float, 3: float} $r */
    private function draw(DisplayList $list, TextPainter $text, array $r): void
    {
        $s = $this->scale;
        $cw = $this->cellW / $s; $ch = $this->cellH / $s;
        // The grid starts at a whole pixel of the display.
        $ox = (round($r[0] * $s) + $this->ox) / $s;
        $oy = (round($r[1] * $s) + $this->oy) / $s;
        $base = $this->baseline / $s;
        if (!$this->t->opts->transparent) {
            $list->rect($r[0], $r[1], $r[2], $r[3], $this->colors->background);
        }
        $selection = $this->theme->selection !== null ? [$this->theme->selection, 1.0] : [$this->colors->foreground, 0.3];
        foreach ($this->lines as $y => $line) {
            if ($line === null) { continue; }
            $ry = $oy + $y * $ch;
            foreach ($line->bgs as [$x0, $x1, $c]) {
                $list->rect($ox + $x0 * $cw, $ry, ($x1 - $x0) * $cw, $ch, $c[0], $c[1]);
            }
            if ($line->sel) {
                $list->rect($ox + $line->selA * $cw, $ry, ($line->selB - $line->selA + 1) * $cw, $ch, $selection[0], $selection[1]);
            }
        }
        [$cur, $curColor, $curText, $block] = $this->cursorRect($ox, $oy, $cw, $ch);
        if ($block) {
            $list->rect($cur[0], $cur[1], $cur[2], $cur[3], $curColor[0], $curColor[1]);
        }
        foreach ($this->lines as $y => $line) {
            if ($line === null) { continue; }
            $ry = $oy + $y * $ch;
            if (!$line->sel || $this->theme->selectionText === null) {
                $this->drawText($list, $text, $line, $ox, $ry, $cw, $ch, $base, null);
                continue;
            }
            // The selected text takes the selection's color in its cells, as far as its glyphs reach.
            $a = $ox + $line->selA * $cw; $b = $ox + ($line->selB + 1) * $cw;
            foreach ([[$r[0], $a - $r[0], null], [$a, $b - $a, [$this->theme->selectionText, 1.0]], [$b, $r[0] + $r[2] - $b, null]] as [$cx, $cwid, $over]) {
                $list->pushClip($cx, $r[1], max($cwid, 0), $r[3]);
                $this->drawText($list, $text, $line, $ox, $ry, $cw, $ch, $base, $over);
                $list->popClip();
            }
        }
        if ($cur[2] > 0 && !$block) {
            if ($this->focused || $this->cursor->style !== VtTerminal::CURSOR_BLOCK) {
                $list->rect($cur[0], $cur[1], $cur[2], $cur[3], $curColor[0], $curColor[1]);
            } else {
                $list->ring($cur[0], $cur[1], $cur[2], $cur[3], $curColor[0], $curColor[1], 0, 1 / $s);
            }
        }
        if ($block && isset($this->lines[$this->cursor->y])) {
            // The text under a block cursor, in the cursor's text color.
            $line = $this->lines[$this->cursor->y];
            $ry = $oy + $this->cursor->y * $ch;
            $list->pushClip($cur[0], $cur[1], $cur[2], $cur[3]);
            foreach ($line->runs as [$l, $gcols]) {
                $under = [];
                foreach ($gcols as $i => $col) {
                    if ($col === $this->cursor->x) { $under[] = $l->lines[0]->glyphs[$i]; }
                }
                if ($under !== []) {
                    $text->paintLayout($list, $this->glyphLayout($under), $ox, $ry + $base, fn() => $curText);
                }
            }
            foreach ($line->boxes as [$bx, $rn]) {
                if ($bx === $this->cursor->x) { Box::draw($list, $s, $rn, [$ox + $bx * $cw, $ry, $cw, $ch], $curText); }
            }
            $list->popClip();
        }
        if ($this->preedit !== '' && $this->focused) {
            $this->drawPreedit($list, $text, $ox, $oy, $cw, $ch, $base);
        }
        $this->drawScrollbar($list, $r);
    }

    /** Draws the glyphs, drawn characters and lines of a row, in their colors or, when $over is set, in $over. @param ?array{0: int, 1: float} $over */
    private function drawText(DisplayList $list, TextPainter $text, RowCache $line, float $ox, float $ry, float $cw, float $ch, float $base, ?array $over): void
    {
        foreach ($line->runs as [$l, , $c]) {
            $color = $over ?? $c;
            $text->paintLayout($list, $l, $ox, $ry + $base, fn() => $color);
        }
        foreach ($line->boxes as [$bx, $rn, $c]) {
            Box::draw($list, $this->scale, $rn, [$ox + $bx * $cw, $ry, $cw, $ch], $over ?? $c);
        }
        foreach ($line->decos as $d) {
            $this->drawDeco($list, $d[0], $d[1], $d[2], $over ?? $d[3], $ox, $ry, $cw, $base);
        }
    }

    /**
     * The cursor's rectangle and colors, and whether it is a filled block,
     * whose text shows in the cursor's text color. A cursor that does not
     * show has an empty rectangle.
     *
     * @return array{0: array{0: float, 1: float, 2: float, 3: float}, 1: array{0: int, 1: float}, 2: array{0: int, 1: float}, 3: bool}
     */
    private function cursorRect(float $ox, float $oy, float $cw, float $ch): array
    {
        $none = [[0.0, 0.0, 0.0, 0.0], [0, 1.0], [0, 1.0], false];
        $cu = $this->cursor;
        if (!$cu->inView || !$cu->visible || $this->preedit !== '') {
            return $none;
        }
        if ($this->focused && $cu->blinking && fmod(($this->now - $this->blinkStart) / 1e9, 2 * self::BLINK) >= self::BLINK) {
            return $none;
        }
        $c = [$this->colors->hasCursor ? $this->colors->cursor : ($this->theme->cursor ?? $this->colors->foreground), 1.0];
        $text = [$this->theme->cursorText ?? $this->colors->background, 1.0];
        $x = $cu->x;
        if ($cu->wideTail && $x > 0) { $x--; }
        $w = $this->wideAt($cu->y, $x) ? 2 * $cw : $cw;
        $r = [$ox + $x * $cw, $oy + $cu->y * $ch, $w, $ch];
        $px = 1 / $this->scale;
        $style = $cu->style;
        if (!$this->focused && $style === VtTerminal::CURSOR_BLOCK) {
            $style = VtTerminal::CURSOR_BLOCK_HOLLOW;
        }
        switch ($style) {
            case VtTerminal::CURSOR_BAR:
                $r[2] = max(round(1.5 * $this->scale), 1) * $px;
                return [$r, $c, $text, false];
            case VtTerminal::CURSOR_UNDERLINE:
                $h = max(round(1.5 * $this->scale), 1) * $px;
                $r[1] += $r[3] - $h; $r[3] = $h;
                return [$r, $c, $text, false];
            case VtTerminal::CURSOR_BLOCK_HOLLOW:
                return [$r, $c, $text, false]; // stroked
        }
        return [$r, $c, $text, true];
    }

    /** Whether the cell at column $x of row $y holds a wide character. */
    private function wideAt(int $y, int $x): bool
    {
        return isset($this->lines[$y]) && in_array($x, $this->lines[$y]->wide, true);
    }

    /** Draws an underline, a strikethrough or an overline. @param array{0: int, 1: float} $c */
    private function drawDeco(DisplayList $list, int $from, int $to, int $kind, array $c, float $ox, float $ry, float $cw, float $base): void
    {
        $px = 1 / $this->scale;
        $size = $this->font['size'];
        $thick = max(round($size * $this->scale / 14), 1) * $px;
        $x0 = $ox + $from * $cw; $x1 = $ox + $to * $cw;
        $ul = $ry + $base + max(round($size * $this->scale / 10), 1) * $px;
        switch ($kind) {
            case self::DECO_STRIKE:
                $list->rect($x0, $ry + $base - $size * 0.3, $x1 - $x0, $thick, $c[0], $c[1]);
                break;
            case self::DECO_OVERLINE:
                $list->rect($x0, $ry, $x1 - $x0, $thick, $c[0], $c[1]);
                break;
            case VtStyle::UNDERLINE_DOUBLE:
                $list->rect($x0, $ul - $thick, $x1 - $x0, $thick, $c[0], $c[1]);
                $list->rect($x0, $ul + $thick, $x1 - $x0, $thick, $c[0], $c[1]);
                break;
            case VtStyle::UNDERLINE_CURLY:
                $path = new Path;
                $amp = 1.5 * $thick;
                $period = $cw;
                $n = (int)(($x1 - $x0) / $period * 8);
                $path->moveTo($x0, $ul);
                for ($i = 1; $i <= $n; $i++) {
                    $x = $x0 + ($x1 - $x0) * $i / $n;
                    $path->lineTo($x, $ul + $amp * sin(($x - $x0) / $period * 2 * M_PI));
                }
                $list->path($path, $c[0], $c[1], $thick);
                break;
            case VtStyle::UNDERLINE_DOTTED:
            case VtStyle::UNDERLINE_DASHED:
                [$step, $dash] = $kind === VtStyle::UNDERLINE_DASHED ? [$cw / 2, $cw / 4] : [2 * $thick, $thick];
                for ($x = $x0; $x < $x1; $x += $step) {
                    $list->rect($x, $ul, min($dash, $x1 - $x), $thick, $c[0], $c[1]);
                }
                break;
            default:
                $list->rect($x0, $ul, $x1 - $x0, $thick, $c[0], $c[1]);
        }
    }

    /** Draws an input method's composition over the cursor. */
    private function drawPreedit(DisplayList $list, TextPainter $text, float $ox, float $oy, float $cw, float $ch, float $base): void
    {
        $g = $this->shape($this->preedit, 0, 1.0);
        $w = 0.0;
        $placed = [];
        foreach ($g as $gl) {
            $w = max($w, $gl->x + $gl->advance);
            $p = clone $gl;
            $p->y = 0.0;
            $placed[] = $p;
        }
        $x = $ox + $this->cursor->x * $cw; $y = $oy + $this->cursor->y * $ch;
        $list->rect($x, $y, $w, $ch, $this->colors->background);
        $fg = $this->colors->foreground;
        $text->paintLayout($list, $this->glyphLayout($placed), $x, $y + $base, fn() => [$fg, 1.0]);
        $list->rect($x, $y + $ch - 1 / $this->scale, $w, 1 / $this->scale, $fg);
    }

    /** Shows where the viewport is while it shows the scrollback. @param array{0: float, 1: float, 2: float, 3: float} $r */
    private function drawScrollbar(DisplayList $list, array $r): void
    {
        [$total, $offset, $len] = $this->scrollbar;
        if ($total <= $len || $offset + $len >= $total) {
            return;
        }
        $h = max($r[3] * $len / $total, 24);
        $y = $r[1] + ($r[3] - $h) * $offset / ($total - $len);
        $list->rect($r[0] + $r[2] - 7, $y + 2, 4, $h - 4, $this->colors->foreground, 0.35, 2);
    }
}
