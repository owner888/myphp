<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/** Commands on the lines of the selections (mygo's lines.go). */
trait EditorLines
{
    /** The lines a selection covers: a selection ending at the start of a line leaves that line out, as Zed's. @return array{0: int, 1: int} */
    public function lineSpanOf(Sel $s): array
    {
        $r = $this->view->text;
        $a = $r->lineOf($s->start()); $z = $r->lineOf($s->end());
        if ($z > $a && $s->end() === $r->lineStart($z)) { $z--; }
        return [$a, $z];
    }

    /** The line spans of the selections, merged. @return list<array{0: int, 1: int}> */
    public function selLines(): array
    {
        $spans = [];
        foreach ($this->sels as $s) {
            [$a, $z] = $this->lineSpanOf($s);
            $n = count($spans);
            if ($n > 0 && $a <= $spans[$n - 1][1] + 1) { $spans[$n - 1][1] = max($spans[$n - 1][1], $z); continue; }
            $spans[] = [$a, $z];
        }
        return $spans;
    }

    /** Deletes the lines of the selections. */
    public function deleteLines(): bool
    {
        $r = $this->view->text;
        $edits = [];
        foreach ($this->selLines() as [$from, $to]) {
            $a = $r->lineStart($from);
            $z = $r->lineStart($to + 1);
            if ($to + 1 >= $r->lineCount()) {
                $z = $r->len();
                if ($from > 0) { $a = $r->lineEnd($from - 1); }
            }
            $edits[] = new Edit($a, $z);
        }
        // The carets keep their columns on the lines taking the place of those deleted, as Zed's.
        $sels = self::cloneSels($this->sels);
        $cols = [];
        foreach ($sels as $i => $s) { $cols[$i] = mb_strlen($r->slice($r->lineStart($r->lineOf($s->head)), $s->head), 'UTF-8'); }
        return $this->apply($edits, Transaction::OTHER, function (\Closure $move) use ($sels, $cols): array {
            $t = $this->buf()->rope();
            foreach ($sels as $i => $s) {
                $at = $move($s->head, Change::LEFT);
                $line = $t->lineOf($at);
                $a = $t->lineStart($line); $z = $t->lineEnd($line);
                $s->collapse($a + self::byteOfRuneIn($t->slice($a, $z), $cols[$i]));
            }
            return $sels;
        });
    }

    /** The byte of rune $n of $s, its end past its runes. */
    public static function byteOfRuneIn(string $s, int $n): int
    {
        if ($n <= 0) { return 0; }
        if (preg_match('/^(?:.){' . $n . '}/us', $s, $m) === 1) { return strlen($m[0]); }
        return strlen($s);
    }

    /** Moves the lines of the selections up or down a line, with the selections. */
    public function moveLines(int $dir): bool
    {
        $r = $this->view->text;
        $spans = $this->selLines();
        $n = $r->lineCount();
        if ($dir < 0 && $spans[0][0] === 0 || $dir > 0 && $spans[count($spans) - 1][1] >= $n - 1) { return false; }
        $edits = []; $shifts = [];
        foreach ($spans as [$from, $to]) {
            $block = $r->slice($r->lineStart($from), $r->lineEnd($to));
            if ($dir < 0) {
                $prev = $r->line($from - 1);
                $edits[] = new Edit($r->lineStart($from - 1), $r->lineEnd($to), $block . "\n" . $prev);
                $shifts[] = [$r->lineStart($from), $r->lineEnd($to), -(strlen($prev) + 1)];
            } else {
                $next = $r->line($to + 1);
                $edits[] = new Edit($r->lineStart($from), $r->lineEnd($to + 1), $next . "\n" . $block);
                $shifts[] = [$r->lineStart($from), $r->lineEnd($to), strlen($next) + 1];
            }
        }
        $sels = self::cloneSels($this->sels);
        $folds = $this->folds;
        $ok = $this->apply($edits, Transaction::OTHER, function () use ($sels, $shifts): array {
            foreach ($sels as $s) {
                foreach ($shifts as [$from, $to, $by]) {
                    if ($s->start() >= $from && $s->end() <= $to + 1) { $s->anchor += $by; $s->head += $by; break; }
                }
            }
            return $sels;
        });
        if ($ok) {
            // Folds move with their lines.
            foreach ($folds as $i => $f) {
                foreach ($spans as [$from, $to]) {
                    if ($folds[$i][0] >= $from && $folds[$i][1] <= $to) { $folds[$i][0] += $dir; $folds[$i][1] += $dir; }
                }
            }
            $this->folds = $folds;
        }
        return $ok;
    }

