<?php
declare(strict_types=1);

namespace MyPHP\UI\Shader\Syntax;

/**
 * Reads Go source into tokens, as Go's go/scanner does, for the shader
 * compiler: names, numbers, runes, strings, operators and keywords, with
 * the semicolons Go inserts at the ends of lines, and without comments.
 *
 * @internal
 */
final class Scanner
{
    /** @var list<array{0: string, 1: string, 2: int}> the tokens: kind, text and byte offset; an inserted semicolon's text is "\n" */
    public array $tokens = [];
    /** @var list<array{0: int, 1: string}> what is wrong, by byte offset */
    public array $errors = [];

    private int $i = 0;
    private int $n;
    /** Whether a newline after the last token ends a statement (Go's insertSemi). */
    private bool $semi = false;

    /** @var array<string, list<string>> Token::OPERATORS by their first character */
    private static array $operators = [];

    public function __construct(private readonly string $src)
    {
        if (self::$operators === []) {
            foreach (Token::OPERATORS as $op) { self::$operators[$op[0]][] = $op; }
        }
        $this->n = strlen($src);
        if (str_starts_with($src, "\xEF\xBB\xBF")) {
            $this->i = 3; // a byte order mark at the start is ignored, as Go does
        }
        $this->scan();
    }

    private function error(int $at, string $msg): void
    {
        $this->errors[] = [$at, $msg];
    }

    private function emit(string $kind, string $lit, int $at): void
    {
        $this->tokens[] = [$kind, $lit, $at];
        $this->semi = $kind === Token::IDENT || $kind === Token::INT || $kind === Token::FLOAT || $kind === Token::IMAG || $kind === Token::CHAR || $kind === Token::STRING
            || $kind === 'break' || $kind === 'continue' || $kind === 'fallthrough' || $kind === 'return'
            || $kind === '++' || $kind === '--' || $kind === ')' || $kind === ']' || $kind === '}';
    }

    /** A newline, or the end, where Go inserts a semicolon after the token before. */
    private function newline(int $at): void
    {
        if ($this->semi) {
            $this->tokens[] = [';', "\n", $at];
            $this->semi = false;
        }
    }

    private function scan(): void
    {
        $s = $this->src; $n = $this->n;
        while ($this->i < $n) {
            $c = $s[$this->i];
            if ($c === "\n") {
                $this->newline($this->i);
                $this->i++;
                continue;
            }
            if ($c === ' ' || $c === "\t" || $c === "\r") {
                $this->i++;
                continue;
            }
            $at = $this->i;
            if ($c === '/' && $this->i + 1 < $n && ($s[$this->i + 1] === '/' || $s[$this->i + 1] === '*')) {
                $this->comment();
                continue;
            }
            if (ctype_alpha($c) || $c === '_' || ord($c) >= 0x80) {
                $this->name();
                continue;
            }
            if (ctype_digit($c) || $c === '.' && $this->i + 1 < $n && ctype_digit($s[$this->i + 1])) {
                $this->number();
                continue;
            }
            if ($c === '"') {
                $this->emit(Token::STRING, $this->quoted('"', 'string'), $at);
                continue;
            }
            if ($c === "'") {
                $lit = $this->quoted("'", 'rune');
                $this->emit(Token::CHAR, $lit, $at);
                continue;
            }
            if ($c === '`') {
                $end = strpos($s, '`', $this->i + 1);
                if ($end === false) {
                    $this->error($at, 'raw string literal not terminated');
                    $end = $n - 1;
                }
                $this->emit(Token::STRING, substr($s, $at, $end + 1 - $at), $at);
                $this->i = $end + 1;
                continue;
            }
            foreach (self::$operators[$c] ?? [] as $op) { // those starting with the character, longest first
                if (substr_compare($s, $op, $this->i, strlen($op)) === 0) {
                    $this->i += strlen($op);
                    $this->emit($op, $op, $at);
                    continue 2;
                }
            }
            $ch = mb_substr(substr($s, $at, 4), 0, 1);
            $this->error($at, sprintf('invalid character %s', json_encode($ch, JSON_UNESCAPED_UNICODE)));
            $this->i += max(1, strlen($ch));
        }
        $this->newline($n);
        $this->tokens[] = [Token::EOF, '', $n];
    }

    /** A // or /* comment: one spanning lines, or reaching the end, ends a line as a newline does. */
    private function comment(): void
    {
        $s = $this->src; $at = $this->i;
        if ($s[$at + 1] === '/') {
            $end = strpos($s, "\n", $at);
            $this->i = $end === false ? $this->n : $end; // the newline itself inserts the semicolon
            return;
        }
        $end = strpos($s, '*/', $at + 2);
        if ($end === false) {
            $this->error($at, 'comment not terminated');
            $this->i = $this->n;
            $this->newline($this->n);
            return;
        }
        $this->i = $end + 2;
        if (str_contains(substr($s, $at, $end - $at), "\n")) {
            $this->newline($at);
        }
    }

    private function name(): void
    {
        $s = $this->src; $at = $this->i;
        preg_match('/\G[\p{L}_][\p{L}\p{Nd}_]*/u', $s, $m, 0, $at);
        if (($m[0] ?? '') === '') {
            $ch = mb_substr(substr($s, $at, 4), 0, 1);
            $this->error($at, sprintf('invalid character %s', json_encode($ch, JSON_UNESCAPED_UNICODE)));
            $this->i += max(1, strlen($ch));
            return;
        }
        $this->i += strlen($m[0]);
        $this->emit(isset(Token::KEYWORDS[$m[0]]) ? $m[0] : Token::IDENT, $m[0], $at);
    }

