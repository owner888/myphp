<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/**
 * A tokenizer of the languages most code is in (mygo's lexer):
 * identifiers and keywords, numbers, strings with escapes and
 * interpolations, line and block comments, operators and punctuation,
 * configured per language. Its state, which fits in 40 bits so that
 * Markdown can hold it for its code blocks, packs:
 *
 *   bits 0-7    the mode: 0 code, 1+k in block comment k, 32+j in string j
 *   bits 8-15   the depth of nested block comments
 *   bits 16-39  up to three strings an interpolation is inside, 8 bits
 *               each: the string's index plus one, and the braces open in
 *               the interpolation
 */
class Lexer implements Tokenizer
{
    public const MODE_COMMENT = 1, MODE_STRING = 32;

    /**
     * @param list<string> $lineComments
     * @param list<array{0: string, 1: string}> $blockComments
     * @param list<LexString> $strings
     * @param array<string, int> $words keywords and their kinds (words())
     * @param bool $foldCase looks keywords up in lower case, as SQL's
     * @param string $identChars characters other than letters, digits and _ in identifiers, as $ in JavaScript
     * @param bool $capitalTypes makes capitalized identifiers types
     * @param bool $calls makes identifiers before ( functions
     * @param bool $keys makes identifiers and strings before : properties, as JSON's
     * @param string $decorator starts annotations, as @ in Java and Python; '' for none
     * @param bool $directives makes # at the start of a line a preprocessor line, as C's
     * @param bool $regexps lexes /.../ where an expression may start, as JavaScript
     * @param bool $variables makes $name a variable, as in shells and PHP
     */
    public function __construct(
        public readonly array $lineComments = [], public readonly array $blockComments = [], public readonly bool $nestComments = false,
        public readonly array $strings = [], public readonly array $words = [], public readonly bool $foldCase = false, public readonly string $identChars = '',
        public readonly bool $capitalTypes = false, public readonly bool $calls = false, public readonly bool $keys = false, public readonly string $decorator = '',
        public readonly bool $directives = false, public readonly bool $regexps = false, public readonly bool $variables = false, public readonly string $operators = '',
    ) {}

    /** A table of keywords: each string lists words of the kind (Token constant) before it. @return array<string, int> */
    public static function words(int|string ...$kinds): array
    {
        $m = []; $k = Token::TEXT;
        foreach ($kinds as $v) {
            if (is_int($v)) { $k = $v; continue; }
            foreach (preg_split('/\s+/', trim($v)) as $w) { $m[$w] = $k; }
        }
        return $m;
    }

    public function tokenize(string $line, int $state, array $tokens): array
    {
        $st = new LexState($line, $tokens, $state & 0xff, ($state >> 8) & 0xff, ($state >> 16) & 0xffffff);
        $n = strlen($line);
        for ($i = 0; $i < $n;) {
            if ($st->mode >= self::MODE_STRING) { $i = $this->inString($st, $i); }
            elseif ($st->mode >= self::MODE_COMMENT) { $i = $this->inComment($st, $i); }
            else { $i = $this->code($st, $i); }
        }
        if ($st->mode >= self::MODE_STRING && !$this->strings[$st->mode - self::MODE_STRING]->multiline) {
            $st->mode = 0; $st->stack = 0;
        }
        return [$st->toks, $st->mode | (($st->depth & 0xff) << 8) | ($st->stack << 16)];
    }

    private function inComment(LexState $st, int $i): int
    {
        [$open, $close] = $this->blockComments[$st->mode - self::MODE_COMMENT];
        $line = $st->line; $n = strlen($line);
        $j = $i;
        while ($j < $n) {
            if ($this->nestComments && substr_compare($line, $open, $j, strlen($open)) === 0) { $st->depth++; $j += strlen($open); continue; }
            if (substr_compare($line, $close, $j, strlen($close)) === 0) {
                $j += strlen($close);
                if ($st->depth > 0) { $st->depth--; continue; }
                $st->mode = 0;
                break;
            }
            $j++;
        }
        Token::add($st->toks, $j, Token::COMMENT);
        return $j;
    }

