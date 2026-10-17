<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/** Bracket matching, outside strings and comments by the tokens of the lines, within a bounded scan as Zed bounds its queries (mygo's brackets.go). */
trait EditorBrackets
{
    /** How many lines a scan for a bracket reads at most. */
    private static int $bracketScanLines = 1000;

    /** The language's brackets of one byte each: openers and closers. @return array{0: string, 1: string} */
    public function bracketPairs(): array
    {
        $l = $this->language();
        if ($l === null) { return ['([{', ')]}']; }
        $opens = ''; $closes = '';
        foreach ($l->brackets as $p) {
            if (strlen($p->open) === 1 && strlen($p->close) === 1 && $p->open !== '<') { $opens .= $p->open; $closes .= $p->close; }
        }
        return [$opens, $closes];
    }

    public static function bracketToken(int $k): bool
    {
        return $k !== Token::STRING && $k !== Token::COMMENT && $k !== Token::REGEXP && $k !== Token::ESCAPE && $k !== Token::CODE;
    }

    /** The bracket matching the one at $off: [open, close], or null. @return ?array{0: int, 1: int} */
    public function matchBracket(int $off): ?array
    {
        $r = $this->view->text;
        [$opens, $closes] = $this->bracketPairs();
        $c = chr($r->byteAt($off));
        for ($i = 0; $i < strlen($opens); $i++) {
            if ($opens[$i] === $c) {
                $z = $this->scanBracket($off + 1, 1, $opens[$i], $closes[$i]);
                return $z === null ? null : [$off, $z];
            }
            if ($closes[$i] === $c) {
                $a = $this->scanBracket($off - 1, -1, $closes[$i], $opens[$i]);
                return $a === null ? null : [$a, $off];
            }
        }
        return null;
    }

    /** Looks from $off in $dir for the unmatched $want, counting nested pairs of $same and $want, skipping strings and comments; null when none is. */
    public function scanBracket(int $off, int $dir, string $same, string $want): ?int
    {
        $r = $this->view->text;
        if ($off < 0 || $off >= $r->len()) { return null; }
        $line = $r->lineOf($off);
        $depth = 0;
        $found = -1;
        $visit = function (int $i, int $a, string $s) use ($off, $dir, $same, $want, &$depth, &$found): bool {
            $toks = $this->view->lineTokens($i, $s);
            if ($dir > 0) {
                $at = $off >= $a && $off <= $a + strlen($s) ? $off - $a : 0;
            } else {
                $at = $off >= $a && $off < $a + strlen($s) ? $off - $a : strlen($s) - 1;
            }
            for ($n = strlen($s); $at >= 0 && $at < $n; $at += $dir) {
                $c = $s[$at];
                if (($c === $same || $c === $want) && self::bracketToken(self::kindAt($toks, $at))) {
                    if ($c === $same) {
                        $depth++;
                    } elseif ($depth === 0) {
                        $found = $a + $at;
                        return false;
                    } else {
                        $depth--;
                    }
                }
            }
            return true;
        };
        if ($dir > 0) {
            $r->eachLine($line, $line + self::$bracketScanLines, $visit);
        } else {
            $r->eachLineBack($line, $line - self::$bracketScanLines, $visit);
        }
        return $found >= 0 ? $found : null;
    }

    /** The innermost pair of brackets around $off, the ranges of the opening and the closing one, as Zed highlights. @return ?array{0: Range, 1: Range} */
    public function enclosingBrackets(int $off): ?array
    {
        $c = $this->brackets;
        if ($c !== null && $c[0] === $this->view->version && $c[1] === $this->view->hlGen && $c[2] === $off) { return $c[3]; }
        $pair = $this->bracketsAround($off, $off);
        $this->brackets = [$this->view->version, $this->view->hlGen, $off, $pair];
        return $pair;
    }

