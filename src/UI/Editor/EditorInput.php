<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

use MyPHP\UI\{InputEvent, Key, TextInputRange, TextInputSelection, TextState};

/** The editor's input as it comes, and the editor as the system's input methods see it (mygo's input.go). */
trait EditorInput
{
    /** Takes the editor's input as it comes. */
    public function input(InputEvent $ev): bool
    {
        // The key after the first of a chord is the chord's, as VS Code's keybindings resolve chords before an extension's type command.
        $chord = $this->chord !== null && $ev->kind === InputEvent::KEY_DOWN;
        if (!$chord && $this->offer($ev)) { return true; }
        return $this->retry(fn() => $this->dispatch($ev));
    }

    /**
     * Gives the app's onInput an event before the editor, once, and
     * reports whether it took it. It is not retried with the editor's own
     * handling: what the app does with an event it leaves, as edit the
     * buffer, would happen twice.
     */
    private function offer(InputEvent $ev): bool
    {
        return $this->o->onInput !== null && ($this->o->onInput)($ev);
    }

    /** Runs $fn, which edits the text as the editor shows it, again on the text as it is when the buffer was edited meanwhile, rather than leaving the edit out (apply). */
    public function retry(\Closure $fn): bool
    {
        for ($i = 0; $i < 4; $i++) {
            $this->sync();
            $this->stale = false;
            $ok = $fn();
            if (!$this->stale) { return $ok; }
        }
        return false;
    }

    private function dispatch(InputEvent $ev): bool
    {
        $this->frameNow = hrtime(true) / 1e9;
        switch ($ev->kind) {
            case InputEvent::KEY_DOWN:
                return $this->key($ev);
            case InputEvent::COMMAND:
                $cmd = ['copy' => Command::COPY, 'cut' => Command::CUT, 'paste' => Command::PASTE, 'pasteAndMatchStyle' => Command::PASTE, 'selectAll' => Command::SELECT_ALL,
                    'undo' => Command::UNDO, 'redo' => Command::REDO, 'delete' => Command::BACKSPACE][$ev->text] ?? Command::NO_COMMAND;
                if ($cmd !== Command::NO_COMMAND) {
                    $this->run($cmd);
                    return true;
                }
                return false;
            case InputEvent::TEXT:
                if (!$this->o->readOnly) {
                    $this->insert($ev->text);
                    return true;
                }
                return false;
            case InputEvent::POINTER_DOWN:
                return $this->pointerDown($ev);
            case InputEvent::POINTER_MOVE:
                return $this->pointerMove($ev);
            case InputEvent::POINTER_UP:
                if ($this->gesture->active || $this->scrollbar['active']) {
                    $this->gesture->active = false;
                    $this->scrollbar['active'] = false;
                    $this->endGroup();
                    return true;
                }
                return false;
            case InputEvent::SCROLL:
                return $this->wheel($ev);
        }
        return false;
    }

    /** Runs the command bound to a key. */
    private function key(InputEvent $ev): bool
    {
        $km = new Keymap($this->o->bindings);
        $mods = $ev->mods;
        $chord = $this->chord;
        $this->chord = null;
        [$b, $prefix] = $km->lookup($chord, $mods, $ev->key);
        if ($chord !== null) {
            // The second key of a chord, bound or not, is the chord's.
            if ($b !== null && $b->command !== Command::NO_COMMAND) { $this->run($b->command); }
            return true;
        }
        if ($prefix && $b === null) {
            $this->chord = new Binding($mods, $ev->key, 0, null, Command::NO_COMMAND);
            return true;
        }
        if ($b === null || $b->command === Command::NO_COMMAND) { return false; }
        if ($this->o->readOnly && self::commandEdits($b->command)) { return false; }
        $did = $this->run($b->command);
        return match ($b->command) {
            // Leave Escape, Tab and F12 to the window when they did nothing.
            Command::CANCEL, Command::TAB, Command::BACKTAB, Command::GO_TO_DEFINITION => $did,
            default => true,
        };
    }

