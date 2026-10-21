<?php
declare(strict_types=1);

namespace MyPHP\UI\RichText;

/**
 * Writes a Text as Markdown (mygo's richtext markdown.go), for
 * Text::markdown().
 *
 * @internal
 */
final class MarkdownWriter
{
    /** $t as CommonMark with GitHub's extensions (Text::markdown()). */
    public static function write(Text $t): string
    {
        $b = '';
        $paras = iterator_to_array($t->paragraphs(), false);
        $numbers = []; // the items of the numbered lists open, by level
        $prevList = false;
        for ($i = 0, $count = count($paras); $i < $count; $i++) {
            $p = $paras[$i];
            $st = $p->style;
            if ($i > 0 && !($st->list !== ListKind::NoList && $prevList)) {
                $b .= "\n\n";
            } elseif ($i > 0) {
                $b .= "\n";
            }
            $prevList = $st->list !== ListKind::NoList;
            if ($st->list === ListKind::NoList) { $numbers = []; }
            $quote = $st->quote ? '> ' : '';
            if ($st->codeBlock) {
                $fence = '```';
                for ($k = $i; $k < $count && $paras[$k]->style->codeBlock; $k++) {
                    while (str_contains(self::plainOf($paras[$k]), $fence)) { $fence .= '`'; }
                }
                $b .= $quote . $fence . "\n";
                for (; $i < $count && $paras[$i]->style->codeBlock; $i++) {
                    $b .= $quote . self::plainOf($paras[$i]) . "\n";
                }
                $i--;
                $b .= $quote . $fence;
                continue;
            }
            if ($st->heading > 0) {
                $b .= $quote . str_repeat('#', min($st->heading, 6)) . ' ';
            } elseif ($st->list !== ListKind::NoList) {
                $level = $st->indent;
                while (count($numbers) <= $level) { $numbers[] = 0; }
                $numbers = array_slice($numbers, 0, $level + 1);
                $b .= $quote . str_repeat('    ', $level);
                switch ($st->list) {
                    case ListKind::Numbers:
                        $numbers[$level]++;
                        $b .= $numbers[$level] . '. ';
                        break;
                    case ListKind::Tasks:
                        $b .= $st->checked ? '- [x] ' : '- [ ] ';
                        $numbers[$level] = 0;
                        break;
                    default:
                        $b .= '- ';
                        $numbers[$level] = 0;
                }
            } else {
                $b .= $quote;
            }
            if ($st->heading > 0) {
                // A heading's closing #s are not its text: escape them.
                $s = self::runs($p->runs);
                $k = strlen(rtrim($s, '#'));
                if ($k < strlen($s)) { $s = substr($s, 0, $k) . '\\' . substr($s, $k); }
                $b .= $s;
                continue;
            }
            $b .= self::runs($p->runs);
        }
        return $b;
    }

    /** @internal the text of a paragraph */
    public static function plainOf(Paragraph $p): string
    {
        $s = '';
        foreach ($p->runs as $r) { $s .= $r->text; }
        return $s;
    }

    /**
     * The marks around runs of attributes $a, from the outside in: a link
     * (by its URL), bold, italic, strikethrough or underline. Code is a span
     * of each run, fenced as its backticks need.
     *
     * @return list<array{0: string, 1: string}> [kind, url]
     */
    private static function marksOf(Attributes $a): array
    {
        $m = [];
        if ($a->link !== '') { $m[] = ['a', $a->link]; }
        if ($a->bold()) { $m[] = ['b', '']; }
        if ($a->italic) { $m[] = ['i', '']; }
        if ($a->strikethrough) { $m[] = ['s', '']; }
        if ($a->underline) { $m[] = ['u', '']; }
        return $m;
    }

    /** A mark's opening, as HTML when $html: what Markdown cannot open or close where it is. */
    private static function open(array $m, bool $html): string
    {
        return match ($m[0]) {
            'a' => '[',
            'b' => $html ? '<strong>' : '**',
            'i' => $html ? '<em>' : '*',
            's' => $html ? '<del>' : '~~',
            'u' => '<u>',
        };
    }

    private static function close(array $m, bool $html): string
    {
        return match ($m[0]) {
            'a' => '](' . self::url($m[1]) . ')',
            'u' => '</u>',
            default => $html ? '</' . substr(self::open($m, true), 1) : self::open($m, false),
        };
    }