    /** A number literal: decimal, hexadecimal, octal or binary, an integer or a float, imaginary with i. */
    private function number(): void
    {
        $s = $this->src; $at = $this->i;
        $kind = Token::INT;
        $base = 10; $prefix = '';
        if ($s[$at] === '0' && $at + 1 < $this->n && str_contains('xXbBoO', $s[$at + 1])) {
            $prefix = strtolower($s[$at + 1]);
            $base = ['x' => 16, 'b' => 2, 'o' => 8][$prefix];
            $this->i += 2;
        }
        $digits = $base === 16 ? '0123456789abcdefABCDEF_' : '0123456789_';
        $this->i += strspn($s, $digits, $this->i);
        if ($this->i < $this->n && $s[$this->i] === '.' && ($base === 10 || $base === 16)) {
            $kind = Token::FLOAT;
            $this->i++;
            $this->i += strspn($s, $digits, $this->i);
        }
        $exp = $base === 16 ? 'pP' : 'eE';
        if ($this->i < $this->n && str_contains($exp, $s[$this->i]) && ($base === 10 || $base === 16)) {
            $kind = Token::FLOAT;
            $this->i++;
            if ($this->i < $this->n && ($s[$this->i] === '+' || $s[$this->i] === '-')) { $this->i++; }
            $d = strspn($s, '0123456789_', $this->i);
            if ($d === 0) { $this->error($at, 'exponent has no digits'); }
            $this->i += $d;
        } elseif ($kind === Token::FLOAT && $base === 16) {
            $this->error($at, 'hexadecimal mantissa requires a \'p\' exponent');
        }
        if ($this->i < $this->n && $s[$this->i] === 'i') {
            $kind = Token::IMAG;
            $this->i++;
        }
        $lit = substr($s, $at, $this->i - $at);
        $this->checkNumber($lit, $at, $base, $prefix, $kind);
        $this->emit($kind, $lit, $at);
    }

    private function checkNumber(string $lit, int $at, int $base, string $prefix, string $kind): void
    {
        $body = $prefix === '' ? $lit : substr($lit, 2);
        if ($kind === Token::IMAG) { $body = substr($body, 0, -1); }
        // Each _ between two digits, or after the prefix and before a digit.
        $digit = fn(string $c): bool => $base === 16 ? ctype_xdigit($c) : ctype_digit($c);
        for ($k = strpos($lit, '_'); $k !== false; $k = strpos($lit, '_', $k + 1)) {
            if (!$digit($lit[$k + 1] ?? '') || !($digit($lit[$k - 1]) || $prefix !== '' && $k === 2)) {
                $this->error($at, "'_' must separate successive digits");
                return;
            }
        }
        if ($prefix !== '' && trim(preg_replace('/[pP][+-]?[0-9_]*$/', '', $body), '._') === '') {
            $this->error($at, [16 => 'hexadecimal', 8 => 'octal', 2 => 'binary'][$base] . ' literal has no digits');
            return;
        }
        $bad = match (true) {
            $base === 2 => preg_match('/[2-9]/', $body, $m) ? [$m[0], 'binary'] : null,
            $base === 8 => preg_match('/[89]/', $body, $m) ? [$m[0], 'octal'] : null,
            $base === 10 && $kind === Token::INT && strlen($lit) > 1 && $lit[0] === '0' => preg_match('/[89]/', $lit, $m) ? [$m[0], 'octal'] : null, // a leading 0 is octal
            default => null,
        };
        if ($bad !== null) {
            $this->error($at + strcspn($lit, $bad[0]), "invalid digit '{$bad[0]}' in {$bad[1]} literal"); // at the digit, as Go has it
        }
    }

    /** A quoted string or rune: its text with the quotes, its escapes checked. */
    private function quoted(string $q, string $what): string
    {
        $s = $this->src; $at = $this->i;
        $this->i++;
        $count = 0;
        while (true) {
            if ($this->i >= $this->n || $s[$this->i] === "\n") {
                $this->error($at, "$what literal not terminated");
                break;
            }
            $c = $s[$this->i];
            if ($c === $q) {
                $this->i++;
                break;
            }
            if ($c === '\\') {
                $this->i++;
                $e = $s[$this->i] ?? '';
                $len = match (true) {
                    str_contains('abfnrtv\\', $e) || $e === $q => 1,
                    $e >= '0' && $e <= '7' => 3,
                    $e === 'x' => 3,
                    $e === 'u' => 5,
                    $e === 'U' => 9,
                    default => 0,
                };
                if ($len === 0) {
                    $this->error($this->i - 1, 'unknown escape sequence');
                    $len = 1;
                }
                $this->i += $len;
            } else {
                $this->i += max(1, strlen(mb_substr(substr($s, $this->i, 4), 0, 1)));
            }
            $count++;
        }
        if ($what === 'rune' && $count !== 1 && $this->i <= $this->n) {
            $this->error($at, $count === 0 ? 'empty rune literal or unescaped \' in rune literal' : 'more than one character in rune literal');
        }
        return substr($s, $at, $this->i - $at);
    }
}
