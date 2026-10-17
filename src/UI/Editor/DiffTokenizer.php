<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/** Unified diffs, by the first characters of their lines (mygo's tokenizeDiff). */
final class DiffTokenizer implements Tokenizer
{
    public function tokenize(string $line, int $state, array $tokens): array
    {
        if ($line === '') { return [$tokens, 0]; }
        $kind = Token::TEXT;
        foreach (['+++', '---', 'diff ', 'index ', 'new file', 'deleted file', 'similarity', 'rename ', 'old mode', 'new mode'] as $p) {
            if (str_starts_with($line, $p)) { $kind = Token::META; break; }
        }
        if ($kind === Token::TEXT) {
            if (str_starts_with($line, '@@')) {
                $kind = Token::KEYWORD;
                $end = strpos($line, '@@', 2);
                if ($end !== false) {
                    $tokens[] = [$end + 2, Token::KEYWORD];
                    if ($end + 2 < strlen($line)) { $tokens[] = [strlen($line), Token::FUNCTION]; }
                    return [$tokens, 0];
                }
            } elseif ($line[0] === '+' || $line[0] === '>') { $kind = Token::INSERTED; }
            elseif ($line[0] === '-' || $line[0] === '<') { $kind = Token::DELETED; }
            elseif ($line[0] === '\\') { $kind = Token::COMMENT; }
        }
        $tokens[] = [strlen($line), $kind];
        return [$tokens, 0];
    }
}