    /** Lexes a string's contents from $i until its end, the end of the line, or an interpolation, which it enters. */
    private function inString(LexState $st, int $i): int
    {
        $k = $st->mode - self::MODE_STRING;
        $s = $this->strings[$k];
        $line = $st->line; $n = strlen($line);
        for ($j = $i; $j < $n;) {
            $c = $line[$j];
            if ($s->escape !== '' && $c === $s->escape) {
                Token::add($st->toks, $j, $s->kind);
                $e = min($n, $j + 2);
                if ($j + 1 < $n && ord($line[$j + 1]) >= 0x80) { $e = $j + 1 + strlen(Chars::runeAt($line, $j + 1)); }
                Token::add($st->toks, $e, Token::ESCAPE);
                $j = $e;
                continue;
            }
            if (self::startsWith($line, $j, $s->close)) {
                Token::add($st->toks, $j + strlen($s->close), $s->kind);
                $st->mode = 0; $st->regexOK = false;
                return $j + strlen($s->close);
            }
            if ($s->interpOpen !== '' && self::startsWith($line, $j, $s->interpOpen)) {
                Token::add($st->toks, $j, $s->kind);
                $e = $j + strlen($s->interpOpen);
                Token::add($st->toks, $e, Token::ESCAPE);
                if ($st->stack >> 16 === 0) {
                    $st->stack = ($st->stack << 8) | (($k + 1) << 3);
                    $st->mode = 0; $st->regexOK = true;
                    return $e;
                }
                $j = $e;
                continue;
            }
            if ($s->dollar && $c === '$' && $j + 1 < $n && (self::isIdentByte($line[$j + 1]) || $line[$j + 1] === '{')) {
                Token::add($st->toks, $j, $s->kind);
                $e = self::dollarEnd($line, $j);
                Token::add($st->toks, $e, Token::VARIABLE);
                $j = $e;
                continue;
            }
            $j++;
        }
        Token::add($st->toks, $n, $s->kind);
        return $n;
    }

    /** Where a $name, ${name} or $1 at $i ends. */
    public static function dollarEnd(string $line, int $i): int
    {
        $n = strlen($line);
        $j = $i + 1;
        if ($j < $n && $line[$j] === '{') {
            while ($j < $n && $line[$j] !== '}') { $j++; }
            return min($n, $j + 1);
        }
        if ($j < $n && str_contains('@*#?$!-0123456789', $line[$j])) { return $j + 1; }
        while ($j < $n && self::isIdentByte($line[$j])) { $j++; }
        return $j;
    }

