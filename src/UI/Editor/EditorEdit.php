<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/** Editing the text at the selections (mygo's edit.go). */
trait EditorEdit
{
    /**
     * Makes $edits to the buffer as one step of undo, and gives the
     * selections after them: $after maps each selection, given a function
     * moving offsets through the edits, move(offset, bias); null moves them
     * as the text around them moves.
     * @param list<Edit> $edits @param ?\Closure(\Closure(int, int): int): ?list<Sel> $after
     */
    public function apply(array $edits, int $kind, ?\Closure $after): bool
    {
        $b = $this->buf();
        if ($b === null || $this->o->readOnly || $edits === []) { return false; }
        $before = self::cloneSels($this->sels);
        if ($b->version() !== $this->view->version) {
            // The buffer changed since this editor last looked, as another editor or the app edited it: the edit, made for the text before, is left for its command to run again on the text as it is (retry).
            $this->stale = true;
            return false;
        }
        $changes = $b->edit($edits, $kind, $this, $before);
        $made = $b->version();
        if ($changes === []) {
            // The edits change nothing, as pasting the text selected over it: the selections still go where the edits put them, as the caret after the text pasted.
            if ($after === null) { return false; }
            $sels = $after(fn(int $off, int $bias) => $off);
            if ($sels === null) { return false; }
            $this->setSels(Sel::merge($sels), self::REVEAL_FIT);
            $this->blinkStart = hrtime(true) / 1e9;
            return true;
        }
        $this->dropSuggestion();
        $move = fn(int $off, int $bias) => Change::mapOffset($off, $changes, $bias);
        $sels = $after !== null ? $after($move) : null;
        $this->sync();
        if ($sels !== null) {
            // Placed in the text the edit made, and moved through the edits made after it before the editor followed.
            if ($this->view->version !== $made) {
                foreach ($b->entriesSince($made) ?? [] as [$ver, , $cs]) {
                    if ($ver > $this->view->version) { break; }
                    foreach ($sels as $i => $s) { $sels[$i] = self::mapSel($s, $cs); }
                }
            }
            $this->sels = Sel::merge($sels);
        }
        $b->setSelectionsAfter($this, self::cloneSels($this->sels));
        $this->reveal = max($this->reveal, self::REVEAL_FIT);
        $this->blinkStart = hrtime(true) / 1e9;
        $this->needsBuild = true;
        return true;
    }

    /** Replaces each selection with the text of $fn($i, $sel) and puts the caret after it, or selects it. */
    public function replaceEach(int $kind, bool $selectInserted, \Closure $fn): bool
    {
        $edits = []; $texts = [];
        foreach ($this->sels as $i => $s) {
            $texts[$i] = $fn($i, $s);
            $edits[] = new Edit($s->start(), $s->end(), $texts[$i]);
        }
        $sels = self::cloneSels($this->sels);
        return $this->apply($edits, $kind, function (\Closure $move) use ($sels, $texts, $selectInserted): array {
            $delta = 0;
            foreach ($sels as $i => $s) {
                $start = $s->start() + $delta;
                $end = $start + strlen($texts[$i]);
                $delta += strlen($texts[$i]) - ($s->end() - $s->start());
                if ($selectInserted) { $s->anchor = $start; $s->head = $end; } else { $s->anchor = $end; $s->head = $end; }
                $s->goal = -1.0;
            }
            return $sels;
        });
    }

    /** Types $text at every selection, closing and wrapping brackets. */
    public function insert(string $text): void
    {
        if ($this->o->readOnly) { return; }
        if ($this->o->autoClose && mb_strlen($text, 'UTF-8') === 1 && $this->autoPair($text)) { return; }
        $kind = strpbrk($text, " \n\t") !== false ? Transaction::OTHER : Transaction::TYPING;
        if ($this->replaceEach($kind, false, fn() => $text)) { $this->outdentTyped($text); }
        $this->afterTyping($text);
    }

    /**
     * Outdents the lines that the text just typed closes a block on, as
     * Zed's auto-indent on input does: a closing bracket typed first on its
     * line takes the indentation of its opener's line, and a line that the
     * language's decreaseIndent matches once typed, as end, one level less
     * than the line above suggests.
     */
    public function outdentTyped(string $t): void
    {
        $l = $this->language();
        if ($l === null || str_contains($t, "\n")) { return; }
        $r = $this->view->text;
        $edits = [];
        $last = -1;
        foreach ($this->sels as $s) {
            $line = $r->lineOf($s->head);
            if (!$s->empty() || $line === $last) { continue; }
            $last = $line;
            $a = $r->lineStart($line);
            $text = $r->line($line);
            $indent = Chars::indentOf($text);
            $col = $s->head - $a;
            if ($col < strlen($indent) + strlen($t) || substr($text, $col - strlen($t), strlen($t)) !== $t) { continue; }
            $target = $this->closedIndent($line, $a + strlen($indent), $text, $col, $t);
            if ($target !== null && Chars::indentWidth($target, $this->tabSize()) < Chars::indentWidth($indent, $this->tabSize())) {
                $edits[] = new Edit($a, $a + strlen($indent), $target);
            }
        }
        $this->apply($edits, Transaction::TYPING, null);
    }