    /** Copies the lines of the selections below them, the selections going with the copy, or staying on the lines above it when $up, as Zed's duplicate line down and up. */
    public function duplicateLines(bool $up): bool
    {
        $r = $this->view->text;
        $spans = $this->selLines();
        $edits = [];
        foreach ($spans as [$from, $to]) {
            $at = $r->lineEnd($to);
            $edits[] = new Edit($at, $at, "\n" . $r->slice($r->lineStart($from), $at));
        }
        $sels = self::cloneSels($this->sels);
        return $this->apply($edits, Transaction::OTHER, function (\Closure $move) use ($sels, $spans, $up, $r): array {
            foreach ($sels as $s) {
                // A selection of the lines copied goes with the copy below them, or stays above it; the others move with the text.
                [$a] = $this->lineSpanOf($s);
                $in = -1;
                foreach ($spans as $k => [$from, $to]) { if ($a >= $from && $a <= $to) { $in = $k; } }
                if ($in < 0) {
                    $s->anchor = $move($s->anchor, Change::LEFT); $s->head = $move($s->head, Change::LEFT);
                    continue;
                }
                [$from, $to] = $spans[$in];
                $before = $move($r->lineStart($from), Change::LEFT) - $r->lineStart($from);
                if (!$up) {
                    // Moved by the copies of the spans above, and its own.
                    $by = $r->lineEnd($to) - $r->lineStart($from) + 1;
                    $s->anchor += $before + $by; $s->head += $before + $by;
                    continue;
                }
                $s->anchor += $before; $s->head += $before;
            }
            return $sels;
        });
    }

    /** Joins each selection's lines, or a caret's line with the next, with a space, dropping the indentation and comment markers. */
    public function joinLines(): bool
    {
        $r = $this->view->text;
        $edits = [];
        $comment = $this->language()?->lineComment ?? '';
        foreach ($this->selLines() as [$from, $to]) {
            if ($to === $from) { $to++; }
            if ($to >= $r->lineCount()) { continue; }
            for ($line = $from; $line < $to; $line++) {
                $end = $r->lineEnd($line);
                $next = $r->line($line + 1);
                $rest = ltrim($next, " \t");
                $text = $r->line($line);
                $cur = rtrim($text, " \t");
                if ($comment !== '' && str_starts_with(ltrim($cur, " \t"), $comment) && str_starts_with($rest, $comment)) {
                    $rest = ltrim(substr($rest, strlen($comment)), " \t");
                }
                $trail = strlen($text) - strlen($cur);
                // No space before closing brackets, nor after opening ones.
                $sep = $rest === '' || str_starts_with($rest, ')') || str_starts_with($rest, ']') || str_ends_with($cur, '(') || str_ends_with($cur, '[') ? '' : ' ';
                $edits[] = new Edit($end - $trail, $end + 1 + strlen($next) - strlen($rest), $sep);
            }
        }
        return $this->apply($edits, Transaction::OTHER, null);
    }