    /** Whether a command edits the text. */
    public static function commandEdits(int $c): bool
    {
        return in_array($c, [Command::UNDO, Command::REDO, Command::CUT, Command::PASTE, Command::BACKSPACE, Command::DELETE, Command::DELETE_WORD_LEFT, Command::DELETE_WORD_RIGHT,
            Command::DELETE_SUBWORD_LEFT, Command::DELETE_SUBWORD_RIGHT, Command::DELETE_TO_LINE_START, Command::DELETE_TO_LINE_END, Command::CUT_TO_LINE_END, Command::DELETE_LINE,
            Command::NEWLINE, Command::NEWLINE_ABOVE, Command::NEWLINE_BELOW, Command::MOVE_LINES_UP, Command::MOVE_LINES_DOWN, Command::DUPLICATE_LINES_UP,
            Command::DUPLICATE_LINES_DOWN, Command::DUPLICATE_SELECTION, Command::JOIN_LINES, Command::TRANSPOSE, Command::INDENT, Command::OUTDENT, Command::TAB,
            Command::BACKTAB, Command::TOGGLE_COMMENT, Command::TOGGLE_BLOCK_COMMENT, Command::SORT_LINES, Command::SORT_LINES_CASE_INSENSITIVE, Command::REVERSE_LINES,
            Command::UNIQUE_LINES, Command::UPPERCASE, Command::LOWERCASE, Command::TITLE_CASE, Command::SNAKE_CASE, Command::KEBAB_CASE, Command::CAMEL_CASE,
            Command::PASCAL_CASE, Command::TRIM_TRAILING_WHITESPACE, Command::REVERT_HUNK], true);
    }

    /** A point of the element in the content. @return array{0: float, 1: float} */
    public function toContent(float $x, float $y): array
    {
        return [$x - $this->textX + $this->scrollX, $y + $this->scrollY];
    }

    /** What part of the editor a point of it is over. */
    public function regionAt(float $x, float $y): int
    {
        [$vertical, $horizontal] = $this->scrollbars();
        if ($vertical && $x >= $this->r[2] - self::SCROLLBAR_WIDTH) { return self::REGION_VSCROLL; }
        if ($horizontal && $y >= $this->hbarY && $y < $this->hbarY + self::SCROLLBAR_WIDTH) { return self::REGION_HSCROLL; }
        if ($x < $this->gutterW) { return self::REGION_GUTTER; }
        return self::REGION_TEXT;
    }

