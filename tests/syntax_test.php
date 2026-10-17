<?php
// Syntax (mygo's syntax_test): the tokenizers of the languages, how a
// file's language is found, and the token highlighter keeping the states
// of lines right through random edits.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\Editor\{Buffer, Edit, Languages, Token, Tokenizer, TokenHighlighter};

$names = ['text', 'kw', 'type', 'fn', 'var', 'prop', 'const', 'num', 'str', 'esc', 're', 'comment', 'op', 'punct', 'tag', 'attr', 'meta', 'heading', 'em', 'strong', 'link', 'code', 'ins', 'del', 'invalid'];
/** Tokenizes lines and writes each token as kind(text), leaving out spaces. */
$lexLines = function (Tokenizer $t, string ...$lines) use ($names): string {
    $out = []; $state = 0;
    foreach ($lines as $i => $line) {
        [$toks, $state] = $t->tokenize($line, $state, []);
        $from = 0; $s = '';
        foreach ($toks as [$end, $kind]) {
            if ($end <= $from || $end > strlen($line)) { return "token ($end, $kind) after $from in " . json_encode($line); }
            $text = substr($line, $from, $end - $from);
            $from = $end;
            if (trim($text) === '') { continue; }
            $s .= $names[$kind] . '(' . trim($text) . ')';
        }
        if ($from !== strlen($line)) { return "tokens end at $from of " . json_encode($line); }
        $out[] = $s;
    }
    return implode(' / ', $out);
};

foreach ([
    ['go', ['func main() { fmt.Println("hi\n", 42) } // done'],
        'kw(func)fn(main)punct(())punct({)var(fmt)punct(.)fn(Println)punct(()str("hi)esc(\n)str(")punct(,)num(42)punct())punct(})comment(// done)'],
    ['go', ['x := `raw', 'still` + 1 /* a', 'b */'],
        'var(x)op(:=)str(`raw) / str(still`)op(+)num(1)comment(/* a) / comment(b */)'],
    ['ts', ['const s = `a${b + `c${d}`}e`'],
        'kw(const)var(s)op(=)str(`a)esc(${)var(b)op(+)str(`c)esc(${)var(d)esc(})str(`)esc(})str(e`)'],
    ['js', ['x = /a[/]b/g.test(y)'],
        'var(x)op(=)re(/a[/]b/g)punct(.)fn(test)punct(()var(y)punct())'],
    ['python', ['@app.route("/")', 'def f(x): return f"{x!r}" + r\'\d\'  # c'],
        'meta(@app.route)punct(()str("/")punct()) / kw(def)fn(f)punct(()var(x)punct())op(:)kw(return)str(f")esc({)var(x)op(!)var(r)esc(})str(")op(+)str(r\'\d\')comment(# c)'],
    ['json', ['{"a": [1, true, "b"]}'],
        'punct({)prop("a")op(:)punct([)num(1)punct(,)const(true)punct(,)str("b")punct(]})'],
    ['rust', ['/* a /* b */ c */ fn x() {}'],
        'comment(/* a /* b */ c */)kw(fn)fn(x)punct(())punct({})'],
    ['c', ['#include <stdio.h>', 'int x = 0x1fu;'],
        'meta(#include)op(<)var(stdio)punct(.)prop(h)op(>) / type(int)var(x)op(=)num(0x1fu)punct(;)'],
    ['css', ['.a:hover, #b { color: red; margin: -4px; }'],
        'type(.a)kw(:hover)punct(,)type(#b)punct({)prop(color)punct(:)const(red)punct(;)prop(margin)punct(:)num(-4px)punct(;)punct(})'],
    ['html', ['<div class="x">a &amp; b<script>let y = 1</script>'],
        'punct(<)tag(div)attr(class)op(=)str("x")punct(>)text(a)esc(&amp;)text(b)punct(<)tag(script)punct(>)kw(let)var(y)op(=)num(1)punct(</)tag(script)punct(>)'],
    ['markdown', ['# Title', 'Some *em* and **strong** `code` [link](url)', '```go', 'func x() {}', '```'],
        'heading(# Title) / text(Some)em(*em*)text(and)strong(**strong**)code(`code`)link([link])str((url)) / punct(```)type(go) / kw(func)fn(x)punct(())punct({}) / punct(```)'],
    ['diff', ['@@ -1,2 +1,2 @@ func x', '-a', '+b', ' c'],
        'kw(@@ -1,2 +1,2 @@)fn(func x) / del(-a) / ins(+b) / text(c)'],
    ['yaml', ["key: 'v' # c", '- true'],
        "prop(key)op(:)str('v')comment(# c) / op(-)const(true)"],
    ['toml', ['[server]', 'port = 8080'],
        'type([server]) / prop(port)op(=)num(8080)'],
    ['sh', ['echo "$HOME/x" $1 # c'],
        'fn(echo)str(")var($HOME)str(/x")var($1)comment(# c)'],
    ['sql', ["SELECT id FROM users WHERE x = 'a'"],
        "kw(SELECT)var(id)kw(FROM)var(users)kw(WHERE)var(x)op(=)str('a')"],
] as [$lang, $lines, $want]) {
    $l = Languages::named($lang);
    check($l !== null, "a language named $lang");
    same($want, $lexLines($l->tokenizer, ...$lines), "$lang tokens of " . json_encode($lines));
}

