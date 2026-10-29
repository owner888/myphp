<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

use MyPHP\UI\{DisplayList, Path, TextPainter, Theme as UITheme};

/** Painting an editor (mygo's paint.go). */
trait EditorPaint
{
    /** How long the caret shows and hides, as Zed's. */
    private static float $blinkInterval = 0.5;

    /** The text engine the editor's lines are measured and painted with, as of its last frame. */
    public ?TextPainter $tp = null;

    /** Sets the view's geometry for a frame of size $w by $h, before painting, at $scale. */
    public function layout(float $w, float $h, float $scale): void
    {
        $v = $this->view;
        $v->setStyle($this->tp, $this->o->fontSize, $this->o->lineHeight, $scale, $this->tabSize());
        $this->gutterW = $this->gutterWidth();
        $pad = $this->gutterW == 0 ? 8.0 : 4.0;
        $this->textX = $this->gutterW + $pad;
        if ($this->o->signs) { $this->textX += 2 * $v->charWidth; }
        $this->viewH = $h;
        $wrap = $this->o->wrap !== $this->toggledWrap;
        $ww = $w - $this->textX - $this->trackWidth() - 4; // a diff fitting its content wraps at its edge, no scroll bar's room kept (mygo's 1967821)
        if ($this->o->wrapColumn > 0) { $ww = min($ww, $this->o->wrapColumn * $v->charWidth); }
        $v->setWrap($wrap, max($ww, $v->charWidth * 8));
        if ($wrap) {
            // Wrapped lines fit: nothing is sideways.
            $this->scrollX = 0.0;
        }
        $v->heights->setLines($v->text->lineCount());
        $hidden = $this->hiddenSpans();
        $blocks = [];
        if (($ds = $this->o->decor) instanceof DiffSideView) {
            $hidden = self::mergeSpans([...$hidden, ...$ds->hidden()]);
            $blocks = $ds->blocks();
        }
        $v->heights->setHidden($hidden);
        $v->heights->setBlocks($blocks);
    }

    /** Fills a rect in a color [rgb, alpha], when it shows. */
    private static function fill(DisplayList $list, float $x, float $y, float $w, float $h, array $c, float $radius = 0.0): void
    {
        if ($c[1] > 0) { $list->rect($x, $y, $w, $h, $c[0], $c[1], $radius); }
    }

    /** Paints the editor in its box $r, [x, y, w, h] in the window. */
    public function paint(DisplayList $list, TextPainter $text, array $r): void
    {
        $v = $this->view;
        $this->tp = $text;
        $this->frameNow = hrtime(true) / 1e9;
        $this->r = $r;
        [$rx, $ry, $rw, $rh] = $r;
        $this->layout($rw, $rh, $text->scale());
        $more = !$v->measureWraps($this->anchorLine, 0.004);
        if ($this->o->sharedScroll === null) {
            $this->restoreScroll();
            $this->doReveal();
        }
        if ($this->autoscroll()) { $this->services?->animationFrame(); }
        $ct = $this->theme;
        // The lines in view: those the window shows of the element, for an editor as high as its content.
        $clip = $list->visible();
        $y0 = $this->scrollY + max(0.0, $clip[1] - $ry);
        $y1 = $this->scrollY + min($rh, $clip[1] + $clip[3] - $ry);
        $f = new PaintFrame;
        [$f->first, $f->last] = $this->visibleLines($y0, $y1);
        $this->shown = [$f->first, $f->last];
        if (!$v->advanceSyntax($f->last + 1, 0.004)) { $more = true; }
        $v->approx = false;
        self::fill($list, $rx, $ry, $rw, $rh, $ct->c['background']);
        $this->prepare($f);
        if ($this->focused) {
            $phase = $this->frameNow - $this->blinkStart;
            $f->caretsOn = ((int)floor($phase / self::$blinkInterval)) % 2 === 0 || $this->gesture->active;
            if (!$this->gesture->active) { $this->services?->after(self::$blinkInterval - fmod($phase, self::$blinkInterval)); }
        } elseif ($this->o->cursorShape === CursorShape::CursorBlock) {
            // Outlined, not blinking, as Vim's and Zed's blocks without the keyboard.
            $f->caretsOn = true;
        }
        $this->paintBlocks($list, $text, $r, $f);
        $list->pushClip($rx + $this->gutterW, $ry, $rw - $this->gutterW, $rh);
        $this->paintLines($list, $r, $f);
        $list->popClip();
        $this->paintGutter($list, $text, $r, $f);
        $this->paintScrollbars($list, $r);
        if ($v->approx || $more) { $this->services?->animationFrame(); }
        $v->endFrame();
    }