    /** The innermost pair of brackets around $a to $z, or touching them, as BracketMatcher says: the highlighter's, when it matches brackets, else those the tokens show outside strings and comments. @return ?array{0: Range, 1: Range} */
    public function bracketsAround(int $a, int $z): ?array
    {
        $bm = $this->highlighter();
        if ($bm instanceof BracketMatcher) { return $bm->enclosingBrackets($a, $z); }
        $p = $this->enclosingBracketsOf($a, $z);
        return $p === null ? null : [new Range($p[0], $p[0] + 1), new Range($p[1], $p[1] + 1)];
    }

    /** The buffer's highlighter, up to date, or null. */
    public function highlighter(): ?Highlighter
    {
        return $this->buf()?->highlighter();
    }

    /**
     * The innermost pair of brackets around $a to $z, by the tokens: the
     * pair a bracket just before $z closes, or one at $a opens, when it
     * holds them, else the opening bracket before $a and the closing one
     * at or after $z. @return ?array{0: int, 1: int}
     */
    public function enclosingBracketsOf(int $a, int $z): ?array
    {
        $r = $this->view->text;
        [$opens, $closes] = $this->bracketPairs();
        $best = null;
        if ($z > 0 && strpos($closes, chr($r->byteAt($z - 1))) !== false && self::bracketToken($this->tokenAt($z))) {
            if (($m = $this->matchBracket($z - 1)) !== null && $m[0] <= $a) { $best = $m; }
        }
        if ($a < $r->len() && strpos($opens, chr($r->byteAt($a))) !== false && self::bracketToken($this->tokenAt($a + 1))) {
            if (($m = $this->matchBracket($a)) !== null && $m[1] >= $z - 1 && ($best === null || $m[1] - $m[0] < $best[1] - $best[0])) { $best = $m; }
        }
        if ($best !== null) { return $best; }
        $from = $a - 1;
        for ($tries = 0; $tries < 64 && $from >= 0; $tries++) {
            $u = $this->unmatchedOpener($from, $opens, $closes);
            if ($u === null) { return null; }
            [$open, $which] = $u;
            $close = $this->scanBracket($open + 1, 1, $opens[$which], $closes[$which]);
            if ($close !== null && $close >= $z) { return [$open, $close]; }
            $from = $open - 1;
        }
        return null;
    }

    /** Scans back from $off for an opening bracket the closing ones after it do not close, in one pass for every kind of bracket: [offset, which], or null. @return ?array{0: int, 1: int} */
    public function unmatchedOpener(int $off, string $opens, string $closes): ?array
    {
        $r = $this->view->text;
        if ($off < 0) { return null; }
        $stack = [];
        $at = -1; $which = -1;
        $line = $r->lineOf(min($off, $r->len()));
        $r->eachLineBack($line, $line - self::$bracketScanLines, function (int $i, int $a, string $s) use ($off, $opens, $closes, &$stack, &$at, &$which): bool {
            // Brackets are rare: look at the tokens only for lines with some.
            if ($opens . $closes === '' || strpbrk($s, $opens . $closes) === false) { return true; }
            $toks = $this->view->lineTokens($i, $s) ?? [];
            $k = $off >= $a && $off < $a + strlen($s) ? $off - $a : strlen($s) - 1;
            $ti = count($toks) - 1;
            for (; $k >= 0; $k--) {
                $c = $s[$k];
                $j = strpos($opens, $c);
                $cj = strpos($closes, $c);
                if ($j === false && $cj === false) { continue; }
                while ($ti > 0 && $toks[$ti - 1][0] > $k) { $ti--; }
                if ($ti >= 0 && $ti < count($toks) && !self::bracketToken($toks[$ti][1])) { continue; }
                if ($cj !== false) {
                    $stack[] = $c;
                    continue;
                }
                if ($stack !== []) {
                    if ($stack[count($stack) - 1] === $closes[$j]) { array_pop($stack); }
                    continue;
                }
                $at = $a + $k; $which = $j;
                return false;
            }
            return true;
        });
        return $at >= 0 ? [$at, $which] : null;
    }
}
