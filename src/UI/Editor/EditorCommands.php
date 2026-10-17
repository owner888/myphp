<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/** Running the commands keys are bound to (mygo's commands.go). */
trait EditorCommands
{
    /** Runs $cmd, and reports whether it did something. */
    public function run(int $cmd): bool
    {
        $r = $this->view->text;
        $extra = $this->wordChars();
        switch ($cmd) {
            case Command::MOVE_LEFT:
            case Command::SELECT_LEFT:
                return $this->move($cmd === Command::SELECT_LEFT, fn(Sel $s) => $s->empty() || $cmd === Command::SELECT_LEFT ? Chars::graphemeLeft($r, $s->head) : $s->start());
            case Command::MOVE_RIGHT:
            case Command::SELECT_RIGHT:
                return $this->move($cmd === Command::SELECT_RIGHT, fn(Sel $s) => $s->empty() || $cmd === Command::SELECT_RIGHT ? Chars::graphemeRight($r, $s->head) : $s->end());
            case Command::MOVE_WORD_LEFT:
            case Command::SELECT_WORD_LEFT:
                return $this->move($cmd === Command::SELECT_WORD_LEFT, fn(Sel $s) => Chars::wordLeft($r, $s->head, $extra, false));
            case Command::MOVE_WORD_RIGHT:
            case Command::SELECT_WORD_RIGHT:
                return $this->move($cmd === Command::SELECT_WORD_RIGHT, fn(Sel $s) => Chars::wordRight($r, $s->head, $extra, false));
            case Command::MOVE_SUBWORD_LEFT:
            case Command::SELECT_SUBWORD_LEFT:
                return $this->move($cmd === Command::SELECT_SUBWORD_LEFT, fn(Sel $s) => Chars::wordLeft($r, $s->head, $extra, true));
            case Command::MOVE_SUBWORD_RIGHT:
            case Command::SELECT_SUBWORD_RIGHT:
                return $this->move($cmd === Command::SELECT_SUBWORD_RIGHT, fn(Sel $s) => Chars::wordRight($r, $s->head, $extra, true));
            case Command::MOVE_UP:
            case Command::SELECT_UP:
            case Command::MOVE_DOWN:
            case Command::SELECT_DOWN:
            case Command::MOVE_PAGE_UP:
            case Command::SELECT_PAGE_UP:
            case Command::MOVE_PAGE_DOWN:
            case Command::SELECT_PAGE_DOWN:
                if ($this->complete->open() && ($cmd === Command::MOVE_UP || $cmd === Command::MOVE_DOWN)) {
                    $this->complete->step(self::direction($cmd === Command::MOVE_UP));
                    return true;
                }
                $page = max(1, (int)($this->viewH / $this->view->lineHeight) - 2);
                $rows = match ($cmd) {
                    Command::MOVE_UP, Command::SELECT_UP => -1,
                    Command::MOVE_PAGE_UP, Command::SELECT_PAGE_UP => -$page,
                    Command::MOVE_PAGE_DOWN, Command::SELECT_PAGE_DOWN => $page,
                    default => 1,
                };
                $extend = in_array($cmd, [Command::SELECT_UP, Command::SELECT_DOWN, Command::SELECT_PAGE_UP, Command::SELECT_PAGE_DOWN], true);
                $sels = self::cloneSels($this->sels);
                foreach ($sels as $s) {
                    $from = $s->head;
                    if (!$extend && !$s->empty()) { $from = $rows < 0 ? $s->start() : $s->end(); }
                    [$to, $goal] = $this->verticalMove($from, $s->goal, $rows);
                    if ($extend) { $s->head = $to; } else { $s->anchor = $to; $s->head = $to; }
                    $s->goal = $goal;
                }
                if ($rows !== 1 && $rows !== -1) { $this->scrollBy($rows * $this->view->lineHeight); }
                $this->setSels($sels, self::REVEAL_FIT);
                $this->endGroup();
                return true;
            case Command::MOVE_LINE_START:
            case Command::SELECT_LINE_START:
                return $this->move($cmd === Command::SELECT_LINE_START, fn(Sel $s) => $this->smartHome($s->head));
            case Command::MOVE_LINE_END:
            case Command::SELECT_LINE_END:
                return $this->move($cmd === Command::SELECT_LINE_END, fn(Sel $s) => $this->rowEnd($s->head));
            case Command::MOVE_DOC_START:
            case Command::SELECT_DOC_START:
                return $this->move($cmd === Command::SELECT_DOC_START, fn() => 0);
            case Command::MOVE_DOC_END:
            case Command::SELECT_DOC_END:
                return $this->move($cmd === Command::SELECT_DOC_END, fn() => $r->len());
            case Command::MOVE_PARAGRAPH_UP:
            case Command::SELECT_PARAGRAPH_UP:
                return $this->move($cmd === Command::SELECT_PARAGRAPH_UP, fn(Sel $s) => $this->paragraph($s->head, -1));
            case Command::MOVE_PARAGRAPH_DOWN:
            case Command::SELECT_PARAGRAPH_DOWN:
                return $this->move($cmd === Command::SELECT_PARAGRAPH_DOWN, fn(Sel $s) => $this->paragraph($s->head, 1));
            case Command::MOVE_TO_BRACKET:
            case Command::SELECT_TO_BRACKET:
                // As Zed's: from either side of a bracket to the same side of the other, inside or outside the pair.
                return $this->move($cmd === Command::SELECT_TO_BRACKET, function (Sel $s): int {
                    $pair = $this->enclosingBrackets($s->head);
                    if ($pair === null) { return $s->head; }
                    [$a, $b] = $pair;
                    $inside = $s->head >= $a->end && $s->head <= $b->start;
                    if ($s->head >= $b->start && $s->head <= $b->end && $inside) { return $a->end; }
                    if ($s->head >= $b->start && $s->head <= $b->end) { return $a->start; }
                    if ($inside) { return $b->start; }
                    return $b->end;
                });
            case Command::SELECT_ALL:
                $this->setSels([$this->newSel(0, $r->len())], self::REVEAL_NONE);
                return true;
            case Command::SELECT_LINE:
                // The lines of the selections, and the line a selection of whole lines ends at the start of, as pressing it again extends.
                $sels = self::cloneSels($this->sels);
                foreach ($sels as $s) {
                    $a = $r->lineOf($s->start()); $z = $r->lineOf($s->end());
                    $s->anchor = $r->lineStart($a);
                    $s->head = $z + 1 >= $r->lineCount() ? $r->len() : min($r->lineStart($z + 1), $r->len());
                }
                $this->setSels($sels, self::REVEAL_FIT);
                return true;
            case Command::SELECT_NEXT:
            case Command::SELECT_PREVIOUS:
                return $this->selectNext($cmd === Command::SELECT_PREVIOUS);
            case Command::SELECT_ALL_MATCHES:
                return $this->selectAllMatches();
            case Command::ADD_CURSOR_ABOVE:
            case Command::ADD_CURSOR_BELOW:
                return $this->addCursor(self::direction($cmd === Command::ADD_CURSOR_ABOVE));
            case Command::SELECT_LARGER:
                return $this->selectLarger();
            case Command::SELECT_SMALLER:
                return $this->selectSmaller();
            case Command::UNDO_SELECTION:
                if (($n = count($this->selUndo)) > 0) {
                    $this->selRedo[] = self::cloneSels($this->sels);
                    $this->sels = Sel::merge($this->clampSels($this->selUndo[$n - 1]));
                    array_pop($this->selUndo);
                    $this->reveal = self::REVEAL_FIT;
                    return true;
                }
                return false;
            case Command::REDO_SELECTION:
                if (($n = count($this->selRedo)) > 0) {
                    $this->selUndo[] = self::cloneSels($this->sels);
                    $this->sels = Sel::merge($this->clampSels($this->selRedo[$n - 1]));
                    array_pop($this->selRedo);
                    $this->reveal = self::REVEAL_FIT;
                    return true;
                }
                return false;
            case Command::SPLIT_SELECTION_INTO_LINES:
                // A selection of each line's part of each selection, as Sublime's and Zed's.
                $sels = [];
                foreach ($this->sels as $s) {
                    [$a, $z] = $this->lineSpanOf($s);
                    for ($line = $a; $line <= $z; $line++) {
                        $sels[] = $this->newSel(max($s->start(), $r->lineStart($line)), min($s->end(), $r->lineEnd($line)));
                    }
                }
                $this->setSels($sels, self::REVEAL_NONE);
                return true;
            case Command::CANCEL:
                if ($this->complete->open()) {
                    $this->complete->close();
                } elseif ($this->hover->open()) {
                    $this->hover->close();
                } elseif ($this->goTo->open) {
                    $this->goTo->close($this);
                } elseif ($this->find->open) {
                    $this->find->open = false;
                    $this->needsBuild = true;
                    $this->refocus = true;
                } elseif (count($this->sels) > 1) {
                    $this->setSels([$this->primary()], self::REVEAL_FIT);
                } elseif (!$this->sels[0]->empty()) {
                    $s = clone $this->sels[0];
                    $s->collapse($s->head);
                    $this->setSels([$s], self::REVEAL_NONE);
                } else {
                    return false;
                }
                return true;
            case Command::UNDO:
            case Command::REDO:
                return $this->undo($cmd === Command::REDO);
            case Command::COPY:
            case Command::CUT:
                $this->copy($cmd === Command::CUT);
                return true;
            case Command::PASTE:
                $this->paste(($this->services->readClipboard)());
                return true;
            case Command::BACKSPACE:
                return $this->backspace();
            case Command::DELETE:
                return $this->deleteMove(fn(Sel $s) => Chars::graphemeRight($r, $s->head));
            case Command::DELETE_WORD_LEFT:
                return $this->deleteMove(fn(Sel $s) => Chars::wordLeft($r, $s->head, $extra, false));
            case Command::DELETE_WORD_RIGHT:
                return $this->deleteMove(fn(Sel $s) => Chars::wordRight($r, $s->head, $extra, false));
            case Command::DELETE_SUBWORD_LEFT:
                return $this->deleteMove(fn(Sel $s) => Chars::wordLeft($r, $s->head, $extra, true));
            case Command::DELETE_SUBWORD_RIGHT:
                return $this->deleteMove(fn(Sel $s) => Chars::wordRight($r, $s->head, $extra, true));
            case Command::DELETE_TO_LINE_START:
                return $this->deleteMove(fn(Sel $s) => $s->head === $r->lineStart($r->lineOf($s->head)) ? Chars::graphemeLeft($r, $s->head) : $this->smartHome($s->head));
            case Command::DELETE_TO_LINE_END:
                return $this->deleteMove(fn(Sel $s) => $s->head === $r->lineEnd($r->lineOf($s->head)) ? Chars::graphemeRight($r, $s->head) : $r->lineEnd($r->lineOf($s->head)));
            case Command::CUT_TO_LINE_END:
                // As Emacs: the rest of the line, or its newline.
                $cut = [];
                foreach ($this->sels as $s) {
                    $end = $r->lineEnd($r->lineOf($s->head));
                    if ($end === $s->head) { $end = min($r->len(), $end + 1); }
                    $cut[] = $r->slice($s->head, $end);
                }
                ($this->services->writeClipboard)(implode("\n", $cut));
                return $this->deleteMove(function (Sel $s) use ($r): int {
                    $end = $r->lineEnd($r->lineOf($s->head));
                    return $end === $s->head ? min($r->len(), $end + 1) : $end;
                });
            case Command::DELETE_LINE:
                return $this->deleteLines();
            case Command::NEWLINE:
                if ($this->complete->open()) { return $this->acceptCompletion(); }
                return $this->newline();
            case Command::NEWLINE_ABOVE:
            case Command::NEWLINE_BELOW:
                return $this->newlineAround($cmd === Command::NEWLINE_ABOVE);
            case Command::MOVE_LINES_UP:
            case Command::MOVE_LINES_DOWN:
                return $this->moveLines(self::direction($cmd === Command::MOVE_LINES_UP));
            case Command::DUPLICATE_LINES_UP:
            case Command::DUPLICATE_LINES_DOWN:
                return $this->duplicateLines($cmd === Command::DUPLICATE_LINES_UP);
            case Command::DUPLICATE_SELECTION:
                foreach ($this->sels as $s) { if ($s->empty()) { return $this->duplicateLines(false); } }
                return $this->replaceEach(Transaction::OTHER, true, function (int $i, Sel $s) use ($r): string {
                    $t = $r->slice($s->start(), $s->end());
                    return $t . $t;
                });
            case Command::JOIN_LINES:
                return $this->joinLines();
            case Command::TRANSPOSE:
                return $this->transpose();
            case Command::INDENT:
                return $this->indentLines(1);
            case Command::OUTDENT:
            case Command::BACKTAB:
                return $this->indentLines(-1);
            case Command::TAB:
                if ($this->complete->open()) { return $this->acceptCompletion(); }
                if ($this->acceptSuggestion()) { return true; }
                return $this->tab();
            case Command::TOGGLE_COMMENT:
                return $this->toggleComment();
            case Command::TOGGLE_BLOCK_COMMENT:
                return $this->toggleBlockComment();
            case Command::SORT_LINES:
            case Command::SORT_LINES_CASE_INSENSITIVE:
            case Command::REVERSE_LINES:
            case Command::UNIQUE_LINES:
                return $this->manipulateLines($cmd);
            case Command::UPPERCASE:
            case Command::LOWERCASE:
            case Command::TITLE_CASE:
            case Command::SNAKE_CASE:
            case Command::KEBAB_CASE:
            case Command::CAMEL_CASE:
            case Command::PASCAL_CASE:
                return $this->convertCase($cmd);
            case Command::TRIM_TRAILING_WHITESPACE:
                $edits = [];
                for ($line = 0; $line < $r->lineCount(); $line++) {
                    $s = $r->line($line);
                    $t = rtrim($s, " \t");
                    if (strlen($t) < strlen($s)) {
                        $a = $r->lineStart($line);
                        $edits[] = new Edit($a + strlen($t), $a + strlen($s));
                    }
                }
                return $this->apply($edits, Transaction::OTHER, null);
            case Command::FOLD:
                return $this->foldAt($this->primary()->head);
            case Command::UNFOLD:
                return $this->unfoldAt($this->primary()->head, false);
            case Command::TOGGLE_FOLD:
                if (!$this->unfoldAt($this->primary()->head, false)) { return $this->foldAt($this->primary()->head); }
                return true;
            case Command::FOLD_ALL:
                return $this->foldLevel(0);
            case Command::UNFOLD_ALL:
                $this->folds = [];
                $this->needsBuild = true;
                return true;
            case Command::FOLD_RECURSIVE:
                return $this->foldRecursive($this->primary()->head);
            case Command::UNFOLD_RECURSIVE:
                return $this->unfoldAt($this->primary()->head, true);
            case Command::FOLD_SELECTION:
                foreach ($this->sels as $s) {
                    [$a, $z] = $this->lineSpanOf($s);
                    if ($z > $a) { $this->folds[] = [$a, $z]; }
                }
                $this->moveOutOfFolds();
                $this->needsBuild = true;
                return true;
            case Command::FIND:
            case Command::FIND_REPLACE:
                $this->find->show($this, $cmd === Command::FIND_REPLACE);
                return true;
            case Command::FIND_NEXT:
            case Command::FIND_PREVIOUS:
                return $this->find->step($this, self::direction($cmd === Command::FIND_PREVIOUS));
            case Command::USE_SELECTION_FOR_FIND:
                if (($t = $this->selectedText()) !== '') {
                    $this->find->query = $t;
                    $this->find->stale = true;
                    $this->needsBuild = true;
                }
                return true;
            case Command::FIND_IN_SELECTION:
                $this->find->show($this, false);
                $this->find->inSelection = !$this->find->inSelection;
                $this->find->scope = null;
                $this->find->stale = true;
                return true;
            case Command::GO_TO_LINE:
                $this->goTo->show($this);
                return true;
            case Command::SCROLL_CURSOR_CENTER:
                if (($cr = $this->caretRect($this->primary()->head)) !== null) {
                    $this->setScroll($cr[1] - ($this->viewH - $this->view->lineHeight) / 2);
                }
                return true;
            case Command::SCROLL_LINE_UP:
            case Command::SCROLL_LINE_DOWN:
                $this->scrollBy(self::direction($cmd === Command::SCROLL_LINE_UP) * $this->view->lineHeight);
                return true;
            case Command::GO_TO_DEFINITION:
                if ($this->o->onDefinition !== null) {
                    ($this->o->onDefinition)($this->primary()->head);
                    return true;
                }
                return false;
            case Command::SHOW_COMPLETIONS:
                return $this->requestCompletions(true);
            case Command::SHOW_HOVER:
                $this->hover->byPointer = false;
                return $this->requestHover($this->primary()->head);
            case Command::NEXT_HUNK:
            case Command::PREVIOUS_HUNK:
                return $this->gotoHunk(self::direction($cmd === Command::PREVIOUS_HUNK));
            case Command::REVERT_HUNK:
                return $this->revertHunks();
            case Command::TOGGLE_SOFT_WRAP:
                $this->toggledWrap = !$this->toggledWrap;
                $this->needsBuild = true;
                return true;
            case Command::TOGGLE_LINE_NUMBERS:
                $this->toggledNumbers = !$this->toggledNumbers;
                $this->needsBuild = true;
                return true;
        }
        if ($cmd >= Command::FOLD_LEVEL1 && $cmd <= Command::FOLD_LEVEL9) { return $this->foldLevel($cmd - Command::FOLD_LEVEL1 + 1); }
        return false;
    }