    private function pointerDown(InputEvent $ev): bool
    {
        $this->pointer = [$ev->x, $ev->y]; $this->pointerIn = true;
        $this->region = $this->regionAt($ev->x, $ev->y);
        $this->hover->close();
        if ($ev->button !== 0) {
            // The secondary button keeps a selection under it for the menu.
            if ($ev->button === 1) {
                [$cx, $cy] = $this->toContent($ev->x, $ev->y);
                $off = $this->offsetAt($cx, $cy);
                foreach ($this->sels as $s) { if ($off >= $s->start() && $off <= $s->end()) { return false; } }
                $this->setSels([$this->newSel($off, $off)], self::REVEAL_NONE);
            }
            return false;
        }
        switch ($this->region) {
            case self::REGION_VSCROLL: return $this->scrollbarPress($ev, false);
            case self::REGION_HSCROLL: return $this->scrollbarPress($ev, true);
            case self::REGION_GUTTER: return $this->gutterPress($ev);
        }
        [$cx, $cy] = $this->toContent($ev->x, $ev->y);
        // A block's own input, as a diff's buttons.
        if (($blk = $this->blockAt($cy)) !== null && $this->o->decor !== null) {
            if ($this->o->decor->blockInput($this, $blk[0], $ev, $blk[1])) { return true; }
        }
        // The fold placeholder unfolds.
        if (($line = $this->foldPlaceholderAt($cx, $cy)) !== null) {
            $this->toggleFoldLine($line);
            return true;
        }
        $off = $this->offsetAt($cx, $cy);
        $mods = $ev->mods;
        $this->endGroup();
        $this->wholeWord = false;
        $this->gesture = new Gesture(true, Gesture::CHARS, $this->newSel($off, $off));
        if ($mods & Key::OPTION && $mods & Key::SHIFT && $ev->clicks === 1) {
            $this->gesture->mode = Gesture::COLUMNS;
            $this->gesture->colStart = [$cx, $cy];
            $this->columnSelect($cx, $cy);
            return true;
        }
        if ($mods === Key::COMMAND && $this->o->onDefinition !== null && $ev->clicks === 1) {
            $this->gesture->active = false;
            $this->setSels([$this->newSel($off, $off)], self::REVEAL_NONE);
            ($this->o->onDefinition)($off);
            return true;
        }
        if ($mods & Key::OPTION) {
            $this->gesture->add = true;
            $this->gesture->others = self::cloneSels($this->sels);
            // Alt-clicking a caret takes it away.
            if ($ev->clicks === 1 && count($this->sels) > 1) {
                foreach ($this->sels as $i => $s) {
                    if ($s->empty() && $s->head === $off) {
                        $sels = self::cloneSels($this->sels);
                        array_splice($sels, $i, 1);
                        $this->sels = $sels;
                        $this->gesture->active = false;
                        return true;
                    }
                }
            }
        } elseif ($mods & Key::SHIFT && $ev->clicks === 1) {
            $p = $this->primary();
            $this->gesture->origin = new Sel($p->anchor, $p->anchor, -1.0, $p->id);
            $p->head = $off; $p->goal = -1.0;
            $this->setSels([$p], self::REVEAL_NONE);
            return true;
        }
        if ($ev->clicks === 2) {
            $this->gesture->mode = Gesture::WORDS;
            [$this->gesture->origin->anchor, $this->gesture->origin->head] = Chars::wordAt($this->view->text, $off, $this->wordChars());
        } elseif ($ev->clicks >= 3) {
            $this->gesture->mode = Gesture::LINES;
            $r = $this->view->text;
            $line = $r->lineOf($off);
            $this->gesture->origin->anchor = $r->lineStart($line);
            $this->gesture->origin->head = $line + 1 >= $r->lineCount() ? $r->len() : min($r->len(), $r->lineStart($line + 1));
        }
        $this->extendGesture($off);
        return true;
    }

    /** Extends the selection being dragged to $off. */
    public function extendGesture(int $off): void
    {
        $g = $this->gesture;
        $o = $g->origin;
        $s = new Sel(0, 0, -1.0, $o->id);
        $r = $this->view->text;
        switch ($g->mode) {
            case Gesture::CHARS:
                $s->anchor = $o->anchor; $s->head = $off;
                break;
            case Gesture::WORDS:
                [$a, $z] = Chars::wordAt($r, $off, $this->wordChars());
                if ($off < $o->start()) { $s->anchor = $o->end(); $s->head = $a; } else { $s->anchor = $o->start(); $s->head = max($z, $o->end()); }
                break;
            case Gesture::LINES:
                $line = $r->lineOf($off);
                if ($off < $o->start()) {
                    $s->anchor = $o->end(); $s->head = $r->lineStart($line);
                } else {
                    $end = $line + 1 >= $r->lineCount() ? $r->len() : min($r->len(), $r->lineStart($line + 1));
                    $s->anchor = $o->start(); $s->head = max($end, $o->end());
                }
                break;
        }
        $sels = $g->add ? [...self::cloneSels($g->others), $s] : [$s];
        $this->sels = Sel::merge($sels);
        $this->blinkStart = hrtime(true) / 1e9;
    }