    /**
     * Runs with their marks, opened and closed as they change, whitespace
     * outside them so that they flank words as CommonMark wants. Where a
     * delimiter cannot open or close (bold "foo." right before "bar" is not
     * **foo.**bar), its mark is written as HTML, which Markdown keeps: the
     * runs written are read back to check, and those still read otherwise
     * are written in HTML whole. What would start a block at the start of
     * the paragraph is escaped, as the text of an item or a heading too.
     *
     * @param list<Run> $runs
     */
    private static function runs(array $runs): string
    {
        [$s, $delims] = self::render($runs, []);
        if ($delims !== [] && !self::readsAs($s, $runs)) {
            $html = [];
            foreach ($delims as $d) {
                if (!self::flanks($d, $s)) { $html[$d['key']] = true; }
            }
            if ($html !== []) {
                [$s2] = self::render($runs, $html);
                if (self::readsAs($s2, $runs)) { return $s2; }
            }
            foreach ($delims as $d) { $html[$d['key']] = true; }
            [$s] = self::render($runs, $html);
        }
        return $s;
    }

    /** Whether the delimiters can open and close where they are, as CommonMark's rules of flanking say. */
    private static function flanks(array $d, string $s): bool
    {
        return self::canFlank($s, $d['open'], $d['length'], $d['char'], true) && self::canFlank($s, $d['close'], $d['length'], $d['char'], false);
    }

    private static function canFlank(string $s, int $at, int $n, string $c, bool $left): bool
    {
        $from = $at; $to = $at + $n;
        while ($from > 0 && $s[$from - 1] === $c) { $from--; }
        while ($to < strlen($s) && $s[$to] === $c) { $to++; }
        $before = $from > 0 ? mb_substr(substr($s, 0, $from), -1, 1, 'UTF-8') : ' ';
        $after = $to < strlen($s) ? mb_substr(substr($s, $to, 4), 0, 1, 'UTF-8') : ' ';
        if (!$left) { [$before, $after] = [$after, $before]; }
        // Left-flanking: not before whitespace, and not before punctuation
        // unless after whitespace or punctuation; right-flanking mirrored.
        return !self::isSpace($after) && (!self::isPunct($after) || self::isSpace($before) || self::isPunct($before));
    }

    /** @internal unicode.IsSpace */
    public static function isSpace(string $r): bool
    {
        return $r !== '' && preg_match('/^[\t\n\v\f\r \x{85}\p{Z}]$/u', $r) === 1;
    }

    private static function isPunct(string $r): bool
    {
        return preg_match('/^[\p{P}\p{S}]$/u', $r) === 1;
    }

    /** @internal $s without unicode spaces at its ends */
    public static function trimSpace(string $s): string
    {
        return preg_replace('/^[\t\n\v\f\r \x{85}\p{Z}]+|[\t\n\v\f\r \x{85}\p{Z}]+$/u', '', $s) ?? $s;
    }

    /**
     * Writes runs, the marks of $html in HTML, and returns where the
     * delimiters of the others are.
     *
     * @param list<Run> $runs
     * @param array<string, bool> $html
     * @return array{0: string, 1: list<array{key: string, char: string, open: int, close: int, length: int}>}
     */
    private static function render(array $runs, array $html): array
    {
        $b = '';
        $open = []; // [mark, key, at]
        $delims = [];
        $pending = '';
        $first = true;
        $closeTo = function (int $n) use (&$open, &$delims, &$b, $html): void {
            while (count($open) > $n) {
                [$m, $key, $at] = array_pop($open);
                $h = $html[$key] ?? false;
                $c = self::close($m, $h);
                if (!$h && ($m[0] === 'b' || $m[0] === 'i' || $m[0] === 's')) {
                    $delims[] = ['key' => $key, 'char' => $c[0], 'open' => $at, 'close' => strlen($b), 'length' => strlen($c)];
                }
                $b .= $c;
            }
        };
        foreach ($runs as $i => $r) {
            if (($at = $r->attributes->attachment) !== null) {
                $closeTo(0);
                $b .= $pending;
                $pending = '';
                $b .= '![' . self::escape($at->name) . '](' . self::url($at->url) . ')';
                $first = false;
                continue;
            }
            $core = self::trimSpace($r->text);
            if ($core === '') {
                $pending .= $r->text;
                continue;
            }
            $lead = substr($r->text, 0, strpos($r->text, $core));
            $trail = substr($r->text, strlen($lead) + strlen($core));
            $want = self::marksOf($r->attributes);
            $k = 0;
            while ($k < count($open) && $k < count($want) && $open[$k][0] === $want[$k]) { $k++; }
            $closeTo($k);
            if ($first) {
                // Spaces at the start would make a block of code.
                $lead = strtr($pending . $lead, [' ' => '&#32;', "\t" => '&#9;']);
                $pending = '';
            }
            $b .= $pending . $lead;
            foreach (array_slice($want, $k) as $m) {
                $key = $i . $m[0];
                $open[] = [$m, $key, strlen($b)];
                $b .= self::open($m, $html[$key] ?? false);
            }
            if ($r->attributes->code) {
                $b .= self::codeSpan($core);
            } else {
                $s = self::escape($core);
                if ($first) { $s = self::escapeBlockStart($s); }
                if (str_ends_with($s, '!')) {
                    $s = substr($s, 0, -1) . '\\!'; // not an image with a link after it
                }
                $b .= $s;
            }
            $first = false;
            $pending = $trail;
        }
        $closeTo(0);
        $b .= $pending;
        return [$b, $delims];
    }