    /** Works out what highlights the lines in view. */
    private function prepare(PaintFrame $f): void
    {
        $t = $this->view->text;
        $a = $t->lineStart($f->first); $z = $t->lineEnd($f->last);
        if ($this->find->open) {
            $this->find->search($this);
            $m = $this->find->matches;
            $i = Finder::lowerBound(count($m), fn(int $k) => $m[$k][1] >= $a);
            $j = Finder::lowerBound(count($m), fn(int $k) => $m[$k][0] > $z);
            $f->matches = array_slice($m, $i, $j - $i);
            if ($this->find->current < count($m)) { $f->current = $m[$this->find->current]; }
        }
        $p = $this->primary();
        if (!$p->empty() && $p->end() - $p->start() <= 200 && $t->lineOf($p->start()) === $t->lineOf($p->end())) {
            $s = $t->slice($p->start(), $p->end());
            if (trim($s) !== '') { $f->occurrence = $s; }
        }
        if ($p->empty() && count($this->sels) === 1) { $f->brackets = $this->enclosingBrackets($p->head); }
        foreach ($this->sels as $s) { $f->activeLines[$t->lineOf($s->head)] = true; }
        $f->guideLevel = -1;
        if ($this->o->indentGuides && count($this->sels) === 1) { $this->activeGuide($f, $t->lineOf($p->head)); }
    }

    /** Finds the guide of the block holding $line, which shows stronger. */
    private function activeGuide(PaintFrame $f, int $line): void
    {
        $t = $this->view->text;
        $tab = $this->tabSize();
        $in = $this->lineIndent($line);
        if ($in <= 0) { return; }
        $level = intdiv($in - 1, $tab);
        $from = $line; $to = $line;
        while ($from > 0 && $line - $from < 2000) {
            if ($this->lineIndent($from - 1) <= $level * $tab) { break; }
            $from--;
        }
        while ($to + 1 < $t->lineCount() && $to - $line < 2000) {
            if ($this->lineIndent($to + 1) <= $level * $tab) { break; }
            $to++;
        }
        $f->guideLevel = $level; $f->guideFrom = $from; $f->guideTo = $to;
    }

    /** The columns of a line's indentation, a blank line taking the larger of the lines around it. */
    private function lineIndent(int $line): int
    {
        $t = $this->view->text;
        $tab = $this->tabSize();
        $s = $t->line($line);
        if (trim($s) !== '') { return Chars::indentWidth($s, $tab); }
        $before = 0; $after = 0;
        for ($i = $line - 1; $i >= 0 && $line - $i < 50; $i--) {
            if (trim($s = $t->line($i)) !== '') { $before = Chars::indentWidth($s, $tab); break; }
        }
        for ($i = $line + 1; $i < $t->lineCount() && $i - $line < 50; $i++) {
            if (trim($s = $t->line($i)) !== '') { $after = Chars::indentWidth($s, $tab); break; }
        }
        return max($before, $after);
    }

    /** A line of $s alone, laid out as one row of its width, to paint text that is no line of the buffer (a placeholder, a number). */
    public function lone(string $s): array
    {
        $l = new LineLayout(0, 0, $s);
        $l->disp = $s;
        $w = $this->tp->measure($s, $this->view->size, $this->view->style)[0];
        $l->rows = [['from' => 0, 'to' => strlen($s), 'indent' => 0.0, 'width' => $w]];
        $l->width = $w;
        return [$l, $w];
    }