    /**
     * Selects the columns from where the press began to $x, $y on each line
     * between, as Zed's columnar selection: lines too short for the columns
     * get no selection, but for the line of the press.
     */
    public function columnSelect(float $x, float $y): void
    {
        $v = $this->view;
        [$x0, $y0] = $this->gesture->colStart;
        $l0 = $v->heights->lineAt($y0); $l1 = $v->heights->lineAt($y);
        $step = $l1 < $l0 ? -1 : 1;
        $sels = [];
        for ($line = $l0;; $line += $step) {
            if (!$v->heights->isHidden($line)) {
                $l = $v->line($line);
                $row = $l->rows[0];
                $a = $l->start + $l->fromDisp($v->dispAt($l, $row, $x0));
                $b = $l->start + $l->fromDisp($v->dispAt($l, $row, $x));
                // Lines ending before the columns start are left out.
                if ($v->xOf($l, $row, strlen($l->disp)) >= min($x0, $x) - $v->charWidth / 2 || $line === $l0) {
                    $this->nextId++;
                    $sels[] = new Sel($a, $b, $x, $this->nextId);
                }
            }
            if ($line === $l1) { break; }
        }
        if ($sels !== []) { $this->sels = Sel::merge($sels); }
    }

    private function pointerMove(InputEvent $ev): bool
    {
        $this->pointer = [$ev->x, $ev->y];
        $this->pointerIn = $ev->x >= 0 && $ev->y >= 0 && $ev->x < $this->r[2] && $ev->y < $this->viewH;
        if ($this->scrollbar['active']) {
            $this->dragScrollbar($ev);
            return true;
        }
        if ($this->gesture->active && $ev->button === 0) {
            [$cx, $cy] = $this->toContent($ev->x, $ev->y);
            if ($this->gesture->mode === Gesture::COLUMNS) {
                $this->columnSelect($cx, $cy);
                return true;
            }
            $this->extendGesture($this->offsetAt($cx, $cy));
            return true;
        }
        // Hovering: the region, which the cursor shows, a line's gutter, and resting on text for the hover.
        $changed = false;
        if (($r = $this->regionAt($ev->x, $ev->y)) !== $this->region) {
            $this->region = $r;
            $changed = true;
        }
        $line = -1;
        if ($this->regionAt($ev->x, $ev->y) === self::REGION_GUTTER || $this->o->decor !== null) {
            [, $cy] = $this->toContent($ev->x, $ev->y);
            $line = $this->view->heights->lineAt($cy);
        }
        if ($line !== $this->hoverLine) {
            $this->hoverLine = $line;
            $changed = true;
        }
        $h = $this->hover;
        $off = -1;
        if ($this->regionAt($ev->x, $ev->y) === self::REGION_TEXT) {
            [$cx, $cy] = $this->toContent($ev->x, $ev->y);
            $off = $this->offsetAt($cx, $cy);
            // Past the end of a line, or below the last, is over no text.
            $cr = $this->caretRect($off);
            if ($cr === null || $cy > $cr[1] + $cr[3] || $off === $this->view->text->lineEnd($this->view->text->lineOf($off)) && $cx > $cr[0] + $this->view->charWidth / 2) {
                $off = -1;
            }
        }
        if ($off < 0) {
            // Off the text, nothing rests.
            $h->pending = false; $h->restOff = -1;
        }
        // Off the text hovered, the hover goes after a grace, so that the pointer can go into it, as the build sees (hideAt).
        if ($h->shown) {
            if ($off >= 0 && $off >= $h->start && $off <= $h->end) {
                $h->hideAt = 0.0;
            } elseif ($h->hideAt == 0) {
                $h->hideAt = hrtime(true) / 1e9 + Hover::HIDE_DELAY;
                $changed = true;
            }
        }
        if ($off >= 0 && ($this->o->onHover !== null || $this->diags !== []) && $off !== $h->restOff) {
            $h->restOff = $off; $h->restAt = hrtime(true) / 1e9; $h->pending = true;
            $changed = true;
        }
        return $changed;
    }

