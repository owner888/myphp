<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

use MyPHP\L10n;
use MyPHP\UI\TextState;

/**
 * An editor's find bar and its matches, as Zed's buffer search (mygo's
 * finder): a query, case sensitive or not, whole words, a regular
 * expression (PCRE), in the selections or everywhere, and its
 * replacement.
 */
final class Finder
{
    /** Bounds the matches a search finds. */
    public const MAX_MATCHES = 100000;

    public bool $open = false;
    public bool $replacing = false;
    public string $query = '';
    public string $replace = '';
    public bool $caseSens = false, $wholeWord = false, $regex = false, $inSelection = false;
    /** @var ?list<Sel> */
    public ?array $scope = null;
    public bool $focusQuery = false;
    /** The query as the matches were last chosen for. */
    public string $typed = '';
    /** @var list<array{0: int, 1: int}> the matches of the last search */
    public array $matches = [];
    /** @var list<array<int|string, array{0: int, 1: int}>> a regular expression's groups of each match, as found in the text, by number and name */
    public array $subs = [];
    /** @var list<array{0: int, 1: int}> lines edits changed, to search again */
    public array $dirty = [];
    public int $current = 0;
    public bool $stale = false;
    public int $version = 0;
    public string $searched = '';
    /** @var array{0: bool, 1: bool, 2: bool, 3: bool} */
    public array $options = [false, false, false, false];
    public ?string $err = null;
    /** The text lowered, for searching without case, and the version it is of. */
    public string $lower = '';
    public int $lowerVer = 0;
    public bool $scrollTo = false;
    /** The fields of the bar's query and replacement, which the texts follow (FindBar). */
    public TextState $queryField, $replaceField;

    public function __construct()
    {
        $this->queryField = new TextState;
        $this->replaceField = new TextState;
    }

    public function show(Editor $ed, bool $replace): void
    {
        $this->open = true;
        $this->replacing = $this->replacing || $replace;
        if ($replace) { $this->replacing = true; }
        // The selection, or the word at the caret, seeds the query.
        $p = $ed->primary();
        $t = $ed->view->text;
        if (!$p->empty() && $t->lineOf($p->start()) === $t->lineOf($p->end())) {
            $this->query = $ed->slice($p->start(), $p->end());
        } elseif ($p->empty()) {
            [$a, $z] = Chars::wordAt($t, $p->head, $ed->wordChars());
            if ($z > $a && $this->query === '') { $this->query = $ed->slice($a, $z); }
        }
        if ($this->inSelection) { $this->scope = null; }
        $this->focusQuery = true;
        $this->stale = true;
        $this->scrollTo = true;
        $ed->needsBuild = true;
    }

    /** Searches again when the text, the query or the options changed, and selects the match at or after the caret as the query is typed, as Zed's search does. */
    public function update(Editor $ed): void
    {
        if ($this->query !== $this->typed) {
            $this->typed = $this->query;
            $this->scrollTo = true;
        }
        $this->search($ed);
        if (!$this->scrollTo || $this->matches === []) { return; }
        $this->scrollTo = false;
        $this->current = $this->nearest($ed->primary()->start());
        $m = $this->matches[$this->current];
        // In the middle of the view when out of it, either way, and out of a fold.
        $ed->setSels([$ed->newSel($m[0], $m[1])], Editor::REVEAL_CENTER);
        if ($ed->o->sharedScroll === null) { $ed->doReveal(); }
    }

    /** Finds the matches of the query in the text again when either changed: after edits, a plain query only in the lines they changed, the others' matches having moved with them (moved). */
    public function search(Editor $ed): void
    {
        $opts = [$this->caseSens, $this->wholeWord, $this->regex, $this->inSelection];
        if (!$this->stale && $this->version === $ed->view->version && $this->searched === $this->query && $this->options === $opts) {
            if ($this->dirty !== []) { $this->searchLines($ed); }
            return;
        }
        if ($opts[3] !== $this->options[3]) {
            // Finding in the selection takes the selections there are now.
            $this->scope = null;
        }
        $this->stale = false; $this->dirty = [];
        $this->version = $ed->view->version; $this->searched = $this->query; $this->options = $opts;
        $this->matches = []; $this->subs = [];
        $this->err = null;
        if ($this->query === '') { return; }
        if ($this->inSelection && $this->scope === null) {
            $this->scope = [];
            foreach ($ed->sels as $s) { if (!$s->empty()) { $this->scope[] = clone $s; } }
        }
        $text = (string)$ed->view->text;
        if (!$this->regex && !$this->caseSens && self::isASCII($this->query)) {
            if ($this->lowerVer !== $ed->view->version || $this->lower === '' && $text !== '') {
                $this->lower = self::asciiLower($text); $this->lowerVer = $ed->view->version;
            }
        }
        $this->scan($ed, $text, $this->lower, 0, function (array $m, ?array $sub): bool {
            $this->matches[] = $m;
            if ($sub !== null) { $this->subs[] = $sub; }
            return count($this->matches) < self::MAX_MATCHES;
        });
        $this->current = min($this->current, max(0, count($this->matches) - 1));
    }