    /** Swaps the characters around each caret, as Emacs's C-t. */
    public function transpose(): bool
    {
        $r = $this->view->text;
        $edits = [];
        $sels = self::cloneSels($this->sels);
        foreach ($this->sels as $i => $s) {
            if (!$s->empty()) { continue; }
            $at = $s->head;
            $line = $r->lineOf($at);
            if ($at === $r->lineEnd($line)) { $at = Chars::graphemeLeft($r, $at); }
            $a = Chars::graphemeLeft($r, $at);
            $z = Chars::graphemeRight($r, $at);
            if ($a >= $at || $z <= $at || $a < $r->lineStart($line)) { continue; }
            if (($n = count($edits)) > 0 && $a < $edits[$n - 1]->end) {
                // Next to the caret before, whose swap took these characters.
                continue;
            }
            $edits[] = new Edit($a, $z, $r->slice($at, $z) . $r->slice($a, $at));
            $sels[$i]->collapse($z);
        }
        return $this->apply($edits, Transaction::OTHER, fn() => $sels);
    }

    /** Indents the lines of the selections a level, or outdents them. */
    public function indentLines(int $dir): bool
    {
        $r = $this->view->text;
        $unit = $this->indentUnit();
        $tab = $this->tabSize();
        $edits = [];
        foreach ($this->selLines() as [$from, $to]) {
            for ($line = $from; $line <= $to; $line++) {
                $s = $r->line($line);
                $a = $r->lineStart($line);
                if ($dir > 0) {
                    if (trim($s) === '' && $from !== $to) { continue; }
                    $edits[] = new Edit($a, $a, $unit);
                    continue;
                }
                if (str_starts_with($s, "\t")) {
                    $edits[] = new Edit($a, $a + 1);
                } elseif (str_starts_with($s, ' ')) {
                    $n = strlen($s) - strlen(ltrim($s, ' '));
                    $n = min($n, ($n - 1) % $tab + 1);
                    $edits[] = new Edit($a, $a + $n);
                }
            }
        }
        $sels = self::cloneSels($this->sels);
        return $this->apply($edits, Transaction::OTHER, function (\Closure $move) use ($sels, $r): array {
            foreach ($sels as $s) {
                if ($s->empty()) {
                    $s->collapse($move($s->head, Change::RIGHT));
                    continue;
                }
                // A selection from a line's start keeps the indentation in it.
                $start = $s->start();
                $mv = fn(int $off) => $off === $start && $off === $r->lineStart($r->lineOf($off)) ? $move($off, Change::LEFT) : $move($off, Change::RIGHT);
                $s->anchor = $mv($s->anchor); $s->head = $mv($s->head);
            }
            return $sels;
        });
    }

    /** Inserts a level of indentation, or indents the lines of selections across lines. */
    public function tab(): bool
    {
        $r = $this->view->text;
        // A selection indents its lines, as Zed's.
        foreach ($this->sels as $s) { if (!$s->empty()) { return $this->indentLines(1); } }
        $tab = $this->tabSize();
        return $this->replaceEach(Transaction::TYPING, false, function (int $i, Sel $s) use ($r, $tab): string {
            if ($this->hardTabs()) { return "\t"; }
            $col = self::columnOf($r->slice($r->lineStart($r->lineOf($s->start())), $s->start()), $tab);
            return str_repeat(' ', $tab - $col % $tab);
        });
    }

    /** The column after $s, tabs going to tab stops. */
    public static function columnOf(string $s, int $tab): int
    {
        $col = 0;
        foreach (mb_str_split($s, 1, 'UTF-8') as $c) { $col = $c === "\t" ? $col + $tab - $col % $tab : $col + 1; }
        return $col;
    }