    private function wheel(InputEvent $ev): bool
    {
        $this->hover->close();
        $moved = false;
        if ($ev->dy != 0 && !$this->o->fitContent) {
            $before = $this->scrollY;
            $this->setScrollShared($this->scrollY + $ev->dy);
            $moved = $this->scrollY !== $before;
        }
        if ($ev->dx != 0 && !$this->view->wrap) {
            $before = $this->scrollX;
            $this->scrollX = max(0.0, min($this->scrollX + $ev->dx, $this->maxScrollX()));
            $this->o->sharedScroll?->setX($this->scrollX);
            $moved = $moved || $this->scrollX !== $before;
        }
        if ($moved && $this->gesture->active) {
            [$cx, $cy] = $this->toContent($this->pointer[0], $this->pointer[1]);
            $this->extendGesture($this->offsetAt($cx, $cy));
        }
        return $moved;
    }

    /** The room the scroll bar's track takes at the right edge: none while the editor shows no scroll bars, as with fitContent (mygo's trackWidth). */
    public function trackWidth(): float
    {
        return $this->o->noScrollbar || $this->o->fitContent ? 0.0 : self::SCROLLBAR_WIDTH;
    }

    /**
     * Which scroll bars the editor shows, and takes presses on: the
     * vertical one while it scrolls, as it does past the end of a short
     * text, and never for an editor as high as its content or the old side
     * of a split diff; the horizontal one while a line runs under the
     * vertical one's track or beyond (mygo's scrollbars). @return array{0: bool, 1: bool}
     */
    public function scrollbars(): array
    {
        return [$this->trackWidth() > 0 && $this->scrollMax() > 0, !$this->view->wrap && $this->view->maxLine > $this->hTrack()];
    }

    /** The length of the horizontal scroll bar's track: the text's width, short of the vertical one's (mygo's hTrack). */
    public function hTrack(): float
    {
        return $this->r[2] - $this->textX - $this->trackWidth();
    }

    public function maxScrollX(): float
    {
        return max(0.0, $this->view->maxLine + $this->view->charWidth * 4 - ($this->r[2] - $this->textX));
    }

    /** Grabs a thumb, or jumps to where the track was pressed. */
    private function scrollbarPress(InputEvent $ev, bool $horizontal): bool
    {
        [$t0, $t1] = $this->thumb($horizontal);
        $pos = $horizontal ? $ev->x - $this->textX : $ev->y;
        $this->scrollbar = ['active' => true, 'horizontal' => $horizontal, 'grab' => 0.0];
        if ($pos >= $t0 && $pos <= $t1) {
            $this->scrollbar['grab'] = $pos - $t0;
        } else {
            $this->scrollbar['grab'] = ($t1 - $t0) / 2;
            $this->dragScrollbar($ev);
        }
        return true;
    }

    private function dragScrollbar(InputEvent $ev): void
    {
        [$t0, $t1] = $this->thumb($this->scrollbar['horizontal']);
        $size = $t1 - $t0;
        if ($this->scrollbar['horizontal']) {
            $track = $this->hTrack();
            $f = ($ev->x - $this->textX - $this->scrollbar['grab']) / max(1.0, $track - $size);
            $this->scrollX = max(0.0, min($f * $this->maxScrollX(), $this->maxScrollX()));
            $this->o->sharedScroll?->setX($this->scrollX);
            return;
        }
        $track = $this->viewH;
        $f = ($ev->y - $this->scrollbar['grab']) / max(1.0, $track - $size);
        $this->setScrollShared($f * $this->scrollMax());
    }

    /** Scrolls, through the owner when it shares the scroll among editors. */
    public function setScrollShared(float $y): void
    {
        if ($this->o->sharedScroll !== null) {
            $this->o->sharedScroll->set($y);
            return;
        }
        $this->setScroll($y);
    }

    /** Where the scroll bar's thumb is along its track: from, to. @return array{0: float, 1: float} */
    public function thumb(bool $horizontal): array
    {
        if ($horizontal) {
            $track = $this->hTrack();
            $content = $this->view->maxLine + $this->view->charWidth * 4;
            $size = max(24.0, $track * $track / max($content, $track));
            $m = $this->maxScrollX();
            $f = $m > 0 ? $this->scrollX / $m : 0.0;
            return [$f * ($track - $size), $f * ($track - $size) + $size];
        }
        $track = $this->viewH;
        $content = $this->scrollMax() + $this->viewH;
        $size = max(24.0, $track * $track / max($content, $track));
        $m = $this->scrollMax();
        $f = $m > 0 ? $this->scrollY / $m : 0.0;
        return [$f * ($track - $size), $f * ($track - $size) + $size];
    }

