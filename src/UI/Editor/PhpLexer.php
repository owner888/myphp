<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/**
 * PHP's tokens beyond what the lexer of C-like languages makes of them
 * (the developer asked for PHP done as an editor of PHP does it; mygo's
 * PHP is its lexer alone): heredocs and nowdocs, which go on to their
 * closing label, a heredoc's variables in it; # comments, and the #[ of
 * attributes; <?php, <?= and ?> as tags; and the names after -> and
 * ?-> as properties. Its state is the lexer's, or, inside a heredoc, the
 * mode HEREDOC or NOWDOC (bits 0-7) and its label's number (bits 8-39),
 * within the 40 bits Markdown holds for its code blocks.
 */
final class PhpLexer implements Tokenizer
{
    private const HEREDOC = 0xf0, NOWDOC = 0xf1;
    /** The labels of the heredocs met, by number, and their numbers: the same label is the same state. @var list<string> */
    private static array $labels = [];
    /** @var array<string, int> */
    private static array $numbers = [];

    /** $code lexes the rest: its line comments are //, not #, which this finds. */
    public function __construct(private readonly Lexer $code) {}

    public function tokenize(string $line, int $state, array $tokens): array
    {
        $mode = $state & 0xff;
        if ($mode !== self::HEREDOC && $mode !== self::NOWDOC) {
            return $this->code($line, 0, $state, $tokens);
        }
        $label = self::$labels[$state >> 8];
        if (preg_match('/^([ \t]*)' . preg_quote($label, '/') . '(?![A-Za-z0-9_\x80-\xff])/', $line, $m)) {
            if ($m[1] !== '') { Token::add($tokens, strlen($m[1]), Token::TEXT); }
            Token::add($tokens, strlen($m[0]), Token::STRING);
            return $this->code($line, strlen($m[0]), 0, $tokens);
        }
        $n = strlen($line);
        for ($i = 0; $mode === self::HEREDOC && $i < $n; $i++) { // a heredoc's $name, {$expression} and ${name} stand out, as in a "string"
            if ($line[$i] === '\\' && $i + 1 < $n) { Token::add($tokens, $i, Token::STRING); Token::add($tokens, $i + 2, Token::ESCAPE); $i++; continue; }
            $brace = $line[$i] === '{' && $i + 1 < $n && $line[$i + 1] === '$';
            if (!$brace && ($line[$i] !== '$' || $i + 1 >= $n || !(Lexer::isIdentByte($line[$i + 1]) || $line[$i + 1] === '{'))) { continue; }
            Token::add($tokens, $i, Token::STRING);
            $e = $brace ? (($c = strpos($line, '}', $i)) === false ? $n : $c + 1) : Lexer::dollarEnd($line, $i);
            Token::add($tokens, $e, Token::VARIABLE);
            $i = $e - 1;
        }
        Token::add($tokens, $n, Token::STRING);
        return [$tokens, $state];
    }

    /** Lexes $line from $from in the lexer's $state, appending to $tokens, up to what is PHP's own, which it lexes and goes on after. @return array{0: list<array{0: int, 1: int}>, 1: int} */
    private function code(string $line, int $from, int $state, array $tokens): array
    {
        $rest = substr($line, $from);
        [$toks, $end] = $this->code->tokenize($rest, $state, []);
        [$at, $what] = self::own($rest, $toks);
        if ($at === null) {
            self::append($tokens, $line, $from, $toks);
            return [$tokens, $end];
        }
        [$before, $state] = $this->code->tokenize(substr($rest, 0, $at), $state, []);
        self::append($tokens, $line, $from, $before);
        $at += $from;
        $n = strlen($line);
        switch ($what) {
            case '#': // a comment to the end of the line
                Token::add($tokens, $n, Token::COMMENT);
                return [$tokens, $state];
            case '<<<':
                preg_match('/<<<[ \t]*(["\']?)([A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)\1/', $line, $m, 0, $at);
                Token::add($tokens, $at + strlen($m[0]), Token::STRING);
                Token::add($tokens, $n, Token::TEXT);
                $number = self::$numbers[$m[2]] ??= array_push(self::$labels, $m[2]) - 1;
                return [$tokens, ($m[1] === "'" ? self::NOWDOC : self::HEREDOC) | $number << 8];
            default: // a tag, or an attribute's #[
                Token::add($tokens, $at + strlen($what), Token::META);
                return $this->code($line, $at + strlen($what), $state, $tokens);
        }
    }

    /**
     * Where in $line, which the lexer made $toks of, the first of what is
     * PHP's own starts, outside strings and comments, and what it is: a #
     * comment, an attribute's #[, a heredoc's <<<, or a tag. @param list<array{0: int, 1: int}> $toks
     * @return array{0: ?int, 1: string}
     */
    private static function own(string $line, array $toks): array
    {
        $start = 0;
        foreach ($toks as [$end, $kind]) {
            if (!in_array($kind, [Token::STRING, Token::ESCAPE, Token::COMMENT, Token::VARIABLE], true)) {
                for ($i = $start; $i < $end; $i++) {
                    if ($line[$i] === '#') { return ($line[$i + 1] ?? '') === '[' ? [$i, '#['] : [$i, '#']; }
                    if (substr_compare($line, '<<<', $i, 3) === 0 && preg_match('/\G<<<[ \t]*(["\']?)[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*\1[ \t]*$/', $line, $m, 0, $i)) { return [$i, '<<<']; }
                    foreach (['<?php', '<?=', '?>'] as $tag) {
                        if (substr_compare($line, $tag, $i, strlen($tag)) === 0) { return [$i, $tag]; }
                    }
                }
            }
            $start = $end;
        }
        return [null, ''];
    }

    /** Appends the lexer's $toks of $line from $from to $tokens, a name after -> or ?-> a property. @param list<array{0: int, 1: int}> $tokens @param list<array{0: int, 1: int}> $toks */
    private static function append(array &$tokens, string $line, int $from, array $toks): void
    {
        $start = $from;
        foreach ($toks as [$end, $kind]) {
            $end += $from;
            if ($kind === Token::VARIABLE && $line[$start] !== '$' && preg_match('/\??->$/', rtrim(substr($line, 0, $start)))) { $kind = Token::PROPERTY; }
            Token::add($tokens, $end, $kind);
            $start = $end;
        }
    }
}