    /** The indentation of $line, whose text is $text, now that $t was typed before column $col, when it closes a block; else null. */
    private function closedIndent(int $line, int $at, string $text, int $col, string $t): ?string
    {
        $l = $this->language();
        $r = $this->view->text;
        $word = substr($text, $at - $r->lineStart($line), $col - ($at - $r->lineStart($line)));
        foreach ($l->brackets as $p) {
            if ($p->open === '<' || $word !== $p->close || $t !== $p->close) { continue; }
            $pair = $this->bracketsAround($at, $at + strlen($t));
            if ($pair !== null && $pair[1]->start === $at) { return Chars::indentOf($r->line($r->lineOf($pair[0]->start))); }
            return null;
        }
        if ($l->decreaseIndent === null || preg_match($l->decreaseIndent, $text) !== 1
            || preg_match($l->decreaseIndent, substr($text, 0, $col - strlen($t)) . substr($text, $col)) === 1) {
            return null;
        }
        // One level less than the line above, and the one it opens.
        $above = $line - 1;
        while ($above >= 0 && trim($r->line($above)) === '') { $above--; }
        if ($above < 0) { return null; }
        $prev = $r->line($above);
        $indent = Chars::indentOf($prev);
        if ($this->increasesIndent($prev)) { return $indent; }
        return self::outdentOnce($indent, $this->tabSize());
    }

    /** Closes a bracket or quote typed, wraps the selections in it, or steps over the closing one it typed before. Reports whether it did. */
    public function autoPair(string $t): bool
    {
        $l = $this->language();
        if ($l === null) { return false; }
        $r = $this->view->text;
        // Typing the closer just typed steps over it, at every caret.
        $closers = [];
        foreach ($this->autoclosed as $a) { if ($a['close'] === $t) { $closers[$a['at']] = true; } }
        $allOver = $closers !== [];
        foreach ($this->sels as $s) {
            if (!$s->empty() || !isset($closers[$s->head]) || $r->slice($s->head, $s->head + strlen($t)) !== $t) { $allOver = false; break; }
        }
        if ($allOver) {
            $sels = self::cloneSels($this->sels);
            foreach ($sels as $s) { $s->collapse($s->head + strlen($t)); }
            $this->autoclosed = array_values(array_filter($this->autoclosed, fn(array $a) => !($a['close'] === $t && isset($closers[$a['at']]))));
            $this->sels = $sels;
            $this->reveal = max($this->reveal, self::REVEAL_FIT);
            return true;
        }
        $pair = null;
        foreach ($l->autoClose as $p) { if ($p->open === $t) { $pair = $p; break; } }
        if ($pair === null) { return false; }
        // Selections are wrapped in the pair, when there are only selections.
        $anySel = false; $anyCaret = false;
        foreach ($this->sels as $s) { if ($s->empty()) { $anyCaret = true; } else { $anySel = true; } }
        if ($anySel) {
            if ($anyCaret) { return false; }
            $edits = [];
            foreach ($this->sels as $s) {
                $edits[] = new Edit($s->start(), $s->start(), $pair->open);
                $edits[] = new Edit($s->end(), $s->end(), $pair->close);
            }
            $sels = self::cloneSels($this->sels);
            return $this->apply($edits, Transaction::OTHER, function (\Closure $move) use ($sels): array {
                foreach ($sels as $s) {
                    $a = $move($s->start(), Change::RIGHT); $z = $move($s->end(), Change::LEFT);
                    if ($s->head < $s->anchor) { $s->anchor = $z; $s->head = $a; } else { $s->anchor = $a; $s->head = $z; }
                }
                return $sels;
            });
        }
        // Close it, where what follows allows: the end of the line, whitespace or a closing bracket, and not inside a string or a comment for quotes.
        foreach ($this->sels as $s) {
            [$next] = $r->runeAt($s->head);
            if ($s->head < $r->len() && $next !== "\n" && !Chars::isSpace($next) && !str_contains(')]}>,;:', $next)) { return false; }
            if ($pair->open === $pair->close) {
                // A quote after a word character starts nothing.
                [$prev] = $r->runeBefore($s->head);
                if (View::isWordRune($prev)) { return false; }
            }
            if ($pair->notIn !== [] && in_array($this->tokenAt($s->head), $pair->notIn, true)) { return false; }
        }
        if (!$this->replaceEach(Transaction::TYPING, false, fn() => $pair->open . $pair->close)) { return false; }
        foreach ($this->sels as $s) {
            $s->collapse($s->head - strlen($pair->close));
            $this->autoclosed[] = ['sel' => $s->id, 'at' => $s->head, 'close' => $pair->close];
        }
        $this->afterTyping($t);
        return true;
    }