    /** Moves each caret to where $fn($sel) takes it, extending the selections when $extend is set. */
    public function move(bool $extend, \Closure $fn): bool
    {
        $sels = self::cloneSels($this->sels);
        foreach ($sels as $s) {
            $to = $this->overFolds($s->head, $fn(clone $s));
            if ($extend) { $s->head = $to; } else { $s->anchor = $to; $s->head = $to; }
            $s->goal = -1.0;
        }
        $this->setSels($sels, self::REVEAL_FIT);
        $this->endGroup();
        return true;
    }

    /** Ends the group of typing that undo takes back as one step, as moving the carets does, and closes the completions. */
    public function endGroup(): void
    {
        $this->buf()?->endGroup();
        $this->complete->close();
    }

    /** $sels kept within the text, as copies. @param list<Sel> $sels @return list<Sel> */
    public function clampSels(array $sels): array
    {
        $n = $this->view->text->len();
        $out = self::cloneSels($sels);
        foreach ($out as $s) { $s->anchor = min($s->anchor, $n); $s->head = min($s->head, $n); }
        return $out;
    }

    /** The first non-blank character of the row, or its start from there, as Zed's beginning of line stops at the indentation. */
    public function smartHome(int $off): int
    {
        $v = $this->view;
        $r = $v->text;
        $line = $r->lineOf($off);
        $l = $v->line($line);
        $d = $l->toDisp($off - $l->start, true);
        $ri = $l->rowOf($d, false);
        if ($ri > 0) {
            $start = $l->start + $l->fromDisp($l->rows[$ri]['from']);
            if ($off !== $start) { return $start; }
        }
        $a = $r->lineStart($line);
        $first = $a + strlen(Chars::indentOf($l->text));
        return $off === $first ? $a : $first;
    }