    /** Paints the lines in view and their blocks. */
    private function paintLines(DisplayList $list, array $r, PaintFrame $f): void
    {
        $v = $this->view;
        $h = $v->heights;
        $clip = $list->visible();
        if ($v->text->len() === 0 && $this->o->placeholder !== '') {
            [$l] = $this->lone($this->o->placeholder);
            $muted = $this->theme->c['lineNumber'];
            $v->paintRow($list, $l, $l->rows[0], $r[0] + $this->textX - $this->scrollX, $r[1] - $this->scrollY, [[], []], fn() => $muted, $clip[0], $clip[2]);
        }
        for ($line = $f->first; $line <= $f->last; $line++) {
            $g = $h->geometry($line);
            if (!$g->hidden) { $this->paintLine($list, $r, $f, $line, $g); }
        }
    }

    /** Paints the blocks of the lines in view, across the gutter and the text. */
    private function paintBlocks(DisplayList $list, TextPainter $text, array $r, PaintFrame $f): void
    {
        if ($this->o->decor === null || $this->view->heights->blocks === []) { return; }
        $this->eachBlock($f->first, $f->last, fn(Block $b, float $top) => $this->paintBlock($list, $text, $r, $b, $top));
    }

    /** Calls $fn with the blocks of the lines in view, as the last frame laid them out, and their tops in the content. */
    public function blocksInView(\Closure $fn): void
    {
        $h = $this->view->heights;
        if ($h->blocks === [] || $h->n === 0) { return; }
        [$y0, $y1] = $this->o->fitContent ? [0.0, $h->total()] : [$this->scrollY, $this->scrollY + $this->viewH];
        $this->eachBlock($h->lineAt($y0), min($h->lineAt($y1), $h->n - 1), $fn);
    }

    /** Calls $fn with the blocks of lines $first to $last, and their tops in the content. */
    public function eachBlock(int $first, int $last, \Closure $fn): void
    {
        $h = $this->view->heights;
        for ($line = $first; $line <= $last; $line++) {
            $g = $h->geometry($line);
            [$above, $below] = $h->blocksOf($line);
            $top = $g->top;
            foreach ($above as $b) { $fn($b, $top); $top += $b->height; }
            $top = $g->below;
            foreach ($below as $b) { $fn($b, $top); $top += $b->height; }
        }
    }

    private function paintBlock(DisplayList $list, TextPainter $text, array $r, Block $b, float $top): void
    {
        $br = [$r[0], $r[1] + $top - $this->scrollY, $r[2], $b->height];
        $vis = $list->visible();
        if ($br[1] > $vis[1] + $vis[3] || $br[1] + $br[3] < $vis[1]) { return; }
        $this->o->decor->paintBlock($list, $text, $this, $b, $br);
    }