    /** The kind of the token holding the byte before $off, or at it at the start of a line. */
    public function tokenAt(int $off): int
    {
        $v = $this->view;
        $l = $v->line($v->text->lineOf($off));
        return self::kindAt($l->toks, max($off - $l->start - 1, 0));
    }

    /** Asks for completions as a word is typed. */
    public function afterTyping(string $t): void
    {
        if ($this->o->onCompletion === null) { return; }
        $r = mb_substr($t, 0, 1, 'UTF-8');
        if (View::isWordRune($r) || strpbrk($t, '.:>') !== false) {
            $this->requestCompletions(false);
        } else {
            $this->complete->close();
        }
    }

    /** The text a level of indentation inserts. */
    public function indentUnit(): string
    {
        return $this->hardTabs() ? "\t" : str_repeat(' ', $this->tabSize());
    }

    /** Takes back (or makes again) the buffer's last step, and puts this editor's selections where they were, when it made the step. */
    public function undo(bool $redo): bool
    {
        $b = $this->buf();
        if ($b === null || $this->o->readOnly) { return false; }
        $tx = $redo ? $b->redoStep() : $b->undoStep();
        if ($tx === null) { return false; }
        $this->sync();
        $saved = $redo ? $tx->selAfter : $tx->selBefore;
        if (is_array($saved) && $tx->owner === $this && $saved !== []) {
            $this->sels = Sel::merge($this->clampSels($saved));
        }
        $this->reveal = self::REVEAL_FIT;
        $this->needsBuild = true;
        $this->complete->close();
        return true;
    }

    /** Writes the selections to the clipboard: a caret alone copies its whole line, which pastes above the line of a caret alone, as Zed and VS Code do. */
    public function copy(bool $cut): void
    {
        $r = $this->view->text;
        $pieces = []; $whole = []; $edits = [];
        foreach ($this->sels as $s) {
            if ($s->empty()) {
                $line = $r->lineOf($s->head);
                $a = $r->lineStart($line); $z = min($r->len(), $r->lineEnd($line) + 1);
                $t = $r->slice($a, $z);
                if (!str_ends_with($t, "\n")) { $t .= "\n"; }
                $pieces[] = $t; $whole[] = true;
                $edits[] = new Edit($a, $z);
                continue;
            }
            $pieces[] = $r->slice($s->start(), $s->end()); $whole[] = false;
            $edits[] = new Edit($s->start(), $s->end());
        }
        $text = '';
        foreach ($pieces as $i => $p) {
            if ($i > 0 && !$whole[$i - 1]) { $text .= "\n"; }
            $text .= $p;
        }
        self::$lastClip = ['text' => $text, 'pieces' => $pieces, 'lines' => $whole];
        ($this->services->writeClipboard)($text);
        if ($cut && !$this->o->readOnly) { $this->apply($edits, Transaction::OTHER, null); }
    }

    /** Inserts $text at the selections: a piece each when the clipboard holds what this editor copied from as many selections, and whole lines above the lines of carets. */
    public function paste(string $text): void
    {
        if ($this->o->readOnly || $text === '') { return; }
        $text = str_replace("\r\n", "\n", $text);
        $r = $this->view->text;
        $pieces = [$text]; $whole = [false];
        $clip = self::$lastClip;
        if ($clip['text'] !== $text) {
        } elseif (count($clip['pieces']) === count($this->sels)) {
            $pieces = $clip['pieces']; $whole = $clip['lines'];
        } elseif (!in_array(false, $clip['lines'], true)) {
            // Whole lines, from one caret or several: pasted as lines above each caret's, as Zed's.
            $whole = [true];
        }
        $edits = [];
        foreach ($this->sels as $i => $s) {
            [$p, $w] = count($pieces) === count($this->sels) ? [$pieces[$i], $whole[$i]] : [$pieces[0], $whole[0]];
            if ($w && $s->empty()) {
                $a = $r->lineStart($r->lineOf($s->head));
                $edits[] = new Edit($a, $a, $p);
                continue;
            }
            $edits[] = new Edit($s->start(), $s->end(), $p);
        }
        $sels = self::cloneSels($this->sels);
        $this->apply($edits, Transaction::OTHER, function (\Closure $move) use ($sels): array {
            foreach ($sels as $s) { $s->collapse($move($s->end(), Change::RIGHT)); }
            return $sels;
        });
    }

