<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/** TOML: tables are types and keys properties (mygo's tomlLexer). */
final class TomlLexer extends Lexer
{
    public function tokenize(string $line, int $state, array $tokens): array
    {
        $n0 = count($tokens);
        [$tokens, $next] = parent::tokenize($line, $state, $tokens);
        if ($state !== 0) { return [$tokens, $next]; }
        $toks = array_slice($tokens, $n0);
        $trimmed = ltrim($line, " \t");
        $start = strlen($line) - strlen($trimmed);
        if (str_starts_with($trimmed, '[')) {
            $end = strrpos($line, ']');
            if ($end !== false && $end > $start) { $toks = Token::relabel($toks, $start, $end + 1, Token::TYPE); }
        } else {
            $eq = strpos($line, '=');
            if ($eq !== false && $eq > $start && strpbrk(substr($line, $start, $eq - $start), "\"'#") === false) {
                $key = rtrim(substr($line, $start, $eq - $start), " \t");
                $toks = Token::relabel($toks, $start, $start + strlen($key), Token::PROPERTY);
            }
        }
        return [array_merge(array_slice($tokens, 0, $n0), $toks), $next];
    }
}