    /** Paints the rows of a line. */
    private function paintLine(DisplayList $list, array $r, PaintFrame $f, int $line, LineGeometry $g): void
    {
        $v = $this->view;
        $ct = $this->theme;
        $l = $v->line($line);
        $lh = $v->lineHeight;
        $ox = $r[0] + $this->textX - $this->scrollX;
        $textX = $r[0] + $this->gutterW; $textW = $r[2] - $this->gutterW;
        $vis = $list->visible();
        $style = $this->o->decor?->lineStyle($line) ?? new LineLook;
        $active = isset($f->activeLines[$line]) && $this->o->activeLine && $this->o->decor === null;
        $end = $l->start + strlen($l->text);
        $lineSelected = false;
        foreach ($this->sels as $s) {
            if (!$s->empty() && $s->start() <= $end && $s->end() >= $l->start) { $lineSelected = true; }
        }
        $f->colors = $v->colorsOf($l);
        foreach ($l->rows as $ri => $row) {
            $y = $r[1] + $g->rows - $this->scrollY + $ri * $lh;
            if ($y > $vis[1] + $vis[3] || $y + $lh < $vis[1]) { continue; }
            // Backgrounds: the line's, the app's, then the caret's line.
            self::fill($list, $textX, $y, $textW, $lh, $style->bg);
            foreach ($this->o->backgrounds as $b) {
                if ($line >= $b->first && $line <= $b->last) { self::fill($list, $textX, $y, $textW, $lh, $b->color); }
            }
            if ($active && !$lineSelected) { self::fill($list, $textX, $y, $textW, $lh, $ct->c['activeLine']); }
            // Words a diff changed.
            if ($this->o->decor !== null) {
                foreach ($this->o->decor->words($line) as [$wa, $wb]) {
                    $this->fillRange($list, $l, $row, $ox, $y, $l->toDisp($wa, false), $l->toDisp($wb, false), $style->word($ct), 3);
                }
            }
            // Matches of the find bar, and occurrences of the selection.
            foreach ($f->matches as $m) {
                if ($m[1] < $l->start || $m[0] > $end) { continue; }
                $c = $m === $f->current ? $ct->c['currentMatch'] : $ct->c['match'];
                $a = max($m[0] - $l->start, 0); $b = min($m[1] - $l->start, strlen($l->text));
                $this->fillRange($list, $l, $row, $ox, $y, $l->toDisp($a, false), $l->toDisp($b, false), $c, 2);
            }
            if ($f->occurrence !== '') {
                for ($at = 0;;) {
                    $i = strpos($l->text, $f->occurrence, $at);
                    if ($i === false) { break; }
                    $a = $i;
                    $at = $a + strlen($f->occurrence);
                    if (self::isWholeWord($l->text, $a, $at, '') || !View::isWordRune(mb_substr($f->occurrence, 0, 1, 'UTF-8'))) {
                        $this->fillRange($list, $l, $row, $ox, $y, $l->toDisp($a, false), $l->toDisp($at, false), $ct->c['occurrence'], 2);
                    }
                }
            }
            // The selections.
            $selColor = $this->focused ? $ct->c['selection'] : $ct->c['inactiveSelection'];
            foreach ($this->sels as $s) {
                if ($s->empty() || $s->end() < $l->start || $s->start() > $end) { continue; }
                $a = max($s->start() - $l->start, 0); $b = min($s->end() - $l->start, strlen($l->text));
                $this->fillRange($list, $l, $row, $ox, $y, $l->toDisp($a, false), $l->toDisp($b, true), $selColor, 0);
                // Past the end of the line, the newline.
                if ($s->end() > $end && $ri === count($l->rows) - 1) {
                    self::fill($list, $ox + $row['indent'] + $row['width'], $y, $v->charWidth * 0.6, $lh, $selColor);
                }
            }
            // Brackets around the caret.
            if ($f->brackets !== null) {
                foreach ($f->brackets as $b) {
                    if ($b->start >= $l->start && $b->start < $end) {
                        foreach ($v->rangeRects($l, $row, $l->toDisp($b->start - $l->start, false), $l->toDisp(min($b->end, $end) - $l->start, false)) as [$rx, $rw]) {
                            $c = $ct->c['matchingBracket'];
                            if ($c[1] > 0) { $list->ring($ox + $rx, $y + 1, $rw, $lh - 2, $c[0], $c[1], 2, 1); }
                        }
                    }
                }
            }
            if ($ri === 0 && $this->o->indentGuides) { $this->paintGuides($list, $f, $line, $ox, $y, count($l->rows)); }
            if ($ri === 0 && $this->o->signs && $style->sign !== '' && ($ds = $this->o->decor) instanceof DiffSideView) {
                $ds->paintSign($list, $this, $style->sign, $r[0] + $this->textX - $v->charWidth * 1.5, $y, $style->number);
            }
            $v->paintRow($list, $l, $row, $ox, $y, $f->colors, null, $vis[0], $vis[2]);
            $this->paintWhitespace($list, $l, $row, $ox, $y);
            // Diagnostics under the text.
            foreach ($this->diags as $d) {
                if ($d->end < $l->start || $d->start > $end) { continue; }
                $a = max($d->start - $l->start, 0); $b = min(max($d->end, $d->start + 1) - $l->start, strlen($l->text));
                $rects = $v->rangeRects($l, $row, $l->toDisp($a, false), $l->toDisp(max($b, $a), false));
                if ($a === $b && strlen($l->text) === 0) { $rects[] = [$row['indent'], $v->charWidth]; }
                foreach ($rects as [$rx, $rw]) { self::squiggle($list, $ox + $rx, $y + $lh - 2.5, $rw, $ct->severityColor($d->severity)); }
            }
            if ($ri === count($l->rows) - 1 && $this->isFolded($line)) {
                $x = $ox + $row['indent'] + $row['width'] + 4;
                $w = $v->charWidth * 3 + 8;
                self::fill($list, $x, $y + 3, $w, $lh - 6, $ct->c['occurrence'], 4);
                [$ph, $pw] = $this->lone('⋯');
                $muted = $ct->c['lineNumber'];
                $v->paintRow($list, $ph, $ph->rows[0], $x + ($w - $pw) / 2, $y, [[], []], fn() => $muted, $vis[0], $vis[2]);
            }
            // The carets.
            if ($f->caretsOn) {
                foreach ($this->sels as $s) {
                    if ($s->head < $l->start || $s->head > $end) { continue; }
                    $d = $l->toDisp($s->head - $l->start, true);
                    if ($l->rowOf($d, $s->head > $s->anchor) !== $ri) { continue; }
                    $x = $ox + $v->xOf($l, $row, $d);
                    $cur = $ct->c['cursor'];
                    switch ($this->o->cursorShape) {
                        case CursorShape::CursorBlock:
                            $cw = $this->caretWidth($l, $row, $s->head, $x - $ox);
                            if ($this->focused) { self::fill($list, $x, $y, $cw, $lh, [$cur[0], $cur[1] * 0.4]); } else { $list->ring($x, $y, $cw, $lh, $cur[0], $cur[1], 0, 1); }
                            break;
                        case CursorShape::CursorUnderline:
                            self::fill($list, $x, $y + $lh - 2, $this->caretWidth($l, $row, $s->head, $x - $ox), 2, $cur);
                            break;
                        default:
                            self::fill($list, $x - 1, $y, 2, $lh, $cur);
                    }
                }
            }
        }
    }