    /** Deletes each selection, or from each caret to where $fn($sel) takes it. */
    public function deleteMove(\Closure $fn): bool
    {
        $edits = [];
        foreach ($this->sels as $s) {
            if ($s->empty()) {
                $to = $fn($s);
                $edits[] = new Edit(min($to, $s->head), max($to, $s->head));
                continue;
            }
            $edits[] = new Edit($s->start(), $s->end());
        }
        return $this->apply(self::mergeEdits($edits), Transaction::DELETING, null);
    }

    /** Sorts edits and merges those that overlap. @param list<Edit> $edits @return list<Edit> */
    public static function mergeEdits(array $edits): array
    {
        usort($edits, fn(Edit $a, Edit $b) => $a->start <=> $b->start);
        $out = [];
        foreach ($edits as $e) {
            $n = count($out);
            if ($n > 0 && $e->start <= $out[$n - 1]->end && $e->text === '' && $out[$n - 1]->text === '') {
                $out[$n - 1] = new Edit($out[$n - 1]->start, max($out[$n - 1]->end, $e->end));
                continue;
            }
            $out[] = $e;
        }
        return $out;
    }

    /** Deletes before the carets: back to the previous tab stop in indentation, a pair of brackets just opened, else a character. */
    public function backspace(): bool
    {
        $r = $this->view->text;
        $tab = $this->tabSize();
        $edits = [];
        foreach ($this->sels as $s) {
            if (!$s->empty()) {
                $edits[] = new Edit($s->start(), $s->end());
                continue;
            }
            $line = $r->lineOf($s->head);
            $a = $r->lineStart($line);
            $before = $r->slice($a, $s->head);
            // In the indentation, back to the previous tab stop.
            if ($before !== '' && ltrim($before, ' ') === '' && !$this->hardTabs()) {
                $col = strlen($before);
                $to = intdiv($col - 1, $tab) * $tab;
                $edits[] = new Edit($a + $to, $s->head);
                continue;
            }
            $start = Chars::graphemeLeft($r, $s->head);
            $end = $s->head;
            // A pair of brackets just opened, its closer closed by the editor, goes together, as Zed's.
            if (($l = $this->language()) !== null && $this->o->autoClose) {
                $prev = $r->slice($start, $s->head);
                foreach ($l->autoClose as $p) {
                    if ($p->open === $prev && $r->slice($s->head, $s->head + strlen($p->close)) === $p->close && $this->autoclosedAt($s->head, $p->close)) {
                        $end = $s->head + strlen($p->close);
                        break;
                    }
                }
            }
            $edits[] = new Edit($start, $end);
        }
        return $this->apply(self::mergeEdits($edits), Transaction::DELETING, null);
    }

    /**
     * Breaks the line at each caret, indenting the new line as the
     * language says: one more level after an opening bracket, with the
     * closing bracket on a line of its own, and continuing line comments.
     */
    public function newline(): bool
    {
        $r = $this->view->text;
        $texts = []; $carets = [];
        foreach ($this->sels as $i => $s) {
            $line = $r->lineOf($s->start());
            $a = $r->lineStart($line);
            $before = $r->slice($a, $s->start());
            $after = $r->slice($s->end(), $r->lineEnd($r->lineOf($s->end())));
            $indent = Chars::indentOf($r->line($line));
            if (strlen($indent) > strlen($before)) { $indent = $before; }
            $next = $indent;
            $levels = $this->indentAfter($s->start(), $before);
            for (; $levels > 0; $levels--) { $next .= $this->indentUnit(); }
            for (; $levels < 0 && $next !== ''; $levels++) { $next = self::outdentOnce($next, $this->tabSize()); }
            // Return in a line comment goes on with it, as Zed's extend_comment_on_newline.
            if (($l = $this->language()) !== null && $l->lineComment !== '' && str_starts_with(ltrim($before, " \t"), $l->lineComment)) {
                $next = $indent . $l->lineComment . ' ';
            }
            $t = "\n" . $next;
            $caret = strlen($t);
            // Between brackets, the closer goes on a line of its own.
            if ($this->closesBefore($before, $after)) { $t .= "\n" . $indent; }
            $texts[$i] = $t; $carets[$i] = $caret;
        }
        $edits = [];
        foreach ($this->sels as $i => $s) {
            $start = $s->start();
            $line = $r->lineOf($start);
            $a = $r->lineStart($line);
            if (trim($r->slice($a, $start)) === '' && $r->slice($start, $r->lineEnd($line)) === '' && $start > $a) {
                // Trailing whitespace of a blank line goes.
                $edits[] = new Edit($a, $s->end(), $texts[$i]);
                continue;
            }
            $edits[] = new Edit($start, $s->end(), $texts[$i]);
        }
        $sels = self::cloneSels($this->sels);
        return $this->apply($edits, Transaction::OTHER, function () use ($sels, $edits, $carets): array {
            $delta = 0;
            foreach ($sels as $i => $s) {
                $e = $edits[$i];
                $at = $e->start + $delta + $carets[$i];
                $delta += strlen($e->text) - ($e->end - $e->start);
                $s->collapse($at);
            }
            return $sels;
        });
    }

