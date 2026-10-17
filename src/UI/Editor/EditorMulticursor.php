<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/** Several carets and selections (mygo's multicursor.go). */
trait EditorMulticursor
{
    /** Adds the next occurrence of the newest selection's text to the selections, as Zed's select next: a caret alone selects its word first, and looks for whole words then. */
    public function selectNext(bool $prev): bool
    {
        $r = $this->view->text;
        $p = $this->primary();
        if ($p->empty()) {
            [$a, $z] = Chars::wordAt($r, $p->head, $this->wordChars());
            if ($a === $z) { return false; }
            $sels = self::cloneSels($this->sels);
            foreach ($sels as $s) { if ($s->id === $p->id) { $s->anchor = $a; $s->head = $z; } }
            $this->setSels($sels, self::REVEAL_FIT);
            // The next ones are whole words too, as the first was a word.
            $this->wholeWord = true;
            return true;
        }
        $query = $r->slice($p->start(), $p->end());
        $text = (string)$r;
        $ok = fn(int $i) => !$this->selected($i, $i + strlen($query));
        $at = $prev
            ? self::searchBack($text, $query, $p->start(), $this->wholeWord, $this->wordChars(), $ok)
            : self::searchFwd($text, $query, $p->end(), $this->wholeWord, $this->wordChars(), $ok);
        if ($at < 0) { return false; }
        $whole = $this->wholeWord;
        $this->setSels([...self::cloneSels($this->sels), $this->newSel($at, $at + strlen($query))], self::REVEAL_FIT);
        $this->wholeWord = $whole;
        return true;
    }

    public function selected(int $a, int $b): bool
    {
        foreach ($this->sels as $s) { if ($s->start() < $b && $s->end() > $a) { return true; } }
        return false;
    }

    /** Selects every occurrence of the newest selection's text, or of the word at the caret. */
    public function selectAllMatches(): bool
    {
        $r = $this->view->text;
        $p = $this->primary();
        $whole = $this->wholeWord;
        if ($p->empty()) {
            [$a, $z] = Chars::wordAt($r, $p->head, $this->wordChars());
            if ($a === $z) { return false; }
            $p->anchor = $a; $p->head = $z;
            $whole = true;
        }
        $query = $r->slice($p->start(), $p->end());
        $text = (string)$r;
        $sels = [];
        for ($at = 0; $at <= strlen($text);) {
            $i = strpos($text, $query, $at);
            if ($i === false) { break; }
            if ($i !== $p->start() && (!$whole || self::isWholeWord($text, $i, $i + strlen($query), $this->wordChars()))) {
                $sels[] = $this->newSel($i, $i + strlen($query));
            }
            $at = $i + strlen($query);
        }
        // The original stays the newest.
        $this->nextId++;
        $p->id = $this->nextId;
        $sels[] = $p;
        $this->setSels($sels, self::REVEAL_NONE);
        return true;
    }

    /** The first match of $query at or after $at that $ok takes, going round to the start, or -1. */
    public static function searchFwd(string $text, string $query, int $at, bool $whole, string $extra, ?\Closure $ok): int
    {
        if ($query === '') { return -1; }
        $at = max(0, min($at, strlen($text)));
        // The matches starting from from to limit.
        $find = function (int $from, int $limit) use ($text, $query, $whole, $extra, $ok): int {
            while ($from < $limit) {
                $i = strpos($text, $query, $from);
                if ($i === false || $i >= $limit) { return -1; }
                if ((!$whole || self::isWholeWord($text, $i, $i + strlen($query), $extra)) && ($ok === null || $ok($i))) { return $i; }
                $from = $i + 1;
            }
            return -1;
        };
        if (($i = $find($at, strlen($text))) >= 0) { return $i; }
        return $find(0, $at);
    }

    /** The last match of $query starting before $before that $ok takes, going round to the end, or -1. */
    public static function searchBack(string $text, string $query, int $before, bool $whole, string $extra, ?\Closure $ok): int
    {
        if ($query === '') { return -1; }
        $before = max(0, min($before, strlen($text)));
        // The matches starting from lo to hi, the last first.
        $find = function (int $lo, int $hi) use ($text, $query, $whole, $extra, $ok): int {
            while ($hi > $lo) {
                $i = strrpos(substr($text, 0, min(strlen($text), $hi - 1 + strlen($query))), $query);
                if ($i === false || $i < $lo) { return -1; }
                if ((!$whole || self::isWholeWord($text, $i, $i + strlen($query), $extra)) && ($ok === null || $ok($i))) { return $i; }
                $hi = $i;
            }
            return -1;
        };
        if (($i = $find(0, $before)) >= 0) { return $i; }
        return $find($before, strlen($text));
    }

    /** Whether $text from $a to $b is not inside a word. */
    public static function isWholeWord(string $text, int $a, int $b, string $extra): bool
    {
        if ($a > 0 && Chars::classOf(Chars::runeBefore($text, $a), $extra) === Chars::WORD) { return false; }
        if ($b < strlen($text) && Chars::classOf(Chars::runeAt($text, $b), $extra) === Chars::WORD) { return false; }
        return true;
    }

