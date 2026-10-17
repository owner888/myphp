<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/**
 * CSS, SCSS and Less (mygo's cssLexer): selectors, at-rules, properties
 * and their values. Its state packs the mode (bits 0-3: 1 a comment) and
 * how many blocks the line starts in (bits 8-15).
 */
final class CssLexer implements Tokenizer
{
    public function tokenize(string $line, int $state, array $tokens): array
    {
        $out = $tokens;
        $mode = $state & 0xf;
        $depth = ($state >> 8) & 0xff;
        $n = strlen($line);
        $value = false; // after a property's colon, until ; or }
        for ($i = 0; $i < $n;) {
            $c = $line[$i];
            if ($mode === 1) {
                $end = strpos($line, '*/', $i);
                if ($end === false) { Token::add($out, $n, Token::COMMENT); $i = $n; continue; }
                Token::add($out, $end + 2, Token::COMMENT);
                $i = $end + 2;
                $mode = 0;
                continue;
            }
            if ($c === ' ' || $c === "\t" || $c === "\r") {
                Token::add($out, $i + 1, Token::TEXT); $i++;
            } elseif (Lexer::startsWith($line, $i, '/*')) {
                $mode = 1; Token::add($out, $i + 2, Token::COMMENT); $i += 2;
            } elseif (Lexer::startsWith($line, $i, '//') && ($i === 0 || $line[$i - 1] !== ':')) {
                Token::add($out, $n, Token::COMMENT); $i = $n;
            } elseif ($c === '"' || $c === "'") {
                $j = $i + 1;
                while ($j < $n && $line[$j] !== $c) { if ($line[$j] === '\\') { $j++; } $j++; }
                Token::add($out, min($n, $j + 1), Token::STRING); $i = min($n, $j + 1);
            } elseif ($c === '{') {
                $depth = min($depth + 1, 255); $value = false; Token::add($out, $i + 1, Token::PUNCTUATION); $i++;
            } elseif ($c === '}') {
                $depth = max($depth - 1, 0); $value = false; Token::add($out, $i + 1, Token::PUNCTUATION); $i++;
            } elseif ($c === ';') {
                $value = false; Token::add($out, $i + 1, Token::PUNCTUATION); $i++;
            } elseif ($c === '@') {
                $j = $i + 1;
                while ($j < $n && (Lexer::isIdentByte($line[$j]) || $line[$j] === '-')) { $j++; }
                Token::add($out, $j, Token::KEYWORD); $i = $j;
            } elseif ($c === '$' && $i + 1 < $n && (Lexer::isIdentByte($line[$i + 1]) || $line[$i + 1] === '-')) {
                $j = $i + 1;
                while ($j < $n && (Lexer::isIdentByte($line[$j]) || $line[$j] === '-')) { $j++; }
                Token::add($out, $j, Token::VARIABLE); $i = $j;
            } elseif ($c === '#' && $value) {
                $j = $i + 1;
                while ($j < $n && Lexer::isIdentByte($line[$j])) { $j++; }
                Token::add($out, $j, Token::NUMBER); $i = $j;
            } elseif (ctype_digit($c) || ($c === '.' || $c === '-' || $c === '+') && $i + 1 < $n && ctype_digit($line[$i + 1]) && $value) {
                $j = $i + 1;
                while ($j < $n && (ctype_digit($line[$j]) || $line[$j] === '.')) { $j++; }
                // The unit.
                while ($j < $n && ($line[$j] >= 'a' && $line[$j] <= 'z' || $line[$j] === '%')) { $j++; }
                Token::add($out, $j, Token::NUMBER); $i = $j;
            } elseif (Lexer::isIdentByte($c) || $c === '-' || ord($c) >= 0x80) {
                $j = $i;
                while ($j < $n && (Lexer::isIdentByte($line[$j]) || $line[$j] === '-' || ord($line[$j]) >= 0x80)) { $j++; }
                $k = $j;
                while ($k < $n && ($line[$k] === ' ' || $line[$k] === "\t")) { $k++; }
                $kind = Token::TAG;
                if ($value && $k < $n && $line[$k] === '(') { $kind = Token::FUNCTION; }
                elseif ($value) { $kind = $line[$i] === '-' && Lexer::startsWith($line, $i, '--') ? Token::VARIABLE : Token::CONSTANT; }
                elseif ($depth > 0 && $k < $n && $line[$k] === ':' && !self::selectorAhead(substr($line, $k))) { $kind = Token::PROPERTY; }
                elseif (Lexer::startsWith($line, $i, '--')) { $kind = Token::VARIABLE; }
                Token::add($out, $j, $kind); $i = $j;
            } elseif ($c === ':' && !$value && $depth > 0 && !self::selectorAhead(substr($line, $i))) {
                $value = true; Token::add($out, $i + 1, Token::PUNCTUATION); $i++;
            } elseif ($c === ':') {
                // A pseudo-class or element.
                $j = $i + 1;
                while ($j < $n && ($line[$j] === ':' || Lexer::isIdentByte($line[$j]) || $line[$j] === '-')) { $j++; }
                Token::add($out, $j, Token::KEYWORD); $i = $j;
            } elseif (($c === '.' || $c === '#') && !$value && $i + 1 < $n && (Lexer::isIdentByte($line[$i + 1]) || $line[$i + 1] === '-')) {
                $j = $i + 1;
                while ($j < $n && (Lexer::isIdentByte($line[$j]) || $line[$j] === '-')) { $j++; }
                Token::add($out, $j, Token::TYPE); $i = $j;
            } elseif ($c === '!' && Lexer::startsWith($line, $i, '!important')) {
                Token::add($out, $i + 10, Token::KEYWORD); $i += 10;
            } else {
                Token::add($out, $i + 1, str_contains('(),[]', $c) ? Token::PUNCTUATION : Token::OPERATOR); $i++;
            }
        }
        return [$out, $mode | ($depth << 8)];
    }

    /** Whether what follows a colon is a selector going on to a block, as a:hover {, rather than a value. */
    private static function selectorAhead(string $s): bool
    {
        $brace = strpos($s, '{'); $semi = strpos($s, ';');
        return $brace !== false && ($semi === false || $brace < $semi);
    }
}