    /** Lexes one token of code from $i, and returns where it ends. */
    private function code(LexState $st, int $i): int
    {
        $line = $st->line; $n = strlen($line);
        $c = $line[$i];
        if ($c === ' ' || $c === "\t" || $c === "\r" || $c === "\f") {
            $j = $i + 1;
            while ($j < $n && ($line[$j] === ' ' || $line[$j] === "\t" || $line[$j] === "\r" || $line[$j] === "\f")) { $j++; }
            Token::add($st->toks, $j, Token::TEXT);
            return $j;
        }
        // An interpolation's braces, and its end going back into its string.
        if ($st->stack !== 0) {
            $top = $st->stack & 0xff;
            $s = $this->strings[($top >> 3) - 1];
            $braces = $top & 7;
            if ($c === '{' && $s->interpClose === '}') {
                if ($braces < 7) { $st->stack++; }
                Token::add($st->toks, $i + 1, Token::PUNCTUATION);
                return $i + 1;
            }
            if (($braces === 0 || $s->interpClose !== '}') && self::startsWith($line, $i, $s->interpClose)) {
                $st->stack >>= 8;
                $st->mode = self::MODE_STRING + ($top >> 3) - 1;
                Token::add($st->toks, $i + strlen($s->interpClose), Token::ESCAPE);
                return $i + strlen($s->interpClose);
            }
            if ($c === '}' && $braces > 0) {
                $st->stack--;
                Token::add($st->toks, $i + 1, Token::PUNCTUATION);
                return $i + 1;
            }
        }
        // Block comments first: Lua's --[[ starts with its line comment's --.
        foreach ($this->blockComments as $k => $b) {
            if (self::startsWith($line, $i, $b[0])) {
                $st->mode = self::MODE_COMMENT + $k; $st->depth = 0;
                Token::add($st->toks, $i + strlen($b[0]), Token::COMMENT);
                return $i + strlen($b[0]);
            }
        }
        foreach ($this->lineComments as $p) {
            if (self::startsWith($line, $i, $p)) { Token::add($st->toks, $n, Token::COMMENT); return $n; }
        }
        if (($j = $this->stringStart($st, $i, '')) !== null) { return $j; }
        if ($this->directives && $c === '#' && ltrim(substr($line, 0, $i), " \t") === '') {
            $j = $i + 1;
            while ($j < $n && ($line[$j] === ' ' || $line[$j] === "\t")) { $j++; }
            while ($j < $n && self::isIdentByte($line[$j])) { $j++; }
            Token::add($st->toks, $j, Token::META);
            return $j;
        }
        if ($this->decorator !== '' && $c === $this->decorator && $i + 1 < $n && self::identStart($line, $i + 1, $this->identChars)) {
            $j = $i + 1;
            while ($j < $n && (self::identPart($line, $j, $this->identChars) || $line[$j] === '.')) { $j += strlen(Chars::runeAt($line, $j)); }
            Token::add($st->toks, $j, Token::META);
            return $j;
        }
        if ($this->variables && $c === '$' && $i + 1 < $n) {
            $j = self::dollarEnd($line, $i);
            if ($j > $i + 1) {
                Token::add($st->toks, $j, Token::VARIABLE);
                $st->prevDot = false; $st->regexOK = false;
                return $j;
            }
        }
        // Numbers.
        if (ctype_digit($c) || $c === '.' && $i + 1 < $n && ctype_digit($line[$i + 1]) && !$st->prevDot) {
            $hex = $c === '0' && $i + 1 < $n && ($line[$i + 1] === 'x' || $line[$i + 1] === 'X');
            $j = $i + 1;
            while ($j < $n) {
                $d = $line[$j];
                if (ctype_alnum($d) || $d === '_') { $j++; continue; }
                if ($d === '.' && $j + 1 < $n && ctype_digit($line[$j + 1])) { $j++; continue; }
                if ($d === '+' || $d === '-') {
                    $e = $line[$j - 1];
                    if (!$hex && ($e === 'e' || $e === 'E') || $hex && ($e === 'p' || $e === 'P')) { $j++; continue; }
                }
                break;
            }
            Token::add($st->toks, $j, Token::NUMBER);
            $st->prevDot = false; $st->regexOK = false;
            return $j;
        }
        if (self::identStart($line, $i, $this->identChars)) {
            $j = $i;
            while ($j < $n && self::identPart($line, $j, $this->identChars)) { $j += ord($line[$j]) < 0x80 ? 1 : strlen(Chars::runeAt($line, $j)); }
            $word = substr($line, $i, $j - $i);
            // A string's prefix, as Python's f"…" or Rust's b"…".
            if ($j < $n && $this->prefixes()) {
                if (($k = $this->stringStart($st, $j, $word)) !== null) { return $k; }
            }
            $kind = Token::VARIABLE;
            $key = $this->foldCase ? strtolower($word) : $word;
            if (isset($this->words[$key]) && !$st->prevDot) {
                $kind = $this->words[$key];
            } else {
                $k = $j;
                while ($k < $n && ($line[$k] === ' ' || $line[$k] === "\t")) { $k++; }
                $next = $k < $n ? $line[$k] : '';
                if ($this->keys && $next === ':' && ($k + 1 >= $n || $line[$k + 1] !== ':')) { $kind = Token::PROPERTY; }
                elseif ($this->calls && ($next === '(' || $next === '!' && $k + 1 < $n && $line[$k + 1] === '(')) { $kind = Token::FUNCTION; }
                elseif ($st->prevDot) { $kind = Token::PROPERTY; }
                elseif ($this->capitalTypes && Chars::isUpper(Chars::runeAt($word, 0))) { $kind = Token::TYPE; }
            }
            Token::add($st->toks, $j, $kind);
            $st->prevDot = false;
            $st->regexOK = $kind === Token::KEYWORD;
            return $j;
        }
        if ($this->regexps && $c === '/' && $st->regexOK && $i + 1 < $n && $line[$i + 1] !== '/' && $line[$i + 1] !== '*') {
            $j = $i + 1; $class = false;
            while ($j < $n) {
                $d = $line[$j];
                if ($d === '\\') { $j += 2; continue; }
                if ($d === '[') { $class = true; }
                elseif ($d === ']') { $class = false; }
                elseif ($d === '/' && !$class) { break; }
                $j++;
            }
            if ($j < $n) {
                $j++;
                while ($j < $n && $line[$j] >= 'a' && $line[$j] <= 'z') { $j++; }
                Token::add($st->toks, $j, Token::REGEXP);
                $st->regexOK = false;
                return $j;
            }
        }
        if (str_contains('()[]{},;', $c)) {
            Token::add($st->toks, $i + 1, Token::PUNCTUATION);
            $st->prevDot = false;
            $st->regexOK = $c !== ')' && $c !== ']' && $c !== '}';
            return $i + 1;
        }
        if ($c === '.') {
            Token::add($st->toks, $i + 1, Token::PUNCTUATION);
            $st->prevDot = true; $st->regexOK = false;
            return $i + 1;
        }
        if ($this->operators !== '' && str_contains($this->operators, $c)) {
            $j = $i + 1;
            while ($j < $n && str_contains($this->operators, $line[$j]) && !$this->startsComment($line, $j)) { $j++; }
            Token::add($st->toks, $j, Token::OPERATOR);
            $st->prevDot = false; $st->regexOK = true;
            return $j;
        }
        $k = strlen(Chars::runeAt($line, $i));
        Token::add($st->toks, $i + $k, Token::TEXT);
        $st->prevDot = false;
        return $i + $k;
    }

