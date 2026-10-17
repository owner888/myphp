<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/**
 * Markdown (mygo's markdownLexer): headings, emphasis, code spans and
 * blocks, links, lists and quotes, and the code of fenced blocks with
 * their language. Its state packs the state of a fenced block's language
 * (bits 0-39), whether a fenced block is open (bit 40) and its fence
 * (bit 41 tildes; bits 42-45 its length), the block's language (bits
 * 46-53, its index in Languages plus one), and whether an HTML comment is
 * open (bit 54).
 */
final class MarkdownLexer implements Tokenizer
{
    private const FENCE = 1 << 40, TILDE = 1 << 41, COMMENT = 1 << 54, INNER = (1 << 40) - 1;

    public function tokenize(string $line, int $state, array $tokens): array
    {
        $out = $tokens;
        $n = strlen($line);
        $trimmed = ltrim($line, ' ');
        $indent = $n - strlen($trimmed);
        if ($state & self::COMMENT) {
            $end = strpos($line, '-->');
            if ($end === false) { Token::add($out, $n, Token::COMMENT); return [$out, $state]; }
            Token::add($out, $end + 3, Token::COMMENT);
            $state &= ~self::COMMENT;
            self::inline($line, $end + 3, $out);
            return [$out, $state];
        }
        if ($state & self::FENCE) {
            $ch = $state & self::TILDE ? '~' : '`';
            $length = ($state >> 42) & 0xf;
            $run = 0;
            while ($run < strlen($trimmed) && $trimmed[$run] === $ch) { $run++; }
            if ($indent < 4 && $run >= $length && trim(substr($trimmed, $run)) === '') {
                Token::add($out, $n, Token::PUNCTUATION);
                return [$out, 0];
            }
            $li = ($state >> 46) & 0xff;
            $langs = Languages::all();
            if ($li > 0 && $li <= count($langs) && $langs[$li - 1]->tokenizer !== null) {
                [$out, $inner] = $langs[$li - 1]->tokenizer->tokenize($line, $state & self::INNER, $out);
                if ($inner > self::INNER) { $inner = 0; }
                Token::add($out, $n, Token::TEXT);
                return [$out, ($state & ~self::INNER) | $inner];
            }
            Token::add($out, $n, Token::CODE);
            return [$out, $state];
        }
        if ($indent < 4 && (str_starts_with($trimmed, '```') || str_starts_with($trimmed, '~~~'))) {
            $ch = $trimmed[0];
            $run = 0;
            while ($run < strlen($trimmed) && $trimmed[$run] === $ch) { $run++; }
            $info = trim(substr($trimmed, $run));
            if ($ch === '`' && str_contains($info, '`')) {
                self::inline($line, 0, $out);
                return [$out, $state];
            }
            Token::add($out, $indent + $run, Token::PUNCTUATION);
            Token::add($out, $n, Token::TYPE);
            $next = self::FENCE | (min($run, 15) << 42);
            if ($ch === '~') { $next |= self::TILDE; }
            $name = $info;
            if (($sp = strpbrk($name, " \t{")) !== false) { $name = substr($name, 0, strlen($name) - strlen($sp)); }
            $l = Languages::named($name);
            if ($l !== null) {
                foreach (Languages::all() as $k => $x) { if ($x === $l) { $next |= ($k + 1) << 46; } }
            }
            return [$out, $next];
        }
        if ($indent < 4 && str_starts_with($trimmed, '#')) {
            $h = 0;
            while ($h < strlen($trimmed) && $trimmed[$h] === '#') { $h++; }
            if ($h <= 6 && ($h === strlen($trimmed) || $trimmed[$h] === ' ' || $trimmed[$h] === "\t")) {
                Token::add($out, $n, Token::HEADING);
                return [$out, $state];
            }
        } elseif ($indent < 4 && $trimmed !== '' && trim($trimmed, '=') === '' || $indent < 4 && strlen($trimmed) > 1 && trim($trimmed, '- ') === '' && substr_count($trimmed, '-') >= 2) {
            Token::add($out, $n, Token::HEADING);
            return [$out, $state];
        } elseif ($indent < 4 && strlen($trimmed) >= 3 && (trim($trimmed, '* ') === '' || trim($trimmed, '_ ') === '')) {
            Token::add($out, $n, Token::PUNCTUATION);
            return [$out, $state];
        }
        $i = $indent;
        Token::add($out, $i, Token::TEXT);
        // Quotes and list markers.
        while ($i < $n && $line[$i] === '>') {
            Token::add($out, $i + 1, Token::PUNCTUATION); $i++;
            while ($i < $n && $line[$i] === ' ') { $i++; }
            Token::add($out, $i, Token::TEXT);
        }
        if ($i + 1 < $n && ($line[$i] === '-' || $line[$i] === '*' || $line[$i] === '+') && $line[$i + 1] === ' ') {
            Token::add($out, $i + 1, Token::PUNCTUATION); $i++;
        } else {
            $j = $i;
            while ($j < $n && $j - $i < 9 && ctype_digit($line[$j])) { $j++; }
            if ($j > $i && $j + 1 < $n && ($line[$j] === '.' || $line[$j] === ')') && $line[$j + 1] === ' ') {
                Token::add($out, $j + 1, Token::PUNCTUATION); $i = $j + 1;
            }
        }
        $rest = substr($line, $i);
        $lt = ltrim($rest, ' ');
        if (str_starts_with($lt, '[ ] ') || str_starts_with($lt, '[x] ') || str_starts_with($lt, '[X] ')) {
            $k = $i + strpos($rest, '[');
            Token::add($out, $k, Token::TEXT);
            Token::add($out, $k + 3, Token::KEYWORD);
            $i = $k + 3;
        }
        if (self::inline($line, $i, $out)) { $state |= self::COMMENT; }
        return [$out, $state];
    }