    /** The PCRE pattern of the query as a regular expression, matching lines at ^ and $, and case as the options say. */
    private function pattern(): string
    {
        return "\x01" . $this->query . "\x01mu" . ($this->caseSens ? '' : 'i');
    }

    /**
     * Finds the query in $text, which starts at byte $base of the editor's
     * text ($lower its lowered ASCII, when known), and calls $found with
     * each match it keeps, and a regular expression's groups, until $found
     * returns false.
     */
    public function scan(Editor $ed, string $text, string $lower, int $base, \Closure $found): void
    {
        $extra = $ed->wordChars();
        $keep = function (int $a, int $b) use ($text, $extra, $base): bool {
            if ($this->wholeWord && !Editor::isWholeWord($text, $a, $b, $extra)) { return false; }
            if ($this->inSelection && $this->scope !== null && $this->scope !== []) {
                foreach ($this->scope as $s) {
                    if ($base + $a >= $s->start() && $base + $b <= $s->end()) { return true; }
                }
                return false;
            }
            return true;
        };
        // PCRE matches no UTF-8 text with a byte of none in it, which Go's regexp reads as U+FFFD: a byte such as an edit splitting a character leaves.
        $utf8 = mb_check_encoding($text, 'UTF-8') ? $text : self::validUTF8($text);
        if ($this->regex) {
            $n = @preg_match_all($this->pattern(), $utf8, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL);
            if ($n === false) {
                $this->err = error_get_last()['message'] ?? preg_last_error_msg();
                return;
            }
            foreach (array_slice($all, 0, self::MAX_MATCHES) as $m) {
                $a = $m[0][1]; $b = $a + strlen($m[0][0]);
                if ($b === $a || !$keep($a, $b)) { continue; }
                $sub = [];
                foreach ($m as $k => [$s, $at]) { $sub[$k] = $s === null ? [-1, -1] : [$at, $at + strlen($s)]; }
                if (!$found([$base + $a, $base + $b], $sub)) { return; }
            }
            return;
        }
        if ($this->caseSens || self::isASCII($this->query)) {
            $q = $this->query; $in = $text;
            if (!$this->caseSens) {
                if ($lower === '') { $lower = self::asciiLower($text); }
                $q = self::asciiLower($this->query); $in = $lower;
            }
            for ($at = 0;;) {
                $i = strpos($in, $q, $at);
                if ($i === false) { return; }
                $a = $i; $b = $i + strlen($q);
                if ($keep($a, $b) && !$found([$base + $a, $base + $b], null)) { return; }
                $at = $b;
            }
        }
        preg_match_all('/' . preg_quote($this->query, '/') . '/iu', $utf8, $all, PREG_OFFSET_CAPTURE);
        foreach (array_slice($all[0], 0, self::MAX_MATCHES) as [$s, $a]) {
            $b = $a + strlen($s);
            if ($keep($a, $b) && !$found([$base + $a, $base + $b], null)) { return; }
        }
    }