    /** The width of a block or underline caret at offset $at, $x in $row: that of the character after it, with its combining marks, or of a space at the end of the line or of the row. */
    private function caretWidth(LineLayout $l, array $row, int $at, float $x): float
    {
        $v = $this->view;
        $end = min(Chars::graphemeRight($v->text, $at), $l->start + strlen($l->text));
        if ($end <= $at) { return $v->charWidth; }
        $d = $l->toDisp($end - $l->start, false);
        if ($d > $row['to']) { return $v->charWidth; }
        $w = $v->xOf($l, $row, $d) - $x;
        return $w > 0 ? $w : $v->charWidth;
    }

    /** Fills displayed bytes $a to $b of a row. */
    private function fillRange(DisplayList $list, LineLayout $l, array $row, float $ox, float $y, int $a, int $b, array $c, float $radius): void
    {
        if ($b <= $a) { return; }
        foreach ($this->view->rangeRects($l, $row, $a, $b) as [$rx, $rw]) {
            self::fill($list, $ox + $rx, $y, $rw, $this->view->lineHeight, $c, $radius);
        }
    }

    /** Draws the indent guides of a line, down its rows. */
    private function paintGuides(DisplayList $list, PaintFrame $f, int $line, float $ox, float $y, int $rows): void
    {
        $v = $this->view;
        $ct = $this->theme;
        $tab = $this->tabSize();
        $in = $this->lineIndent($line);
        $h = $v->lineHeight * $rows;
        for ($level = 0; $level * $tab < $in; $level++) {
            $x = round($ox + $level * $tab * $v->charWidth);
            $c = $level === $f->guideLevel && $line >= $f->guideFrom && $line <= $f->guideTo ? $ct->c['activeIndentGuide'] : $ct->c['indentGuide'];
            self::fill($list, $x, $y, 1, $h, $c);
        }
        foreach ($this->o->wrapGuides as $col) {
            self::fill($list, round($ox + $col * $v->charWidth), $y, 1, $h, $ct->c['wrapGuide']);
        }
    }