    private function prefixes(): bool
    {
        foreach ($this->strings as $s) { if ($s->prefix !== '') { return true; } }
        return false;
    }

    private function startsComment(string $line, int $at): bool
    {
        foreach ($this->lineComments as $p) { if (self::startsWith($line, $at, $p)) { return true; } }
        foreach ($this->blockComments as $b) { if (self::startsWith($line, $at, $b[0])) { return true; } }
        return false;
    }

    /** Starts a string at $i, after $prefix, which it colors as the string; where it ends, or null for none. */
    private function stringStart(LexState $st, int $i, string $prefix): ?int
    {
        foreach ($this->strings as $k => $s) {
            if ($s->prefix !== $prefix || !self::startsWith($st->line, $i, $s->open)) { continue; }
            $e = $i + strlen($s->open);
            Token::add($st->toks, $e, $s->kind);
            $st->mode = self::MODE_STRING + $k;
            $st->prevDot = false;
            return $e;
        }
        return null;
    }

    /** Whether $s has $p at byte $at. */
    public static function startsWith(string $s, int $at, string $p): bool
    {
        return $p !== '' && substr_compare($s, $p, $at, strlen($p)) === 0;
    }

    public static function isIdentByte(string $c): bool
    {
        return $c === '_' || ctype_alnum($c);
    }

    /** Whether an identifier starts at byte $at of $s; $extra are the language's other identifier characters. */
    public static function identStart(string $s, int $at, string $extra): bool
    {
        $c = $s[$at];
        if (ord($c) < 0x80) {
            // - and . go on identifiers, but start none.
            return $c === '_' || ctype_alpha($c) || $c !== '-' && $c !== '.' && $extra !== '' && str_contains($extra, $c);
        }
        return preg_match('/^\p{L}/u', substr($s, $at, 4)) === 1;
    }

    public static function identPart(string $s, int $at, string $extra): bool
    {
        $c = $s[$at];
        if (ord($c) < 0x80) { return self::isIdentByte($c) || $extra !== '' && str_contains($extra, $c); }
        return preg_match('/^[\p{L}\p{Nd}\p{Mn}]/u', substr($s, $at, 4)) === 1;
    }
}