    /** The end of the row holding $off: the end of the line, or of its row when it wraps. */
    public function rowEnd(int $off): int
    {
        $v = $this->view;
        $line = $v->text->lineOf($off);
        $l = $v->line($line);
        $d = $l->toDisp($off - $l->start, true);
        $ri = $l->rowOf($d, false);
        if ($ri < count($l->rows) - 1) {
            $end = $l->start + $l->fromDisp($l->rows[$ri]['to']);
            // Before the space a row ends with.
            if ($end > $off && $end > $l->start && $l->text[$end - $l->start - 1] === ' ') { return $end - 1; }
            return $end;
        }
        return $v->text->lineEnd($line);
    }

    /** The start of the next blank line after $off going down, or before it going up. */
    public function paragraph(int $off, int $dir): int
    {
        $r = $this->view->text;
        $line = $r->lineOf($off);
        $n = $r->lineCount();
        $blank = fn(int $i) => trim($r->line($i)) === '';
        for (;;) {
            $line += $dir;
            if ($line <= 0) { return 0; }
            if ($line >= $n) { return $r->len(); }
            if ($blank($line) && !$blank($line - $dir)) { return $r->lineStart($line); }
        }
    }

    /** Scrolls by $dy points, down for positive. */
    public function scrollBy(float $dy): void { $this->setScroll($this->scrollY + $dy); }

    /** -1 going back, up or left, and 1 the other way. */
    public static function direction(bool $back): int { return $back ? -1 : 1; }

    /**
     * Moves a caret going from $off to $to over the lines a fold hides
     * rather than into them, which would unfold them: going forward to the
     * first line shown after them, back to the end of the line folding
     * them, as Zed and VS Code skip a fold.
     */
    public function overFolds(int $off, int $to): int
    {
        $r = $this->view->text;
        $hidden = $this->hiddenSpans();
        $h = self::hiddenHeights($hidden);
        $line = $r->lineOf($to);
        if (!$h->isHidden($line)) { return $to; }
        foreach ($hidden as [$from, $end]) {
            if ($line < $from || $line > $end) { continue; }
            if ($to > $off && $end + 1 < $r->lineCount()) { return $r->lineStart($end + 1); }
            return $r->lineEnd($from - 1);
        }
        return $to;
    }
}