    /** Draws dots for spaces and arrows for tabs where the editor shows whitespace. */
    private function paintWhitespace(DisplayList $list, LineLayout $l, array $row, float $ox, float $y): void
    {
        $mode = $this->o->whitespace;
        if ($mode === Whitespace::WhitespaceNone) { return; }
        $v = $this->view;
        $ws = $this->theme->c['whitespace'];
        $s = $l->text;
        // The row's text alone, and in Selection mode only rows a selection meets.
        $a = $l->fromDisp($row['from']); $b = $l->fromDisp($row['to']);
        if ($mode === Whitespace::WhitespaceSelection) {
            $meets = false;
            foreach ($this->sels as $sel) { if (!$sel->empty() && $sel->start() < $l->start + $b && $sel->end() > $l->start + $a) { $meets = true; break; } }
            if (!$meets) { return; }
        }
        $trailFrom = 0;
        if ($mode === Whitespace::WhitespaceTrailing) {
            $trailFrom = strlen(rtrim($s, " \t"));
            $a = max($a, $trailFrom);
        }
        for ($i = $a; $i < $b; $i++) {
            $c = $s[$i];
            if ($c !== ' ' && $c !== "\t") { continue; }
            $show = false;
            switch ($mode) {
                case Whitespace::WhitespaceAll: $show = true; break;
                case Whitespace::WhitespaceTrailing: $show = $i >= $trailFrom; break;
                case Whitespace::WhitespaceBoundary:
                    $single = $c === ' ' && $i > 0 && $i + 1 < strlen($s) && $s[$i - 1] !== ' ' && $s[$i - 1] !== "\t" && $s[$i + 1] !== ' ' && $s[$i + 1] !== "\t";
                    $show = !$single;
                    break;
                case Whitespace::WhitespaceSelection:
                    foreach ($this->sels as $sel) {
                        if ($l->start + $i >= $sel->start() && $l->start + $i < $sel->end()) { $show = true; break; }
                    }
                    break;
            }
            if (!$show) { continue; }
            $d = $l->toDisp($i, false);
            if ($d < $row['from'] || $d >= $row['to']) { continue; }
            $x0 = $ox + $v->xOf($l, $row, $d);
            $x1 = $ox + $v->xOf($l, $row, $l->toDisp($i + 1, false));
            $cy = $y + $v->lineHeight / 2;
            if ($c === ' ') {
                $dot = 2.0;
                self::fill($list, ($x0 + $x1) / 2 - $dot / 2, $cy - $dot / 2, $dot, $dot, $ws, $dot / 2);
                continue;
            }
            $w = min($x1 - $x0 - 4, $v->charWidth * 2);
            $path = (new Path)->moveTo($x0 + 2, $cy)->lineTo($x0 + 2 + $w, $cy)
                ->moveTo($x0 + 2 + $w - 3, $cy - 3)->lineTo($x0 + 2 + $w, $cy)->lineTo($x0 + 2 + $w - 3, $cy + 3);
            $list->path($path, $ws[0], $ws[1], 1.0);
        }
    }

    /** Draws a wavy line under $w points from $x, $y. */
    private static function squiggle(DisplayList $list, float $x, float $y, float $w, array $c): void
    {
        if ($w <= 0) { return; }
        $wave = 4.0; $amp = 1.25;
        $path = (new Path)->moveTo($x, $y);
        for ($i = 0.0; $i < $w; $i += $wave / 2) {
            $dy = ((int)($i / ($wave / 2))) % 2 === 1 ? -$amp : $amp;
            $path->lineTo(min($x + $i + $wave / 2, $x + $w), $y + $dy);
        }
        $list->path($path, $c[0], $c[1], 1.0);
    }

