<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/** JSON: the strings before colons are keys (mygo's jsonLexer). */
final class JsonLexer extends Lexer
{
    public function tokenize(string $line, int $state, array $tokens): array
    {
        $n0 = count($tokens);
        [$tokens, $next] = parent::tokenize($line, $state, $tokens);
        $toks = array_slice($tokens, $n0);
        $from = 0;
        for ($i = 0; $i < count($toks); $i++) {
            if ($toks[$i][1] !== Token::STRING) { $from = $toks[$i][0]; continue; }
            // A string, with its escapes.
            $start = $from;
            $k = $i;
            while ($k + 1 < count($toks) && ($toks[$k + 1][1] === Token::STRING || $toks[$k + 1][1] === Token::ESCAPE)) { $k++; }
            $end = $toks[$k][0];
            $p = $end;
            while ($p < strlen($line) && ($line[$p] === ' ' || $line[$p] === "\t")) { $p++; }
            if ($p < strlen($line) && $line[$p] === ':') {
                $toks = Token::relabel($toks, $start, $end, Token::PROPERTY);
                // Find where the relabeled run ends to go on from it.
                for ($i = 0; $i < count($toks) && $toks[$i][0] < $end; $i++) {}
            } else {
                $i = $k;
            }
            $from = $end;
        }
        return [array_merge(array_slice($tokens, 0, $n0), $toks), $next];
    }
}
