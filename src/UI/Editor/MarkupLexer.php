<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/**
 * HTML and XML (mygo's markupLexer): tags, attributes, comments,
 * entities, and, in HTML, the scripts and styles inside it with their
 * own tokenizers. Its state packs the mode (bits 0-3), what the tag being
 * lexed opens (bits 4-5: 1 a script, 2 a style), and the state of the
 * script or style (bits 16-55).
 */
final class MarkupLexer implements Tokenizer
{
    private const TEXT = 0, TAG = 1, COMMENT = 2, DOUBLE = 3, SINGLE = 4, CDATA = 5, SCRIPT = 6, STYLE = 7;

    public function __construct(public readonly bool $html = false, public readonly ?Tokenizer $script = null, public readonly ?Tokenizer $style = null) {}

    public function tokenize(string $line, int $state, array $tokens): array
    {
        $out = $tokens;
        $mode = $state & 0xf;
        $embed = ($state >> 4) & 3;
        $inner = $state >> 16;
        $n = strlen($line);
        for ($i = 0; $i < $n;) {
            switch ($mode) {
                case self::SCRIPT: case self::STYLE:
                    $tag = $mode === self::STYLE ? '</style' : '</script';
                    $t = $mode === self::STYLE ? $this->style : $this->script;
                    $end = stripos($line, $tag, $i);
                    $stop = $end === false ? $n : $end;
                    if ($t !== null && $stop > $i) {
                        [$toks, $inner] = $t->tokenize(substr($line, $i, $stop - $i), $inner, []);
                        foreach ($toks as [$e, $k]) { Token::add($out, $i + $e, $k); }
                    }
                    Token::add($out, $stop, Token::TEXT);
                    $i = $stop;
                    if ($end !== false) { $mode = self::TEXT; $inner = 0; }
                    break;
                case self::COMMENT:
                    $end = strpos($line, '-->', $i);
                    if ($end === false) { Token::add($out, $n, Token::COMMENT); $i = $n; break; }
                    Token::add($out, $end + 3, Token::COMMENT); $i = $end + 3; $mode = self::TEXT;
                    break;
                case self::CDATA:
                    $end = strpos($line, ']]>', $i);
                    if ($end === false) { Token::add($out, $n, Token::STRING); $i = $n; break; }
                    Token::add($out, $end + 3, Token::STRING); $i = $end + 3; $mode = self::TEXT;
                    break;
                case self::DOUBLE: case self::SINGLE:
                    $end = strpos($line, $mode === self::SINGLE ? "'" : '"', $i);
                    if ($end === false) { Token::add($out, $n, Token::STRING); $i = $n; break; }
                    Token::add($out, $end + 1, Token::STRING); $i = $end + 1; $mode = self::TAG;
                    break;
                case self::TAG:
                    $c = $line[$i];
                    if ($c === '>') {
                        Token::add($out, $i + 1, Token::PUNCTUATION); $i++;
                        $mode = match ($embed) { 1 => self::SCRIPT, 2 => self::STYLE, default => self::TEXT };
                        $embed = 0;
                    } elseif ($c === '/' && $i + 1 < $n && $line[$i + 1] === '>') {
                        Token::add($out, $i + 2, Token::PUNCTUATION); $i += 2; $mode = self::TEXT; $embed = 0;
                    } elseif ($c === '"') {
                        Token::add($out, $i + 1, Token::STRING); $i++; $mode = self::DOUBLE;
                    } elseif ($c === "'") {
                        Token::add($out, $i + 1, Token::STRING); $i++; $mode = self::SINGLE;
                    } elseif ($c === '=') {
                        Token::add($out, $i + 1, Token::OPERATOR); $i++;
                    } elseif ($c === ' ' || $c === "\t" || $c === "\r") {
                        Token::add($out, $i + 1, Token::TEXT); $i++;
                    } else {
                        $j = $i;
                        while ($j < $n && !str_contains(" \t\r=>/\"'", $line[$j])) { $j++; }
                        if ($j === $i) { $j++; }
                        Token::add($out, $j, Token::ATTRIBUTE); $i = $j;
                    }
                    break;
                default:
                    $c = $line[$i];
                    if (Lexer::startsWith($line, $i, '<!--')) {
                        Token::add($out, $i + 4, Token::COMMENT); $i += 4; $mode = self::COMMENT;
                    } elseif (Lexer::startsWith($line, $i, '<![CDATA[')) {
                        Token::add($out, $i + 9, Token::STRING); $i += 9; $mode = self::CDATA;
                    } elseif ($c === '<' && $i + 1 < $n && ($line[$i + 1] === '!' || $line[$i + 1] === '?')) {
                        $j = $i + 2;
                        while ($j < $n && $line[$j] !== '>' && $line[$j] !== ' ') { $j++; }
                        Token::add($out, $j, Token::META); $i = $j; $mode = self::TAG;
                    } elseif ($c === '<' && $i + 1 < $n && ($line[$i + 1] === '/' || Lexer::identStart($line, $i + 1, ''))) {
                        Token::add($out, $i + 1, Token::PUNCTUATION);
                        $j = $i + 1;
                        if ($line[$j] === '/') { Token::add($out, $j + 1, Token::PUNCTUATION); $j++; }
                        $k = $j;
                        while ($k < $n && (Lexer::isIdentByte($line[$k]) || $line[$k] === '-' || $line[$k] === ':' || $line[$k] === '.')) { $k++; }
                        Token::add($out, $k, Token::TAG);
                        $name = strtolower(substr($line, $j, $k - $j));
                        $embed = 0;
                        if ($this->html && $line[$i + 1] !== '/') { $embed = match ($name) { 'script' => 1, 'style' => 2, default => 0 }; }
                        $i = $k; $mode = self::TAG;
                    } elseif ($c === '&') {
                        $j = $i + 1;
                        while ($j < $n && $j - $i < 32 && (Lexer::isIdentByte($line[$j]) || $line[$j] === '#')) { $j++; }
                        if ($j < $n && $line[$j] === ';') { Token::add($out, $j + 1, Token::ESCAPE); $i = $j + 1; }
                        else { Token::add($out, $i + 1, Token::TEXT); $i++; }
                    } else {
                        $j = $i + 1;
                        while ($j < $n && $line[$j] !== '<' && $line[$j] !== '&') { $j++; }
                        Token::add($out, $j, Token::TEXT); $i = $j;
                    }
            }
        }
        return [$out, $mode | ($embed << 4) | ($inner << 16)];
    }
}