foreach (['main.go' => 'Go', 'a/b/Makefile' => 'Makefile', 'x.TSX' => 'TypeScript', 'Dockerfile' => 'Dockerfile', 'x.unknown' => ''] as $name => $want) {
    same($want, Languages::for($name)?->name ?? '', "the language of $name");
}
same(28, count(Languages::all()), 'the languages');

// After any edits, the states the highlighter keeps are those of tokenizing the text from the top.
mt_srand(78);
$pieces = ['x', "\n", '/*', '*/', '`', '"', ' ', 'func', '{', '}', "// c\n", '${', "a\nb"];
$b = new Buffer(str_repeat("func f() {\n\tx := `a\nb` /* c */\n}\n", 20));
$b->setLanguage(Languages::named('go'));
$ok = true;
for ($round = 0; $round < 300 && $ok; $round++) {
    if ($round % 3 === 0) { $b->setLanguage(Languages::named(['go', 'javascript', 'markdown'][intdiv($round, 3) % 3])); }
    $text = $b->rope();
    for ($e = mt_rand(1, 3); $e > 0; $e--) {
        $at = mt_rand(0, $text->len()); $end = min($text->len(), $at + mt_rand(0, 3));
        $b->applyEdits(new Edit($at, $end, $pieces[mt_rand(0, count($pieces) - 1)]));
        $text = $b->rope();
    }
    $s = $b->highlighter();
    if (!$s instanceof TokenHighlighter) { $ok = false; break; }
    // Sometimes only part of the text.
    $upTo = mt_rand(0, 1) === 0 ? mt_rand(0, $text->lineCount() - 1) : $text->lineCount() - 1;
    if (!$s->highlight($upTo, 3600.0)) { $ok = false; break; }
    $state = 0;
    $tok = $b->language()->tokenizer;
    for ($i = 0; $i <= $upTo; $i++) {
        [$got, $known] = $s->stateOf($i);
        if (!$known || $got !== $state) { $ok = false; break 2; }
        [, $state] = $tok->tokenize($text->line($i), $state, []);
    }
}
check($ok, 'the highlighter\'s states are those of tokenizing from the top, through 300 rounds of edits and language changes');

// A budget stops a pass, which the next goes on from; tokens before the frontier are final.
$b = new Buffer(str_repeat("x /* c\n*/ y\n", 2000));
$b->setLanguage(Languages::named('c'));
$h = $b->highlighter();
check(!$h->highlight(3999, 0.0), 'a pass with no budget stops');
check($h->highlight(3999, 3600.0), 'and the next finishes');
[$toks, $final] = $h->tokens(1, '*/ y', []);
same([[[2, Token::COMMENT], [3, Token::TEXT], [4, Token::VARIABLE]], true], [$toks, $final], 'the tokens of a line inside a comment, final');
