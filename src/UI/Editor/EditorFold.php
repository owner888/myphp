<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/**
 * Folding, as Zed falls back to without a syntax tree and VS Code does
 * (mygo's fold.go): a line ending with an opening bracket folds the lines
 * up to the one with its closing bracket, which stays; another folds the
 * lines indented more than it after it, but blank lines at the end.
 */
trait EditorFold
{
    /** The last line folding $line hides, or null when $line starts no fold. */
    public function foldEnd(int $line): ?int
    {
        $f = $this->highlighter();
        if ($f instanceof Folder) { return $f->foldEnd($line); }
        $r = $this->view->text;
        $n = $r->lineCount();
        if ($line >= $n - 1) { return null; }
        $s = $r->line($line);
        if (trim($s) === '') { return null; }
        // An opening bracket at the end of the line.
        if (($open = $this->trailingOpener($line, $s)) !== null) {
            if (($m = $this->matchBracket($r->lineStart($line) + $open)) !== null) {
                $cl = $r->lineOf($m[1]);
                if ($cl > $line + 1) { return $cl - 1; }
                if ($cl === $line + 1) { return null; }
            }
        }
        $tab = $this->tabSize();
        $in = Chars::indentWidth($s, $tab);
        $end = $line;
        for ($i = $line + 1; $i < $n; $i++) {
            $t = $r->line($i);
            if (trim($t) === '') { continue; }
            if (Chars::indentWidth($t, $tab) <= $in) { break; }
            $end = $i;
        }
        return $end > $line ? $end : null;
    }

    /** Whether $line starts a fold, cheaply, for the gutter: by indentation, or an opening bracket at its end. */
    public function foldable(int $line): bool
    {
        if ($this->view->hl instanceof Folder) { return $this->view->hl->foldEnd($line) !== null; }
        $r = $this->view->text;
        $n = $r->lineCount();
        if ($line >= $n - 1) { return false; }
        $s = $r->line($line);
        if (trim($s) === '') { return false; }
        $tab = $this->tabSize();
        $in = Chars::indentWidth($s, $tab);
        for ($i = $line + 1; $i < min($n, $line + 50); $i++) {
            $t = $r->line($i);
            if (trim($t) === '') { continue; }
            return Chars::indentWidth($t, $tab) > $in;
        }
        return false;
    }

    /** The offset in $s of an opening bracket ending it, before spaces and a comment, or null. */
    public function trailingOpener(int $line, string $s): ?int
    {
        $l = $this->language();
        if ($l === null) { return null; }
        $t = rtrim($s, " \t");
        if ($l->lineComment !== '' && ($i = strrpos($t, $l->lineComment)) !== false) {
            if (self::kindAt($this->view->lineTokens($line, $s), $i) === Token::COMMENT) { $t = rtrim(substr($t, 0, $i), " \t"); }
        }
        if ($t === '') { return null; }
        $c = $t[strlen($t) - 1];
        foreach ($l->brackets as $p) {
            if (strlen($p->open) === 1 && $p->open === $c && $c !== '<') { return strlen($t) - 1; }
        }
        return null;
    }

    /** The kind of the token holding byte $i of a line, its tokens $toks (null: all code). @param ?list<array{0: int, 1: int}> $toks */
    public static function kindAt(?array $toks, int $i): int
    {
        foreach ($toks ?? [] as [$end, $kind]) { if ($i < $end) { return $kind; } }
        return Token::TEXT;
    }

    /** Whether a fold starts at $line. */
    public function isFolded(int $line): bool
    {
        foreach ($this->folds as $f) { if ($f[0] === $line) { return true; } }
        return false;
    }

    /** Folds the innermost fold holding the line of $off. */
    public function foldAt(int $off): bool
    {
        $line = $this->view->text->lineOf($off);
        for ($l = $line; $l >= 0 && $line - $l < 10000; $l--) {
            if ($this->isFolded($l) && $l !== $line) { continue; }
            $end = $this->foldEnd($l);
            if ($end !== null && $end >= $line && !$this->isFolded($l)) {
                $this->folds[] = [$l, $end];
                $this->afterFold($l);
                return true;
            }
        }
        return false;
    }