    /** Draws the gutter: the numbers of lines, fold markers, the marks of changes and the app's. */
    private function paintGutter(DisplayList $list, TextPainter $text, array $r, PaintFrame $f): void
    {
        if ($this->gutterW <= 0) { return; }
        $v = $this->view;
        $ct = $this->theme;
        $h = $v->heights;
        $lh = $v->lineHeight;
        [$rx, $ry, , $rh] = $r;
        $numbersOn = $this->numbersOn();
        $cur = $v->text->lineOf($this->primary()->head);
        $list->pushClip($rx, $ry, $this->gutterW, $rh);
        $vis = $list->visible();
        $numRight = $rx + $this->gutterW - 4 - $v->charWidth * 0.5;
        if ($this->o->folding && $this->o->decor === null) { $numRight -= $lh * 0.8; }
        if ($this->gitDiff !== null) { $numRight -= 6; }
        for ($line = $f->first; $line <= $f->last; $line++) {
            $g = $h->geometry($line);
            if ($g->hidden) { continue; }
            $y = $ry + $g->rows - $this->scrollY;
            $rowsH = $g->below - $g->rows;
            $style = new LineLook;
            if ($this->o->decor !== null) {
                $style = $this->o->decor->lineStyle($line);
                self::fill($list, $rx, $y, $this->gutterW, $rowsH, $style->gutter);
            }
            $active = isset($f->activeLines[$line]);
            if ($numbersOn) {
                $n = $this->o->decor !== null ? $this->o->decor->number($line) : $line + 1;
                if ($this->o->lineNumbers === LineNumbers::LineNumbersRelative && $line !== $cur) { $n = abs($line - $cur); }
                if ($n >= 0) {
                    [$ll, $lw] = $this->lone((string)$n);
                    $col = $active && $this->o->decor === null ? $ct->c['activeLineNumber'] : $ct->c['lineNumber'];
                    if ($style->number[1] > 0) { $col = $style->number; }
                    $v->paintRow($list, $ll, $ll->rows[0], $numRight - $lw, $y, [[], []], fn() => $col, $vis[0], $vis[2]);
                }
            }
            foreach ($this->o->gutterMarks as $m) {
                if ($m->line === $line) {
                    $d = $lh * 0.45;
                    self::fill($list, $rx + 3, $y + ($lh - $d) / 2, $d, $d, $m->color, $d / 2);
                }
            }
            // Change bars.
            $barX = $rx + $this->gutterW - 4 - 3;
            if ($this->o->folding && $this->o->decor === null) { $barX -= $lh * 0.8; }
            if ($this->gitDiff !== null) {
                switch ($this->gitDiff->status($line)) {
                    case 1: self::fill($list, $barX, $y, 3, $rowsH, $ct->c['added']); break;
                    case 2: self::fill($list, $barX, $y, 3, $rowsH, $ct->c['modified']); break;
                    case 3:
                        $path = (new Path)->moveTo($barX - 1, $y - 3)->lineTo($barX + 5, $y)->lineTo($barX - 1, $y + 3)->close();
                        $list->path($path, $ct->c['deleted'][0], $ct->c['deleted'][1]);
                        break;
                }
            }
            if ($style->bar[1] > 0) { $this->paintBar($list, $rx, $y, 4, $rowsH, $style); }
            // Fold markers: always on folded lines, on the others while the pointer is over the gutter.
            if ($this->o->folding && $this->o->decor === null) {
                $folded = $this->isFolded($line);
                $hovered = $this->pointerIn && $this->pointer[0] < $this->gutterW;
                if ($folded || $hovered && $this->foldable($line)) {
                    $cx = $rx + $this->gutterW - 4 - $lh * 0.4; $cy = $y + $lh / 2;
                    $d = $lh * 0.16;
                    $path = $folded
                        ? (new Path)->moveTo($cx - $d / 2, $cy - $d)->lineTo($cx + $d / 2, $cy)->lineTo($cx - $d / 2, $cy + $d)
                        : (new Path)->moveTo($cx - $d, $cy - $d / 2)->lineTo($cx, $cy + $d / 2)->lineTo($cx + $d, $cy - $d / 2);
                    $col = $line === $this->hoverLine ? $ct->c['activeLineNumber'] : $ct->c['lineNumber'];
                    $list->path($path, $col[0], $col[1], 1.5);
                }
            }
        }
        $list->popClip();
    }

    /** Draws a diff's change bar: solid for additions, striped for deletions, as Pierre's, so the two differ without their colors. */
    public function paintBar(DisplayList $list, float $x, float $y, float $w, float $h, LineLook $s): void
    {
        if (!$s->stripes) {
            self::fill($list, $x, $y, $w, $h, $s->bar);
            return;
        }
        $list->pushClip($x, $y, $w, $h);
        for ($yy = $y; $yy < $y + $h; $yy += 4) { self::fill($list, $x, $yy, $w, 2, $s->bar); }
        $list->popClip();
    }