    /**
     * Follows edits of the text the matches were found in: those an edit
     * touches go, the others move with the text, and the lines it changed
     * are searched again (searchLines). A regular expression, whose matches
     * can span lines, or a query of several lines, searches again.
     * @param list<Change> $cs
     */
    public function moved(array $cs): void
    {
        if ($this->stale || $this->regex || $this->query === '' || str_contains($this->query, "\n")) {
            $this->stale = true;
            return;
        }
        $out = [];
        foreach ($this->matches as $m) {
            $touched = false;
            foreach ($cs as $c) {
                if ($m[0] <= $c->oldEnd && $m[1] >= $c->oldStart) { $touched = true; break; }
            }
            if (!$touched) { $out[] = [Change::mapOffset($m[0], $cs, Change::RIGHT), Change::mapOffset($m[1], $cs, Change::LEFT)]; }
        }
        $this->matches = $out;
        foreach ($this->dirty as $i => $d) { $this->dirty[$i] = [Change::mapLine($d[0], $cs), Change::mapLine($d[1], $cs)]; }
        foreach ($cs as $c) { $this->dirty[] = [$c->newLine, $c->newEndLine]; }
    }

    /** Searches the lines edits changed again, as moved left them. */
    private function searchLines(Editor $ed): void
    {
        $r = $ed->view->text;
        $n = $r->lineCount();
        $spans = Editor::mergeSpans($this->dirty);
        $this->dirty = [];
        foreach ($spans as [$sf, $st]) {
            $from = min($sf, $n - 1); $to = min($st, $n - 1);
            $a = $r->lineStart($from); $z = $r->lineEnd($to);
            $this->matches = array_values(array_filter($this->matches, fn(array $m) => !($m[0] >= $a && $m[0] <= $z)));
            $i = self::lowerBound(count($this->matches), fn(int $k) => $this->matches[$k][0] >= $a);
            $found = [];
            $this->scan($ed, $r->slice($a, $z), '', $a, function (array $m) use (&$found): bool { $found[] = $m; return true; });
            array_splice($this->matches, $i, 0, $found);
        }
        if (count($this->matches) > self::MAX_MATCHES) { $this->matches = array_slice($this->matches, 0, self::MAX_MATCHES); }
        $this->current = min($this->current, max(0, count($this->matches) - 1));
    }

    /** The first index of 0..$n-1 that $ok takes, all after it taking it too, else $n (Go's sort.Search). */
    public static function lowerBound(int $n, \Closure $ok): int
    {
        $lo = 0; $hi = $n;
        while ($lo < $hi) { $m = ($lo + $hi) >> 1; if ($ok($m)) { $hi = $m; } else { $lo = $m + 1; } }
        return $lo;
    }

    /** $s with each byte that is not of a UTF-8 character as \x1a, a character of one byte, so that offsets in it are those in $s. */
    private static function validUTF8(string $s): string
    {
        return preg_replace_callback('/((?:[\x00-\x7f]|[\xc2-\xdf][\x80-\xbf]|\xe0[\xa0-\xbf][\x80-\xbf]|[\xe1-\xec\xee\xef][\x80-\xbf]{2}|\xed[\x80-\x9f][\x80-\xbf]|\xf0[\x90-\xbf][\x80-\xbf]{2}|[\xf1-\xf3][\x80-\xbf]{3}|\xf4[\x80-\x8f][\x80-\xbf]{2})+)|./s',
            fn(array $m) => ($m[1] ?? '') !== '' ? $m[1] : "\x1a", $s);
    }

    public static function isASCII(string $s): bool { return preg_match('/[\x80-\xff]/', $s) !== 1; }