    /** Comments the lines of the selections out with the language's line comment, at the least indentation among them, or uncomments them when all are comments, as Zed does. */
    public function toggleComment(): bool
    {
        $l = $this->language();
        if ($l === null) { return false; }
        if ($l->lineComment === '') {
            if (($l->blockComment[0] ?? '') !== '') { return $this->toggleBlockComment(); }
            return false;
        }
        $r = $this->view->text;
        $prefix = $l->lineComment;
        $edits = [];
        foreach ($this->selLines() as [$from, $to]) {
            $minIndent = -1;
            $all = true;
            for ($line = $from; $line <= $to; $line++) {
                $s = $r->line($line);
                $t = ltrim($s, " \t");
                if ($t === '') { continue; }
                if (!str_starts_with($t, $prefix)) { $all = false; }
                $n = strlen($s) - strlen($t);
                if ($minIndent < 0 || $n < $minIndent) { $minIndent = $n; }
            }
            if ($minIndent < 0) { $minIndent = strlen(Chars::indentOf($r->line($from))); }
            for ($line = $from; $line <= $to; $line++) {
                $s = $r->line($line);
                $a = $r->lineStart($line);
                $t = ltrim($s, " \t");
                if ($all) {
                    if ($t === '') { continue; }
                    $at = $a + strlen($s) - strlen($t);
                    $n = strlen($prefix);
                    if (str_starts_with(substr($t, $n), ' ')) { $n++; }
                    $edits[] = new Edit($at, $at + $n);
                    continue;
                }
                if ($t === '' && $from !== $to) { continue; }
                $at = $a + min($minIndent, strlen($s));
                $edits[] = new Edit($at, $at, $prefix . ' ');
            }
        }
        $sels = self::cloneSels($this->sels);
        return $this->apply($edits, Transaction::OTHER, function (\Closure $move) use ($sels): array {
            foreach ($sels as $s) { $s->anchor = $move($s->anchor, Change::RIGHT); $s->head = $move($s->head, Change::RIGHT); }
            return $sels;
        });
    }

    /** Wraps each selection in the language's block comment, or unwraps it. */
    public function toggleBlockComment(): bool
    {
        $l = $this->language();
        if ($l === null || ($l->blockComment[0] ?? '') === '') { return false; }
        [$open, $close] = $l->blockComment;
        $r = $this->view->text;
        $edits = [];
        foreach ($this->sels as $s) {
            $a = $s->start(); $z = $s->end();
            if ($s->empty()) {
                $line = $r->lineOf($a);
                $a = $r->lineStart($line) + strlen(Chars::indentOf($r->line($line))); $z = $r->lineEnd($line);
            }
            $t = $r->slice($a, $z);
            if (str_starts_with($t, $open) && str_ends_with($t, $close) && strlen($t) >= strlen($open) + strlen($close)) {
                $ol = strlen($open); $cl = strlen($close);
                if (str_starts_with(substr($t, $ol), ' ') && str_ends_with(substr($t, 0, strlen($t) - $cl), ' ') && strlen($t) >= $ol + $cl + 2) { $ol++; $cl++; }
                $edits[] = new Edit($a, $a + $ol);
                $edits[] = new Edit($z - $cl, $z);
                continue;
            }
            $edits[] = new Edit($a, $a, $open . ' ');
            $edits[] = new Edit($z, $z, ' ' . $close);
        }
        return $this->apply($edits, Transaction::OTHER, null);
    }