    /** Draws the scroll bars, the vertical one with markers of the matches, diagnostics, changes and carets. */
    private function paintScrollbars(DisplayList $list, array $r): void
    {
        $v = $this->view;
        $ct = $this->theme;
        $t = $this->uiTheme;
        [$rx, $ry, $rw, $rh] = $r;
        [$vertical, $horizontal] = $this->scrollbars();
        $sw = self::SCROLLBAR_WIDTH;
        if ($vertical) {
            $x = $rx + $rw - $sw;
            $hovered = $this->pointerIn && $this->region === self::REGION_VSCROLL;
            if ($hovered || $this->scrollbar['active']) { self::fill($list, $x, $ry, $sw, $rh, [$t->scrollbar, $t->scrollbarAlpha * 0.15]); }
            $span = $this->scrollMax() + $this->viewH;
            $mark = function (float $y, float $h, float $x0, float $w, array $c) use ($list, $x, $ry, $rh, $span) {
                self::fill($list, $x + $x0, $ry + $y / $span * $rh, $w, max($h / $span * $rh, 2.0), $c);
            };
            $h = $v->heights;
            // Changes against the base, or of the diff, on the left.
            [$d, $old] = $this->diffModel();
            if ($d !== null) {
                foreach ($d->hunks as $i => $hk) {
                    if ($i > 2000) { break; }
                    [$from, $to] = $old ? [$hk->a0, $hk->a1] : [$hk->b0, $hk->b1];
                    $c = $ct->c['modified'];
                    if ($hk->a0 === $hk->a1) { $c = $ct->c['added']; } elseif ($hk->b0 === $hk->b1) { $c = $ct->c['deleted']; }
                    if ($old) {
                        $c = $ct->c['deleted'];
                        if ($hk->a0 === $hk->a1) { continue; }
                    } elseif ($hk->b0 === $hk->b1 && $this->o->decor !== null) {
                        continue;
                    }
                    $top = $h->top($from);
                    $mark($top, $h->top(max($to, $from + 1)) - $top, 0, 3, $c);
                }
            }
            // Matches in the middle.
            if ($this->find->open) {
                $last = -10.0;
                foreach ($this->find->matches as $i => $m) {
                    if ($i > 20000) { break; }
                    $y = $h->top($v->text->lineOf($m[0]));
                    $yy = $y / $span * $rh;
                    if ($yy - $last < 2) { continue; }
                    $last = $yy;
                    $mark($y, $v->lineHeight, 3, 4, $ct->c['currentMatch']);
                }
            }
            // Diagnostics on the right.
            foreach ($this->diags as $i => $dg) {
                if ($i > 2000) { break; }
                $mark($h->top($v->text->lineOf($dg->start)), $v->lineHeight, 7, 3, $ct->severityColor($dg->severity));
            }
            // Carets.
            if (count($this->sels) <= 100) {
                $tc = $ct->c['text'];
                foreach ($this->sels as $s) { $mark($h->top($v->text->lineOf($s->head)), 2, 0, $sw, [$tc[0], $tc[1] * 0.5]); }
            }
            [$t0, $t1] = $this->thumb(false);
            $alpha = $hovered || $this->scrollbar['active'] ? 0.9 : 0.5;
            self::fill($list, $x + 2, $ry + $t0, $sw - 4, $t1 - $t0, [$t->scrollbar, $t->scrollbarAlpha * $alpha], ($sw - 4) / 2);
        }
        if ($horizontal) {
            [$t0, $t1] = $this->thumb(true);
            $y = $ry + $rh - $sw;
            $alpha = $this->pointerIn && $this->region === self::REGION_HSCROLL || $this->scrollbar['active'] ? 0.9 : 0.4;
            self::fill($list, $rx + $this->textX + $t0, $y + 2, $t1 - $t0, $sw - 4, [$t->scrollbar, $t->scrollbarAlpha * $alpha], ($sw - 4) / 2);
        }
    }
}