    /** Folds at a fold marker, selects lines at a number, or calls onGutterClick. */
    private function gutterPress(InputEvent $ev): bool
    {
        [, $cy] = $this->toContent($ev->x, $ev->y);
        $line = $this->view->heights->lineAt($cy);
        $g = $this->view->heights->geometry($line);
        if ($cy < $g->rows || $cy >= $g->below) {
            if (($blk = $this->blockAt($cy)) !== null && $this->o->decor !== null) { return $this->o->decor->blockInput($this, $blk[0], $ev, $blk[1]); }
            return false;
        }
        if ($this->o->decor !== null && $this->o->decor->blockInput($this, null, $ev, [0.0, $g->rows - $this->scrollY, $this->gutterW, $this->view->lineHeight])) { return true; }
        if ($this->o->folding && $this->o->decor === null && $ev->x >= $this->foldX()) {
            if ($this->foldable($line) || $this->isFolded($line)) {
                $this->toggleFoldLine($line);
                return true;
            }
        }
        if ($this->o->onGutterClick !== null) {
            ($this->o->onGutterClick)($line);
            return true;
        }
        // Select the line, and drag to select more.
        $r = $this->view->text;
        $off = $r->lineStart($line);
        $this->gesture = new Gesture(true, Gesture::LINES, $this->newSel($off, $off));
        $this->gesture->origin->anchor = $off;
        $this->gesture->origin->head = $line + 1 >= $r->lineCount() ? $r->len() : min($r->len(), $r->lineStart($line + 1));
        if ($ev->mods & Key::SHIFT) {
            $p = $this->primary();
            $this->gesture->origin->anchor = $p->anchor; $this->gesture->origin->head = $p->anchor;
            $this->gesture->mode = Gesture::LINES;
        }
        $this->extendGesture($off);
        return true;
    }

    /** Where the fold markers are in the gutter. */
    public function foldX(): float { return $this->gutterW - $this->view->lineHeight * 0.8 - 4; }

    /** The block at height $y of the content, and its box [x, y, w, h] in the element, or null. @return ?array{0: Block, 1: array{0: float, 1: float, 2: float, 3: float}} */
    public function blockAt(float $y): ?array
    {
        $h = $this->view->heights;
        $line = $h->lineAt($y);
        $g = $h->geometry($line);
        [$above, $below] = $h->blocksOf($line);
        $top = $g->top;
        foreach ($above as $b) {
            if ($y >= $top && $y < $top + $b->height) { return [$b, [0.0, $top - $this->scrollY, $this->r[2], $b->height]]; }
            $top += $b->height;
        }
        $top = $g->below;
        foreach ($below as $b) {
            if ($y >= $top && $y < $top + $b->height) { return [$b, [0.0, $top - $this->scrollY, $this->r[2], $b->height]]; }
            $top += $b->height;
        }
        return null;
    }

    /** The line whose fold placeholder, after its text, is at $x, $y, or null. */
    public function foldPlaceholderAt(float $x, float $y): ?int
    {
        $v = $this->view;
        $line = $v->heights->lineAt($y);
        if (!$this->isFolded($line)) { return null; }
        $l = $v->line($line);
        $last = $l->rows[count($l->rows) - 1];
        $end = $last['indent'] + $last['width'];
        $rowTop = $v->heights->geometry($line)->rows + (count($l->rows) - 1) * $v->lineHeight;
        if ($y >= $rowTop && $x >= $end + 4 && $x <= $end + 4 + $v->charWidth * 3 + 8) { return $line; }
        return null;
    }

    // The editor as the system's input methods see it: the newest selection, and a composition typed at every caret.