    /** How many levels more than its line a line Return opens at $off is indented: as the highlighter says, when it knows, else one after a line ending in an opening bracket, or matching the language's increaseIndent. */
    public function indentAfter(int $off, string $before): int
    {
        $in = $this->highlighter();
        if ($in instanceof Indenter && ($levels = $in->indentAfter($off)) !== null) { return $levels; }
        return $this->increasesIndent($before) ? 1 : 0;
    }

    /** Whether the line before the caret, $before, indents the next. */
    public function increasesIndent(string $before): bool
    {
        $l = $this->language();
        if ($l === null) { return false; }
        if ($l->increaseIndent !== null) { return preg_match($l->increaseIndent, $before) === 1; }
        $t = rtrim($before, " \t");
        if ($l->lineComment !== '' && ($i = strpos($t, $l->lineComment)) !== false && strpbrk(substr($t, 0, $i), "\"'`") === false) {
            $t = rtrim(substr($t, 0, $i), " \t");
        }
        foreach ($l->brackets as $p) {
            if (str_ends_with($t, $p->open) && $p->open !== '<') { return true; }
        }
        return false;
    }

    /** Whether the caret is between an opening bracket and its closer. */
    public function closesBefore(string $before, string $after): bool
    {
        $l = $this->language();
        if ($l === null) { return false; }
        $b = rtrim($before, " \t");
        $a = ltrim($after, " \t");
        foreach ($l->brackets as $p) {
            if ($p->open !== '<' && str_ends_with($b, $p->open) && str_starts_with($a, $p->close)) { return true; }
        }
        return false;
    }

    /** Starts a line below (or above) the lines of the selections, indented as theirs. */
    public function newlineAround(bool $above): bool
    {
        $r = $this->view->text;
        $edits = []; $carets = [];
        foreach ($this->sels as $s) {
            [$a, $z] = $this->lineSpanOf($s);
            if ($above) {
                $indent = Chars::indentOf($r->line($a));
                $at = $r->lineStart($a);
                $edits[] = new Edit($at, $at, $indent . "\n");
                $carets[] = strlen($indent);
                continue;
            }
            $line = $r->line($z);
            $indent = Chars::indentOf($line);
            if ($this->increasesIndent($line)) { $indent .= $this->indentUnit(); }
            $at = $r->lineEnd($z);
            $edits[] = new Edit($at, $at, "\n" . $indent);
            $carets[] = 1 + strlen($indent);
        }
        $sels = self::cloneSels($this->sels);
        return $this->apply($edits, Transaction::OTHER, function () use ($sels, $edits, $carets): array {
            $delta = 0;
            foreach ($sels as $i => $s) {
                $e = $edits[$i];
                $s->collapse($e->start + $delta + $carets[$i]);
                $delta += strlen($e->text);
            }
            return $sels;
        });
    }

    /** Takes a level of indentation off $indent. */
    public static function outdentOnce(string $indent, int $tab): string
    {
        if (str_ends_with($indent, "\t")) { return substr($indent, 0, -1); }
        $n = strlen($indent) - strlen(rtrim($indent, ' '));
        return substr($indent, 0, strlen($indent) - min($n, max(1, ($n - 1) % $tab + 1)));
    }

    /** Whether the editor closed the bracket $close at $off, as it was opened. */
    public function autoclosedAt(int $off, string $close): bool
    {
        foreach ($this->autoclosed as $a) { if ($a['at'] === $off && $a['close'] === $close) { return true; } }
        return false;
    }
}
