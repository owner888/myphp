<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/**
 * The kinds of tokens of code, which the editor's theme colors (mygo's
 * TokenKind), and the tokens themselves: a token is [end, kind], a run
 * of a line of one kind from the end of the token before it, or the
 * start of the line, to $end, a byte offset in the line.
 */
final class Token
{
    public const TEXT = 0, KEYWORD = 1, TYPE = 2, FUNCTION = 3, VARIABLE = 4, PROPERTY = 5, CONSTANT = 6, NUMBER = 7, STRING = 8,
        ESCAPE = 9, // escapes and interpolations in strings
        REGEXP = 10, COMMENT = 11, OPERATOR = 12, PUNCTUATION = 13,
        TAG = 14, // HTML and XML elements
        ATTRIBUTE = 15, // their attributes
        META = 16, // preprocessor lines, decorators, annotations
        HEADING = 17, EMPHASIS = 18, STRONG = 19, LINK = 20,
        CODE = 21, // code in prose, as Markdown's
        INSERTED = 22, DELETED = 23, INVALID = 24, KINDS = 25;

    /** Appends a token ending at $end of kind $k to $toks, joining it with the last when of the same kind, and dropping it when it ends no later (mygo's lexOut.add and appendTok). @param list<array{0: int, 1: int}> $toks */
    public static function add(array &$toks, int $end, int $k): void
    {
        $n = count($toks);
        if ($n > 0) {
            if ($toks[$n - 1][0] >= $end) { return; }
            if ($toks[$n - 1][1] === $k) { $toks[$n - 1][0] = $end; return; }
        }
        $toks[] = [$end, $k];
    }

    /** The tokens of a line covering $start to $end given kind $k, splitting those that reach past them. @param list<array{0: int, 1: int}> $toks @return list<array{0: int, 1: int}> */
    public static function relabel(array $toks, int $start, int $end, int $k): array
    {
        $out = [];
        $from = 0;
        foreach ($toks as [$b, $kind]) {
            $a = $from;
            $from = $b;
            if ($b <= $start || $a >= $end) { self::add($out, $b, $kind); continue; }
            if ($a < $start) { self::add($out, $start, $kind); }
            self::add($out, min($b, $end), $k);
            if ($b > $end) { self::add($out, $b, $kind); }
        }
        return $out;
    }
}