    public function textForRange(TextInputRange $r): array
    {
        $this->sync();
        $t = $this->view->text;
        $a = $t->fromUTF16(min($r->start, $r->end)); $z = $t->fromUTF16(max($r->start, $r->end));
        return [$t->slice($a, $z), new TextInputRange($t->utf16($a), $t->utf16($z))];
    }

    public function selection(): TextInputSelection
    {
        $this->sync();
        $t = $this->view->text;
        $p = $this->primary();
        return new TextInputSelection(new TextInputRange($t->utf16($p->start()), $t->utf16($p->end())), $p->head < $p->anchor);
    }

    public function markedRange(): ?TextInputRange
    {
        if (!$this->markedActive || $this->marked === []) { return null; }
        $t = $this->view->text;
        $m = $this->marked[min(Sel::newest($this->sels), count($this->marked) - 1)];
        return new TextInputRange($t->utf16($m[0]), $t->utf16($m[1]));
    }

    /** The ranges input replaces, in bytes: those marked, else the selections, or the one range the input method names. @return list<array{0: int, 1: int}> */
    private function targets(?TextInputRange $r): array
    {
        $t = $this->view->text;
        if ($r !== null) {
            $a = $t->fromUTF16(min($r->start, $r->end)); $z = $t->fromUTF16(max($r->start, $r->end));
            $p = $this->primary();
            $mr = $this->markedRange();
            if (!($a === $p->start() && $z === $p->end()) && !($mr !== null && $r->start === $mr->start && $r->end === $mr->end)) { return [[$a, $z]]; }
        }
        if ($this->markedActive && $this->marked !== []) { return $this->marked; }
        $out = [];
        foreach ($this->sels as $s) { $out[] = [$s->start(), $s->end()]; }
        return $out;
    }

    public function replaceText(?TextInputRange $r, string $s): void
    {
        // Text typed at the selection is input; committing a composition, or replacing other text, as the accents of press and hold replace the letter before the caret, is the input method's.
        if (!$this->markedActive && $this->atSelection($r) && $this->offer(new InputEvent(InputEvent::TEXT, text: $s))) { return; }
        $this->retry(function () use ($r, $s) { $this->replaceTextNow($r, $s); return true; });
    }

    /** Whether an input method's range is the newest selection: null, or the same range. */
    private function atSelection(?TextInputRange $r): bool
    {
        if ($r === null) { return true; }
        $this->sync();
        $t = $this->view->text;
        $p = $this->primary();
        return $t->fromUTF16(min($r->start, $r->end)) === $p->start() && $t->fromUTF16(max($r->start, $r->end)) === $p->end();
    }

    private function replaceTextNow(?TextInputRange $r, string $s): void
    {
        if ($this->o->readOnly) { return; }
        if ($r === null && !$this->markedActive) {
            $this->insert($s);
            return;
        }
        $ts = $this->targets($r);
        $this->markedActive = false; $this->marked = [];
        $this->replaceRanges($ts, $s, Transaction::COMPOSE, -1, -1);
        $this->buf()?->endGroup();
        $this->complete->close();
        $this->afterTyping($s);
    }

    public function setMarkedText(?TextInputRange $r, string $s, TextInputRange $selected): void
    {
        $this->retry(function () use ($r, $s, $selected) { $this->setMarkedTextNow($r, $s, $selected); return true; });
    }

    private function setMarkedTextNow(?TextInputRange $r, string $s, TextInputRange $selected): void
    {
        if ($this->o->readOnly) { return; }
        $ts = $this->targets($r);
        // Selected counts UTF-16 units of s.
        $sa = TextState::byteIndex($s, max(0, $selected->start)); $sz = TextState::byteIndex($s, max(0, $selected->end));
        $this->replaceRanges($ts, $s, Transaction::COMPOSE, $sa, $sz);
        $this->markedActive = $s !== '';
        $this->marked = [];
        if ($s === '') {
        } elseif (count($ts) === 1 && count($this->sels) > 1) {
            // The range named alone holds the composition.
            $p = $this->primary();
            $this->marked[] = [$p->start() - $sa, $p->start() - $sa + strlen($s)];
        } else {
            foreach ($this->sels as $sel) { $this->marked[] = [$sel->start() - $sa, $sel->start() - $sa + strlen($s)]; }
        }
    }