    /** Adds a caret on the row above (or below) the newest, at the same x, as Zed's add selection above: going back the other way takes the carets added back. */
    public function addCursor(int $dir): bool
    {
        if ($this->addDir === -$dir && $this->addStack !== []) {
            $id = array_pop($this->addStack);
            $sels = array_values(array_filter(self::cloneSels($this->sels), fn(Sel $s) => $s->id !== $id));
            if ($sels !== []) {
                $this->sels = Sel::merge($sels);
                $this->reveal = self::REVEAL_FIT;
                return true;
            }
        }
        if ($this->addStack === []) { $this->addDir = $dir; }
        $p = $this->primary();
        $r = $this->view->text;
        $from = $p->head;
        // From the edge of the selections in the direction.
        foreach ($this->sels as $s) {
            if ($dir < 0 && $s->head < $from || $dir > 0 && $s->head > $from) { $from = $s->head; }
        }
        $line = $r->lineOf($from);
        if ($dir < 0 && $line === 0 || $dir > 0 && $line >= $r->lineCount() - 1) { return false; }
        $goal = $p->goal;
        if ($goal < 0 && ($cr = $this->caretRect($p->head)) !== null) { $goal = $cr[0]; }
        [$to] = $this->verticalMove($from, $goal, $dir);
        $s = $this->newSel($to, $to);
        $s->goal = $goal;
        $this->addStack[] = $s->id;
        $this->sels = Sel::merge([...self::cloneSels($this->sels), $s]);
        $this->reveal = self::REVEAL_FIT;
        return true;
    }

    /**
     * Grows each selection, as Zed's select larger syntax node without a
     * syntax tree: the word, the inside of the string or the brackets
     * around it, the brackets with them, the line, then the text.
     */
    public function selectLarger(): bool
    {
        $sels = self::cloneSels($this->sels);
        $before = [];
        foreach ($sels as $s) {
            $a = $s->start(); $z = $s->end();
            [$na, $nz] = $this->larger($a, $z);
            if ($na < $a || $nz > $z) {
                $before[] = new Sel($s->anchor, $s->head, -1.0, $s->id);
                $s->anchor = $na; $s->head = $nz;
            }
        }
        if ($before === []) { return false; }
        $larged = $this->larged;
        $this->setSels($sels, self::REVEAL_FIT);
        // Select Smaller takes them back.
        $this->larged = [...$larged, ...$before];
        return true;
    }

    /** The range around $a to $z the next step selects: the highlighter's syntax node, when it knows the tree, else the word, the string, the brackets, the line and the text, in turn. @return array{0: int, 1: int} */
    public function larger(int $a, int $z): array
    {
        $ss = $this->highlighter();
        if ($ss instanceof SyntaxSelector && ($n = $ss->enclosing($a, $z)) !== null && $n->start <= $a && $n->end >= $z && $n->end - $n->start > $z - $a) {
            return [$n->start, $n->end];
        }
        $r = $this->view->text;
        if ($a === $z) {
            // The word at the caret, not a run of punctuation.
            [$wa, $wz] = Chars::wordAt($r, $a, $this->wordChars());
            [$c] = $r->runeAt($wa);
            if ($wz > $wa && Chars::classOf($c, $this->wordChars()) === Chars::WORD) { return [$wa, $wz]; }
        }
        // The string around the selection.
        if (($sr = $this->stringAround($a, $z)) !== null) {
            [$sa, $sz] = $sr;
            if ($sa < $a || $sz > $z) { return [$sa, $sz]; }
            if ($sa - 1 >= 0 && ($sa - 1 < $a || $sz + 1 > $z) && $sa > $r->lineStart($r->lineOf($sa))) { return [$sa - 1, $sz + 1]; }
        }
        // The brackets around it: their inside, then them. A selection of a pair grows to the pair around it.
        $pair = $this->bracketsAround($a, $z);
        if ($pair !== null && $pair[0]->start === $a && $pair[1]->end === $z) {
            $pair = $a > 0 ? $this->bracketsAround($a - 1, $z) : null;
        }
        if ($pair !== null) {
            [$open, $close] = $pair;
            if ($open->end <= $a && $close->start >= $z && ($open->end < $a || $close->start > $z)) { return [$open->end, $close->start]; }
            return [$open->start, $close->end];
        }
        $la = $r->lineStart($r->lineOf($a)); $lz = $r->lineEnd($r->lineOf($z));
        if ($la < $a || $lz > $z) { return [$la, $lz]; }
        return [0, $r->len()];
    }

    /** The string token holding $a to $z, inside its quotes, or null. @return ?array{0: int, 1: int} */
    public function stringAround(int $a, int $z): ?array
    {
        $v = $this->view;
        $line = $v->text->lineOf($a);
        if ($v->text->lineOf($z) !== $line) { return null; }
        $l = $v->line($line);
        $from = 0;
        $toks = $l->toks;
        foreach ($toks as $i => [$end, $kind]) {
            if ($kind === Token::STRING || $kind === Token::ESCAPE) {
                // The run of string tokens.
                $runEnd = $end;
                $j = $i;
                while ($j + 1 < count($toks) && ($toks[$j + 1][1] === Token::STRING || $toks[$j + 1][1] === Token::ESCAPE)) {
                    $j++;
                    $runEnd = $toks[$j][0];
                }
                $sa = $l->start + $from; $sz = $l->start + $runEnd;
                if ($sa <= $a && $z <= $sz && $sz - $sa >= 2) { return [$sa + 1, $sz - 1]; }
            }
            $from = $end;
        }
        return null;
    }

    /** Takes back the selections Select Larger grew. */
    public function selectSmaller(): bool
    {
        if ($this->larged === []) { return false; }
        $sels = self::cloneSels($this->sels);
        $changed = false;
        foreach ($sels as $s) {
            for ($j = count($this->larged) - 1; $j >= 0; $j--) {
                if ($this->larged[$j]->id === $s->id) {
                    $s->anchor = $this->larged[$j]->anchor; $s->head = $this->larged[$j]->head;
                    array_splice($this->larged, $j, 1);
                    $changed = true;
                    break;
                }
            }
        }
        if ($changed) {
            $this->sels = Sel::merge($sels);
            $this->reveal = self::REVEAL_FIT;
        }
        return $changed;
    }
}