    /** $s with its ASCII letters lowered, keeping its length. */
    public static function asciiLower(string $s): string { return strtr($s, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz'); }

    /** The match at or after $off, round to the first. */
    public function nearest(int $off): int
    {
        $i = self::lowerBound(count($this->matches), fn(int $k) => $this->matches[$k][0] >= $off);
        return $i === count($this->matches) ? 0 : $i;
    }

    /** Selects the next match (or previous) after the newest caret. */
    public function step(Editor $ed, int $dir): bool
    {
        $this->search($ed);
        if ($this->matches === []) { return false; }
        $p = $ed->primary();
        if ($dir > 0) {
            $i = $this->nearest($p->end());
            if ($this->matches[$i][0] === $p->start() && $this->matches[$i][1] === $p->end()) { $i = ($i + 1) % count($this->matches); }
        } else {
            $i = $this->nearest($p->start()) - 1;
            if ($i < 0) { $i = count($this->matches) - 1; }
        }
        $this->current = $i;
        $m = $this->matches[$i];
        $ed->setSels([$ed->newSel($m[0], $m[1])], Editor::REVEAL_CENTER);
        return true;
    }

    /** Replaces the current match when selected, and goes to the next. */
    public function replaceOne(Editor $ed): void
    {
        $this->search($ed);
        $p = $ed->primary();
        $text = $this->textForReplacing($ed);
        foreach ($this->matches as $i => $m) {
            if ($m[0] === $p->start() && $m[1] === $p->end()) {
                $with = $this->replacement($text, $i);
                $ed->apply([new Edit($m[0], $m[1], $with)], Transaction::OTHER, function (\Closure $move) use ($ed, $m, $with): array {
                    $at = $move($m[0], Change::LEFT) + strlen($with);
                    return [$ed->newSel($at, $at)];
                });
                $this->search($ed);
                $this->current = $i;
                $this->step($ed, 1);
                return;
            }
        }
        $this->step($ed, 1);
    }

    /** Replaces every match, as one step of undo. */
    public function replaceAll(Editor $ed): void
    {
        $this->search($ed);
        if ($this->matches === []) { return; }
        $edits = [];
        $text = $this->textForReplacing($ed);
        foreach ($this->matches as $i => $m) { $edits[] = new Edit($m[0], $m[1], $this->replacement($text, $i)); }
        $ed->apply($edits, Transaction::OTHER, null);
    }

    /** The text a regular expression's replacements expand their groups from, nothing else needing it. */
    private function textForReplacing(Editor $ed): string
    {
        if (!$this->regex || $this->subs === []) { return ''; }
        return (string)$ed->view->text;
    }

    /**
     * The replacement of match $i: the replace text, with $1 and ${name}
     * expanded for a regular expression, from the groups found in the
     * whole text, as \b and ^ need the text around a match.
     */
    public function replacement(string $text, int $i): string
    {
        if (!$this->regex || $i >= count($this->subs)) { return $this->replace; }
        return self::expand($this->replace, $text, $this->subs[$i]);
    }

    /**
     * $template with its variables expanded from groups $sub of $text, as
     * Go's Regexp.Expand: $name or ${name}, name the longest run of
     * letters, digits and _ ($1x is ${1x}, not ${1}x), a number naming a
     * group by its index, a group that is not one or did not match
     * nothing; $$ is a $.
     * @param array<int|string, array{0: int, 1: int}> $sub
     */
    public static function expand(string $template, string $text, array $sub): string
    {
        $out = '';
        for ($i = 0, $n = strlen($template); $i < $n;) {
            $j = strpos($template, '$', $i);
            if ($j === false) { $out .= substr($template, $i); break; }
            $out .= substr($template, $i, $j - $i);
            $rest = substr($template, $j + 1);
            if (str_starts_with($rest, '$')) { $out .= '$'; $i = $j + 2; continue; }
            if (preg_match('/^\{([\p{L}\p{Nd}_]+)\}/u', $rest, $m) === 1 || preg_match('/^([\p{L}\p{Nd}_]+)/u', $rest, $m) === 1) {
                $name = $m[1];
                $key = ctype_digit($name) ? (int)$name : $name;
                if (isset($sub[$key]) && $sub[$key][0] >= 0) { $out .= substr($text, $sub[$key][0], $sub[$key][1] - $sub[$key][0]); }
                $i = $j + 1 + strlen($m[0]);
                continue;
            }
            // A malformed variable: the $ stays.
            $out .= '$';
            $i = $j + 1;
        }
        return $out;
    }

    /** What the bar says of the matches, in the language of $tx. */
    public function status(L10n $tx): string
    {
        return match (true) {
            $this->err !== null => $tx->get('invalid'),
            $this->query === '' => '',
            $this->matches === [] => $tx->get('noResults'),
            count($this->matches) >= self::MAX_MATCHES => $tx->get('matchesMore', ['current' => $this->current + 1, 'total' => count($this->matches)]),
            default => $tx->get('matches', ['current' => $this->current + 1, 'total' => count($this->matches)]),
        };
    }

    /** Selects every match, as alt-enter in Zed's search bar. */
    public function selectAll(Editor $ed): void
    {
        $this->search($ed);
        if ($this->matches === []) { return; }
        $sels = [];
        foreach ($this->matches as $m) { $sels[] = $ed->newSel($m[0], $m[1]); }
        $ed->setSels($sels, Editor::REVEAL_FIT);
        $this->open = false;
        $ed->refocus = true;
    }
}