    /** Replaces each range with $s, selecting $sa to $sz of it, or putting the caret after it. @param list<array{0: int, 1: int}> $ts */
    private function replaceRanges(array $ts, string $s, int $kind, int $sa, int $sz): void
    {
        $edits = [];
        foreach ($ts as [$a, $b]) { $edits[] = new Edit($a, $b, $s); }
        $single = count($ts) === 1 && count($this->sels) > 1;
        $sels = self::cloneSels($this->sels);
        $newest = $this->primary()->id;
        $this->apply($edits, $kind, function (\Closure $move) use ($single, $sels, $newest, $ts, $s, $sa, $sz): array {
            if ($single) {
                // One range the input method named: the newest caret goes in it, where the input method says, the others move with the text.
                foreach ($sels as $sl) {
                    if ($sl->id !== $newest) {
                        $sl->anchor = $move($sl->anchor, Change::RIGHT); $sl->head = $move($sl->head, Change::RIGHT);
                        continue;
                    }
                    $start = $move($ts[0][0], Change::LEFT);
                    $sl->anchor = $start + strlen($s); $sl->head = $start + strlen($s); $sl->goal = -1.0;
                    if ($sa >= 0) { $sl->anchor = $start + $sa; $sl->head = $start + $sz; }
                }
                return $sels;
            }
            $out = [];
            $delta = 0;
            foreach ($ts as $i => [$a, $b]) {
                $start = $a + $delta;
                $delta += strlen($s) - ($b - $a);
                $id = $i < count($sels) ? $sels[$i]->id : $this->nextId;
                $sel = new Sel($start + strlen($s), $start + strlen($s), -1.0, $id);
                if ($sa >= 0) { $sel->anchor = $start + $sa; $sel->head = $start + $sz; }
                $out[] = $sel;
            }
            return $out;
        });
    }

    public function unmarkText(): void
    {
        $this->markedActive = false; $this->marked = [];
        $this->buf()?->endGroup();
    }

    public function boundsForRange(TextInputRange $r): ?array
    {
        $this->sync();
        $t = $this->view->text;
        $a = $t->fromUTF16(min($r->start, $r->end));
        $cr = $this->caretRect($a);
        if ($cr === null) { return null; }
        $x = $this->textX + $cr[0] - $this->scrollX;
        $y = $cr[1] - $this->scrollY;
        if ($y + $cr[3] < 0 || $y > $this->viewH) { return null; }
        $z = $t->fromUTF16(max($r->start, $r->end));
        $w = 1.0;
        if ($z > $a && ($cz = $this->caretRect($z)) !== null && $cz[1] == $cr[1]) { $w = max(1.0, $cz[0] - $cr[0]); }
        return [[$x, $y, $w, $cr[3]], $r];
    }

    public function indexForPoint(float $x, float $y): ?int
    {
        $this->sync();
        [$cx, $cy] = $this->toContent($x, $y);
        return $this->view->text->utf16($this->offsetAt($cx, $cy));
    }

    /** Scrolls while a drag goes past the top or the bottom, and reports whether it did. */
    public function autoscroll(): bool
    {
        if (!$this->gesture->active) { return false; }
        $y = $this->pointer[1];
        if ($y < 0) {
            $dy = $y;
        } elseif ($y > $this->viewH) {
            $dy = $y - $this->viewH;
        } else {
            return false;
        }
        $speed = min(abs($dy), 300.0) / 4;
        if ($dy < 0) { $speed = -$speed; }
        $this->setScrollShared($this->scrollY + $speed);
        [$cx, $cy] = $this->toContent($this->pointer[0], $this->pointer[1]);
        if ($this->gesture->mode === Gesture::COLUMNS) {
            $this->columnSelect($cx, $cy);
        } else {
            $this->extendGesture($this->offsetAt($cx, $cy));
        }
        return true;
    }
}