    /** Lexes Markdown's inline syntax from $i, and reports whether an HTML comment stays open at the end of the line. @param list<array{0: int, 1: int}> $out */
    private static function inline(string $line, int $i, array &$out): bool
    {
        $n = strlen($line);
        while ($i < $n) {
            $c = $line[$i];
            if ($c === '\\' && $i + 1 < $n && str_contains('\\`*_{}[]()#+-.!|<>~', $line[$i + 1])) {
                Token::add($out, $i + 2, Token::ESCAPE); $i += 2;
            } elseif ($c === '`') {
                $run = 1;
                while ($i + $run < $n && $line[$i + $run] === '`') { $run++; }
                $end = strpos($line, str_repeat('`', $run), $i + $run);
                if ($end === false) { Token::add($out, $i + $run, Token::TEXT); $i += $run; continue; }
                Token::add($out, $end + $run, Token::CODE); $i = $end + $run;
            } elseif (Lexer::startsWith($line, $i, '<!--')) {
                $end = strpos($line, '-->', $i + 4);
                if ($end === false) { Token::add($out, $n, Token::COMMENT); return true; }
                Token::add($out, $end + 3, Token::COMMENT); $i = $end + 3;
            } elseif ($c === '<' && $i + 1 < $n && (Lexer::identStart($line, $i + 1, '') || $line[$i + 1] === '/')) {
                $end = strpos($line, '>', $i);
                if ($end === false) { Token::add($out, $i + 1, Token::TEXT); $i++; continue; }
                $inside = substr($line, $i, $end - $i);
                $kind = str_contains($inside, '://') || str_contains($inside, '@') ? Token::LINK : Token::TAG;
                Token::add($out, $end + 1, $kind); $i = $end + 1;
            } elseif ($c === '[' || $c === '!' && $i + 1 < $n && $line[$i + 1] === '[') {
                $start = $i;
                if ($c === '!') { $i++; }
                $close = strpos($line, ']', $i);
                if ($close === false) { Token::add($out, $i + 1, Token::TEXT); $i++; continue; }
                Token::add($out, $start, Token::TEXT);
                Token::add($out, $close + 1, Token::LINK);
                $i = $close + 1;
                if ($i < $n && ($line[$i] === '(' || $line[$i] === '[')) {
                    $q = $line[$i] === '[' ? ']' : ')';
                    $end = strpos($line, $q, $i);
                    if ($end !== false) { Token::add($out, $end + 1, Token::STRING); $i = $end + 1; }
                }
            } elseif ($c === '*' || $c === '_' || $c === '~') {
                $run = 1;
                while ($i + $run < $n && $line[$i + $run] === $c) { $run++; }
                if ($c === '~' && $run < 2 || $i + $run >= $n || $line[$i + $run] === ' ') { Token::add($out, $i + $run, Token::TEXT); $i += $run; continue; }
                if ($c === '_' && $i > 0 && Lexer::isIdentByte($line[$i - 1])) { Token::add($out, $i + $run, Token::TEXT); $i += $run; continue; }
                $end = strpos($line, substr($line, $i, $run), $i + $run);
                if ($end === false || $end - ($i + $run) <= 0 || $line[$end - 1] === ' ') { Token::add($out, $i + $run, Token::TEXT); $i += $run; continue; }
                $kind = $c === '~' ? Token::DELETED : ($run >= 2 ? Token::STRONG : Token::EMPHASIS);
                Token::add($out, $end + $run, $kind); $i = $end + $run;
            } elseif (Lexer::startsWith($line, $i, 'http://') || Lexer::startsWith($line, $i, 'https://')) {
                $j = $i;
                while ($j < $n && $line[$j] !== ' ' && $line[$j] !== ')' && $line[$j] !== '>') { $j++; }
                Token::add($out, $j, Token::LINK); $i = $j;
            } elseif ($c === '|') {
                Token::add($out, $i + 1, Token::PUNCTUATION); $i++;
            } else {
                $j = $i + 1;
                while ($j < $n && !str_contains("\\`<[!*_~h|", $line[$j])) { $j++; }
                Token::add($out, $j, Token::TEXT); $i = $j;
            }
        }
        return false;
    }
}