    /** Sorts, reverses or dedupes the lines of the selections; a caret alone takes all lines. */
    public function manipulateLines(int $cmd): bool
    {
        $r = $this->view->text;
        $spans = $this->selLines();
        if (count($spans) === 1 && $spans[0][0] === $spans[0][1]) { $spans = [[0, $r->lineCount() - 1]]; }
        $edits = [];
        foreach ($spans as [$from, $to]) {
            $a = $r->lineStart($from); $z = $r->lineEnd($to);
            $lines = explode("\n", $r->slice($a, $z));
            switch ($cmd) {
                case Command::SORT_LINES: sort($lines, SORT_STRING); break;
                case Command::SORT_LINES_CASE_INSENSITIVE: usort($lines, fn(string $x, string $y) => strcmp(mb_strtolower($x, 'UTF-8'), mb_strtolower($y, 'UTF-8'))); break;
                case Command::REVERSE_LINES: $lines = array_reverse($lines); break;
                case Command::UNIQUE_LINES: $lines = array_values(array_unique($lines, SORT_STRING)); break;
            }
            $edits[] = new Edit($a, $z, implode("\n", $lines));
        }
        // Selections select their lines as they come out; carets stay.
        $sels = self::cloneSels($this->sels);
        return $this->apply($edits, Transaction::OTHER, function (\Closure $move) use ($sels, $edits): array {
            $t = $this->buf()->rope();
            foreach ($sels as $s) {
                if ($s->empty()) {
                    $s->collapse(min($s->head, $t->len()));
                    continue;
                }
                foreach ($edits as $e) {
                    if ($s->start() >= $e->start && $s->end() <= $e->end + 1) {
                        $a = $move($e->start, Change::LEFT);
                        $s->anchor = $a; $s->head = $a + strlen($e->text);
                        if ($s->end() > $e->end) { $s->head = min($t->len(), $s->head + 1); }
                        break;
                    }
                }
            }
            return $sels;
        });
    }

    /** Converts the case of each selection, or of the word at a caret. */
    public function convertCase(int $cmd): bool
    {
        $r = $this->view->text;
        $extra = $this->wordChars();
        $sels = self::cloneSels($this->sels);
        foreach ($sels as $s) {
            if ($s->empty()) { [$s->anchor, $s->head] = Chars::wordAt($r, $s->head, $extra); }
        }
        $this->sels = $sels;
        return $this->replaceEach(Transaction::OTHER, true, fn(int $i, Sel $s) => self::convertCaseOf($r->slice($s->start(), $s->end()), $cmd));
    }

    /** $s converted: to upper or lower case, title case, or the naming conventions of identifiers. */
    public static function convertCaseOf(string $s, int $cmd): string
    {
        switch ($cmd) {
            case Command::UPPERCASE: return mb_strtoupper($s, 'UTF-8');
            case Command::LOWERCASE: return mb_strtolower($s, 'UTF-8');
            case Command::TITLE_CASE:
                $prev = ' '; $out = '';
                foreach (mb_str_split($s, 1, 'UTF-8') as $c) {
                    $out .= !View::isWordRune($prev) ? mb_convert_case($c, MB_CASE_TITLE, 'UTF-8') : mb_strtolower($c, 'UTF-8');
                    $prev = $c;
                }
                return $out;
        }
        // Split into words at underscores, hyphens, spaces and case changes.
        $words = []; $cur = [];
        $rs = mb_str_split($s, 1, 'UTF-8');
        foreach ($rs as $i => $c) {
            if ($c === '_' || $c === '-' || Chars::isSpace($c)) {
                if ($cur !== []) { $words[] = implode('', $cur); $cur = []; }
                continue;
            }
            if (Chars::isUpper($c) && $cur !== [] && (Chars::isLower($cur[count($cur) - 1]) || $i + 1 < count($rs) && Chars::isLower($rs[$i + 1]) && Chars::isUpper($cur[count($cur) - 1]))) {
                $words[] = implode('', $cur); $cur = [];
            }
            $cur[] = $c;
        }
        if ($cur !== []) { $words[] = implode('', $cur); }
        $words = array_map(fn(string $w) => mb_strtolower($w, 'UTF-8'), $words);
        $title = fn(string $w) => mb_convert_case(mb_substr($w, 0, 1, 'UTF-8'), MB_CASE_TITLE, 'UTF-8') . mb_substr($w, 1, null, 'UTF-8');
        switch ($cmd) {
            case Command::SNAKE_CASE: return implode('_', $words);
            case Command::KEBAB_CASE: return implode('-', $words);
            case Command::CAMEL_CASE:
                for ($i = 1; $i < count($words); $i++) { $words[$i] = $title($words[$i]); }
                return implode('', $words);
            case Command::PASCAL_CASE:
                return implode('', array_map($title, $words));
        }
        return $s;
    }
}
