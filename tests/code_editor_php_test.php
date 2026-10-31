<?php
// PHP in the code editor (PhpLexer, which the developer asked for beyond
// mygo's lexer): # comments and attributes' #[, heredocs and nowdocs to
// their closing label with a heredoc's variables, tags, the names after
// -> and ?-> as properties, enum and the scalar types.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\Editor\{Languages, Token as T};

$php = Languages::named('php');
/** The lines' tokens, as [kind, text] pairs, each line starting in the state the one before ended in. @return list<list<array{0: int, 1: string}>> */
$lex = function (string ...$lines) use ($php): array {
    $state = 0; $out = [];
    foreach ($lines as $line) {
        [$toks, $state] = $php->tokenizer->tokenize($line, $state, []);
        $at = 0; $row = [];
        foreach ($toks as [$end, $kind]) { if ($end > $at) { $row[] = [$kind, substr($line, $at, $end - $at)]; } $at = $end; }
        $out[] = $row;
    }
    return $out;
};
$kindOf = function (array $row, string $text): ?int { foreach ($row as [$k, $t]) { if ($t === $text) { return $k; } } return null; };

[$tag] = $lex('<?php');
same([[T::META, '<?php']], $tag, '<?php a tag');
[$attr] = $lex('#[Attribute(Attribute::TARGET_CLASS)]');
same([T::META, T::TYPE], [$kindOf($attr, '#['), $kindOf($attr, 'TARGET_CLASS')], 'an attribute: its #[ no comment, what follows code');
[$c] = $lex("# a comment, don't");
same([[T::COMMENT, "# a comment, don't"]], $c, '# a comment, its apostrophe no string');
[, $after] = $lex("# don't", 'x();');
same(T::FUNCTION, $kindOf($after, 'x'), 'and the line after it code');
[$e] = $lex("enum Suit: string { case Hearts = 'H'; }");
same([T::KEYWORD, T::TYPE, T::KEYWORD], [$kindOf($e, 'enum'), $kindOf($e, 'string'), $kindOf($e, 'case')], 'enum a keyword, string a type');
[$p] = $lex('$this->counts[$n] = $o?->name(1) + self::X;');
same([T::VARIABLE, T::PROPERTY, T::FUNCTION, T::TYPE], [$kindOf($p, '$this'), $kindOf($p, 'counts'), $kindOf($p, 'name'), $kindOf($p, 'self')], 'after -> a property, a call a function');

$doc = $lex('$x = <<<EOT', '  Hello {$name->first} and $x \\n', "  it's", '  EOT;', 'y();');
same([T::STRING, '<<<EOT'], end($doc[0]), 'a heredoc opens');
same([[T::STRING, '  Hello '], [T::VARIABLE, '{$name->first}'], [T::STRING, ' and '], [T::VARIABLE, '$x'], [T::STRING, ' '], [T::ESCAPE, '\\n']], $doc[1], 'its text a string, its variables and escapes standing out');
same([[T::STRING, "  it's"]], $doc[2], 'an apostrophe in it no string of its own');
same([[T::TEXT, '  '], [T::STRING, 'EOT'], [T::PUNCTUATION, ';']], $doc[3], 'its label closes it, indented');
same(T::FUNCTION, $kindOf($doc[4], 'y'), 'and code goes on after it');
$now = $lex("\$y = <<<'PHP'", '  $not {$this} \\n', 'PHP . "a"; // c');
same([[T::STRING, '  $not {$this} \\n']], $now[1], 'a nowdoc\'s text all string');
same([T::STRING, T::COMMENT], [$kindOf($now[2], 'PHP'), $kindOf($now[2], '// c')], 'closed, code after its label');
[$s] = $lex('$s = "a # b <<<X";');
same([T::VARIABLE, T::STRING], [$kindOf($s, '$s'), $kindOf($s, '"a # b <<<X"')], 'a # and a <<< in a string are the string\'s');
[, $close] = $lex('<?= $x ?>', '?>');
same([[T::META, '?>']], $close, '?> a tag');