    /** Moves carets out of what a fold hides. */
    public function afterFold(int $line): void
    {
        $r = $this->view->text;
        $this->needsBuild = true;
        $h = self::hiddenHeights($this->hiddenSpans());
        $sels = self::cloneSels($this->sels);
        $moved = false;
        foreach ($sels as $s) {
            if ($h->isHidden($r->lineOf($s->head))) {
                $s->collapse($r->lineEnd($line));
                $moved = true;
            }
        }
        if ($moved) { $this->sels = Sel::merge($sels); }
    }

    /** Unfolds the folds starting at the line of $off or holding it, and those inside them when $recursive. */
    public function unfoldAt(int $off, bool $recursive): bool
    {
        $line = $this->view->text->lineOf($off);
        $before = count($this->folds);
        $outer = [0, 0];
        foreach ($this->folds as $f) {
            if (($f[0] === $line || $line > $f[0] && $line <= $f[1]) && $f[1] - $f[0] > $outer[1] - $outer[0]) { $outer = $f; }
        }
        $this->folds = array_values(array_filter($this->folds, function (array $f) use ($line, $recursive, $outer): bool {
            if ($f[0] === $line || $line > $f[0] && $line <= $f[1]) { return false; }
            return !($recursive && $outer[1] > $outer[0] && $f[0] >= $outer[0] && $f[1] <= $outer[1]);
        }));
        $this->needsBuild = true;
        return count($this->folds) !== $before;
    }

    /** Every fold of the text, with how deep each is. @return array{0: list<array{0: int, 1: int}>, 1: list<int>} */
    public function foldRanges(): array
    {
        $r = $this->view->text;
        $stack = []; $folds = []; $depths = [];
        for ($line = 0; $line < $r->lineCount() - 1; $line++) {
            while ($stack !== [] && $stack[count($stack) - 1][1] < $line) { array_pop($stack); }
            if (!$this->foldable($line) && $this->trailingOpener($line, $r->line($line)) === null) { continue; }
            if (($end = $this->foldEnd($line)) !== null) {
                $f = [$line, $end];
                $folds[] = $f;
                $depths[] = count($stack) + 1;
                $stack[] = $f;
            }
        }
        return [$folds, $depths];
    }

    /** Folds the folds at a depth, all of them for 0. */
    public function foldLevel(int $level): bool
    {
        [$folds, $depths] = $this->foldRanges();
        $this->folds = [];
        foreach ($folds as $i => $f) {
            if ($level === 0 || $depths[$i] === $level) { $this->folds[] = $f; }
        }
        $this->needsBuild = true;
        $this->moveOutOfFolds();
        return true;
    }

    /** Folds the fold at $off and those inside it. */
    public function foldRecursive(int $off): bool
    {
        if (!$this->foldAt($off)) { return false; }
        $outer = $this->folds[count($this->folds) - 1];
        [$folds] = $this->foldRanges();
        foreach ($folds as $f) {
            if ($f[0] > $outer[0] && $f[1] <= $outer[1] && !$this->isFolded($f[0])) { $this->folds[] = $f; }
        }
        return true;
    }

    /** Puts carets in folded lines at the end of the line folding them. */
    public function moveOutOfFolds(): void
    {
        $r = $this->view->text;
        $h = self::hiddenHeights($this->hiddenSpans());
        $sels = self::cloneSels($this->sels);
        foreach ($sels as $s) {
            $l = $r->lineOf($s->head);
            while ($l > 0 && $h->isHidden($l)) { $l--; }
            if ($l !== $r->lineOf($s->head)) { $s->collapse($r->lineEnd($l)); }
        }
        $this->sels = Sel::merge($sels);
    }

    /** Folds or unfolds the fold starting at $line, as the gutter's marker does. */
    public function toggleFoldLine(int $line): void
    {
        if ($this->isFolded($line)) {
            $this->folds = array_values(array_filter($this->folds, fn(array $f) => $f[0] !== $line));
            $this->needsBuild = true;
            return;
        }
        if (($end = $this->foldEnd($line)) !== null) {
            $this->folds[] = [$line, $end];
            $this->moveOutOfFolds();
            $this->needsBuild = true;
        }
    }
}