    /** Whether $s reads back as $runs, as far as Markdown keeps them: their text, and the marks of all but whitespace. @param list<Run> $runs */
    private static function readsAs(string $s, array $runs): bool
    {
        $got = null;
        foreach (RichText::parseMarkdown($s)->paragraphs() as $p) {
            if ($got !== null) { return false; }
            $got = $p->runs;
        }
        return self::markKey($got ?? []) === self::markKey($runs);
    }

    /**
     * The text of runs with the marks of each character Markdown keeps, but
     * none of whitespace outside code, nor of spaces ending the paragraph,
     * which it does not show.
     *
     * @param list<Run> $runs
     */
    private static function markKey(array $runs): string
    {
        $all = []; $ms = [];
        foreach ($runs as $r) {
            $a = $r->attributes;
            $m = [$a->bold(), $a->italic, $a->strikethrough, $a->underline, $a->code, $a->link,
                $a->attachment === null ? null : [$a->attachment->name, $a->attachment->url]];
            foreach (mb_str_split($r->text, 1, 'UTF-8') as $c) {
                $all[] = $c;
                $ms[] = $m;
            }
        }
        $end = count($all);
        while ($end > 0 && $all[$end - 1] === ' ' && !$ms[$end - 1][4]) { $end--; }
        $b = '';
        for ($i = 0; $i < $end; $i++) {
            $m = $ms[$i];
            if (self::isSpace($all[$i]) && !$m[4]) { $m = []; }
            $b .= $all[$i] . json_encode($m) . "\0";
        }
        return $b;
    }

    /** $s as a code span, fenced by more backticks than any run of them in it. */
    private static function codeSpan(string $s): string
    {
        $longest = 0;
        if (preg_match_all('/`+/', $s, $m)) {
            foreach ($m[0] as $run) { $longest = max($longest, strlen($run)); }
        }
        $fence = str_repeat('`', $longest + 1);
        if (str_starts_with($s, '`') || str_ends_with($s, '`')) { $s = " $s "; }
        return $fence . $s . $fence;
    }

    /** What Markdown would read as marks, escaped. */
    private static function escape(string $s): string
    {
        $b = '';
        $n = strlen($s);
        for ($i = 0; $i < $n; $i++) {
            $c = $s[$i];
            if (str_contains('\\*_`[]<~', $c)) {
                $b .= '\\';
            } elseif ($c === '&' && $i + 1 < $n && ($s[$i + 1] === '#' || preg_match('/^\pL$/u', mb_chr(ord($s[$i + 1]), 'UTF-8')) === 1)) {
                $b .= '\\'; // not the start of an entity, as &amp; or &#38;
            }
            $b .= $c;
        }
        return $b;
    }

    /** The start of a body paragraph that Markdown would read as a heading, a list, a quotation or a rule, escaped. */
    private static function escapeBlockStart(string $s): string
    {
        if ($s !== '' && str_contains('#>-+=', $s[0])) { return '\\' . $s; }
        $i = strspn($s, '0123456789');
        if ($i > 0 && $i < strlen($s) && ($s[$i] === '.' || $s[$i] === ')')) {
            return substr($s, 0, $i) . '\\' . substr($s, $i);
        }
        return $s;
    }

    /** A URL as the destination of a link. */
    private static function url(string $u): string
    {
        if (strpbrk($u, ' ()<>') !== false) {
            return '<' . strtr($u, ['<' => '%3C', '>' => '%3E']) . '>';
        }
        return $u;
    }
}
