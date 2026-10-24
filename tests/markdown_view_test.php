<?php
// The Markdown view (mygo's ui/markdown view_test.go): what it shows, its
// links, its code blocks' copy button, copying a selection as text and as
// Markdown, streaming and revealing text, its theme, slugs, fading.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Context, Key, Node};
use MyPHP\UI\Markdown\{Markdown, Options, Reveal, RevealState, RevealUnit, Theme, ViewState};

$sample = "# Title\n\nSome **bold** and a [link](https://go.dev), `code`.\n\n" .
    "- one\n- two\n\n| a | b |\n|---|---|\n| 1 | 2 |\n\n```go\nfunc main() {}\n```\n\nLast [bad](javascript:alert(1)) one.\n";

$view = fn(string $src, ?Options $o = null, float $w = 600, float $h = 800) => new Headless(function (Context $c) use ($src, $o) { Markdown::view($c, $src, $o); }, $w, $h);
$hasText = fn(Headless $h, string $s) => in_array($s, $h->texts(), true);

// What it shows.
$h = $view($sample); $h->frame();
foreach (['Title', 'Some bold and a link, code.', 'one', 'two', 'a', 'b', '1', '2', 'func main() {}', 'Copy', 'go'] as $s) {
    check($hasText($h, $s), "the view shows \"$s\" (" . json_encode($h->texts(), JSON_UNESCAPED_UNICODE) . ')');
}

// Links: a safe one opens, one of another scheme does nothing; with onLink, every link reaches it.
$opened = [];
$h = $view($sample); $h->rt->openURL = function (string $url) use (&$opened) { $opened[] = $url; }; $h->frame();
$h->clickText('link');
$h->clickText('bad');
same(['https://go.dev'], $opened, 'the safe link opens, javascript: does not');
$clicked = []; $opened = [];
$o = new Options(onLink: function (string $url) use (&$clicked) { $clicked[] = $url; });
$h = $view($sample, $o); $h->rt->openURL = function (string $url) use (&$opened) { $opened[] = $url; }; $h->frame();
$h->clickText('bad');
same([['javascript:alert(1)'], []], [$clicked, $opened], 'onLink gets the link, nothing opens');

// A code block's copy button copies its code, and says so.
$h = $view($sample); $h->frame();
$h->clickText('Copy');
same("func main() {}\n", ($h->rt->readClipboard)(), 'Copy copies the code');
check($hasText($h, 'Copied'), 'and shows Copied');

// Select All and Copy as text: blank lines between paragraphs, newlines between a tight list's items, tabs between cells; Shift+Cmd+C as Markdown.
$src = "# Title\n\nFirst **para**.\n\n- one\n- two\n\n| a | b |\n|---|---|\n| 1 | 2 |\n";
$h = $view($src); $h->frame();
$n = $h->byText('First para.');
$h->click($n->x + 2, $n->y + $n->h / 2);
$h->input->push(['edit', 'selectAll']); $h->frame();
$h->input->push(['edit', 'copy']); $h->frame();
same("Title\n\nFirst para.\n\none\ntwo\n\na\tb\n1\t2", ($h->rt->readClipboard)(), 'copied as text');
$h->shortcut('c', Key::COMMAND | Key::SHIFT);
same(rtrim($src, "\n"), ($h->rt->readClipboard)(), 'copied as Markdown');

// Copying part of strong text as Markdown completes its syntax.
$h = $view('Some **bold text**', null, 600, 400); $h->frame();
$n = $h->byText('Some bold text');
$y = $n->y + $n->h / 2;
$h->press($n->x + 37, $y); // inside "bold"
$h->drag($n->x + $n->w - 1, $y);
$h->release($n->x + $n->w - 1, $y);
$h->shortcut('c', Key::COMMAND | Key::SHIFT);
$got = ($h->rt->readClipboard)();
check(str_starts_with($got, '**') && str_ends_with($got, 'text**') && !str_contains($got, 'Some'), "copied as Markdown \"$got\": the end of the strong text, completed");

// A document streamed a character at a time shows as the whole one does.
$doc = file_get_contents(__DIR__ . '/testdata/markdown/extras.md');
$o = new Options(reveal: new Reveal(RevealUnit::AtOnce));
$text = ''; $done = false;
$h = new Headless(function (Context $c) use (&$text, &$done, $o) { $done ? Markdown::view($c, $text, $o) : Markdown::stream($c, $text, $o); }, 700, 3000);
$chars = preg_split('//u', $doc, -1, PREG_SPLIT_NO_EMPTY);
$at = 0;
foreach ($chars as $k => $ch) {
    $text .= $ch;
    if ($k % 7 === 0) { $h->frame(1); }
}
$done = true; $h->frame();
$whole = $view($doc, $o, 700, 3000); $whole->frame();
same($whole->texts(), $h->texts(), 'streamed, it shows as the whole document does');

// A burst comes in over frames, all of it once the stream is done.
$now = 0;
$text = ''; $done = false; $o = new Options();
$h = new Headless(function (Context $c) use (&$text, &$done, $o) { $done ? Markdown::view($c, $text, $o) : Markdown::stream($c, $text, $o); }, 600, 400);
$h->rt->clock = function () use (&$now) { return $now; };
$text = str_repeat('word ', 200);
$now += 16_000_000; $h->frame(1);
$first = strlen(implode('', $h->texts()));
check($first < strlen($text) - 1, "a burst does not show at once ($first of " . strlen($text) . ')');
$now += 60_000_000; $h->frame(1);
check(strlen(implode('', $h->texts())) > $first, 'more shows after a while');
$done = true;
for ($k = 0; $k < 100 && implode('', $h->texts()) !== trim($text); $k++) { $now += 20_000_000; $h->frame(1); }
same(trim($text), implode('', $h->texts()), 'all of it once done');

// A selection survives text streaming in.
$text = "First paragraph here.\n\nSecond one is being";
$o = new Options(reveal: new Reveal(RevealUnit::AtOnce));
$h = new Headless(function (Context $c) use (&$text, $o) { Markdown::stream($c, $text, $o); }, 600, 400);
$h->frame();
$n = $h->byText('First paragraph here.');
$h->click($n->x + 2, $n->y + $n->h / 2);
$h->input->push(['edit', 'selectAll']); $h->frame();
$text .= ' written, word by word.';
$h->frame();
$h->input->push(['edit', 'copy']); $h->frame();
check(str_starts_with(($h->rt->readClipboard)(), "First paragraph here.\n\nSecond one is being"), 'the selection survives streaming: ' . json_encode(($h->rt->readClipboard)()));

// A theme sets what it changes; the rest is the default's.
$th = new Theme(link: [0xff0000, 1.0], fontSize: 20);
$def = Theme::defaultTheme(\MyPHP\UI\Theme::light());
$got = Theme::resolve($th, $def);
check($got->link === [0xff0000, 1.0] && $got->fontSize == 20 && $got->text === $def->text && $got->headings[0]->size == $def->headings[0]->size, 'a theme over the default');

foreach (['Hello, World!' => 'hello-world', 'API v2 — notes' => 'api-v2--notes', 'foo_bar-baz' => 'foo_bar-baz'] as $in => $want) {
    same($want, Markdown::slug($in), "slug of \"$in\"");
}

// Text revealed later is more transparent.
$now = 1_000_000_000_000;
$rs = new RevealState();
$rs->marks = [[0, $now - 250_000_000], [5, $now - 150_000_000], [8, $now - 20_000_000]];
$f = $rs->fadeAt($now, false, null);
check($f(1) > $f(6) && $f(6) > $f(9), 'opacities decrease with the time revealed');
same($f(9), $f(100), 'text after the last mark is as transparent as it');

// Sentences end at a period and a space, a question mark, an ideographic full stop, a line's end; not before the space comes.
foreach ([['Pi is 3.14 today. Next', strlen('Pi is 3.14 today. ')], ['He said "go." Then', strlen('He said "go." ')], ['Really? Yes', strlen('Really? ')],
    ['一句话。下一句', strlen('一句话。')], ["- an item\n- next", strlen("- an item\n")], ['Not done yet', -1], ['Ends here.', -1]] as [$s, $want]) {
    same($want, RevealState::unitEnd($s, 0, strlen($s), RevealUnit::BySentence), "the sentence of \"$s\" ends");
}

// By sentence, only complete sentences show while more is coming; all once done.
$now = 0; $text = ''; $done = false;
$o = new Options(reveal: new Reveal(RevealUnit::BySentence, catchUp: 0.001));
$h = new Headless(function (Context $c) use (&$text, &$done, $o) { $done ? Markdown::view($c, $text, $o) : Markdown::stream($c, $text, $o); }, 600, 400);
$h->rt->clock = function () use (&$now) { return $now; };
$text = 'First sentence here. Second one is still';
for ($k = 0; $k < 10; $k++) { $now += 10_000_000; $h->frame(1); }
same('First sentence here.', implode('', $h->texts()), 'streaming shows the complete sentence');
$done = true;
for ($k = 0; $k < 10; $k++) { $now += 10_000_000; $h->frame(1); }
same($text, implode('', $h->texts()), 'done, it shows all');

check(ViewState::linkAllowed('https://go.dev', false) && !ViewState::linkAllowed('/docs', false) && ViewState::linkAllowed('/docs', true) && !ViewState::linkAllowed('//evil', true), 'links allowed without onLink');

// Copying as Markdown where its syntax cuts the selection (mygo's review_view_test.go).
$rv = function (string $src) {
    $root = null;
    $h = new Headless(function (Context $c) use ($src, &$root) { $root = Markdown::view($c, $src); }, 700, 3000);
    $h->frame();
    return [$h, $h->rt->local($root->id, fn() => null)];
};
$elemOf = function (ViewState $st, int $block): int {
    foreach ($st->elems as $e) { if ($e[1] === $block) { return $e[0]; } }
    throw new \RuntimeException("no node of block $block");
};
$paraText = fn(ViewState $st, int $i) => implode('', array_map(fn($pc) => implode('', array_map(fn($s) => $s->text, $pc[1])), $st->paras[$i]['p']->pieces));
$part = fn(int $id, string $text, int $from, int $to) => new \MyPHP\UI\SelectedText($id, $text, $from, $to);

[, $st] = $rv('foo *bar* baz');
same('foo bar baz', $paraText($st, 1), 'emphasis: the text');
same('foo ', $st->copyMarkdown([$part($elemOf($st, 1), 'foo bar baz', 0, 4)]), 'a selection ending before emphasis copies no stray closer');

[$h] = $rv('foo *bar* baz');
$n = $h->byText('foo bar baz'); $y = $n->y + $n->h / 2; $found = false;
for ($dx = 10; $dx < 40 && !$found; $dx += 0.5) {
    [$h] = $rv('foo *bar* baz');
    $n = $h->byText('foo bar baz');
    $h->press($n->x + 0.5, $y); $h->drag($n->x + $dx, $y); $h->release($n->x + $dx, $y);
    $h->input->push(['edit', 'copy']); $h->frame();
    if (($h->rt->readClipboard)() !== 'foo ') { continue; }
    $found = true;
    $h->shortcut('c', Key::COMMAND | Key::SHIFT);
    same('foo ', ($h->rt->readClipboard)(), 'dragged over "foo ", copied as Markdown');
}
check($found, 'a drag selected "foo "');

[, $st] = $rv('Tom &amp; Jerry *ran* home');
same('Tom & Jerry ran home', $paraText($st, 1), 'an entity: the text');
same('Jerry', $st->copyMarkdown([$part($elemOf($st, 1), 'Tom & Jerry ran home', 6, 11)]), 'a selection after an entity maps to its source');

[, $st] = $rv("para\n- item\n");
same('para', $st->copyMarkdown([$part($elemOf($st, 1), 'para', 0, 4)]), 'a paragraph selected to its end copies no next line');

$lines = array_map(fn($i) => "line$i", range(0, 39));
[, $st] = $rv("```\n" . implode("\n", $lines) . "\n```\n");
$chunk = implode("\n", array_slice($lines, 0, 32));
same(implode("\n", array_slice($lines, 1, 31)), $st->copyMarkdown([$part($elemOf($st, 1), $chunk, 6, mb_strlen($chunk))]), 'lines 1 to 32 of a 40-line block copy those');

$src = "[foo]\n"; $grown = null;
$h = new Headless(function (Context $c) use (&$src) { Markdown::view($c, $src); }, 700, 3000);
$h->frame();
check(in_array('[foo]', $h->texts(), true), 'an undefined reference shows as text');
$src = "[foo]\n\n[foo]: https://go.dev\n"; $h->frame();
[$whole] = $rv($src);
same(implode('|', $whole->texts()), implode('|', $h->texts()), 'a paragraph becomes a link once its reference comes');

$now = 0; $frames = 0;
$o = new Options(reveal: new Reveal(RevealUnit::ByLine));
$h = new Headless(function (Context $c) use (&$frames, $o) { $frames++; Markdown::stream($c, 'Hello world, no newline yet', $o); }, 600, 400);
$h->rt->clock = function () use (&$now) { return $now; };
$h->frame();
$now += 400_000_000; $h->frame(1);
check(!$h->rt->needsFrame, 'by line, waiting for a line end asks for no more frames');

$opened = [];
[$h] = $rv('[a](calc) [b](\\\\\\\\evil\\\\share\\\\x.exe)');
$h->rt->openURL = function (string $u) use (&$opened) { $opened[] = $u; };
$h->clickText('a'); $h->clickText('b');
same([], $opened, 'links without a scheme open nothing outside a router');

$th = Theme::resolve(new Theme(text: [0xc80000, 1.0]), Theme::defaultTheme(\MyPHP\UI\Theme::light()));
same($th->text, $th->headings[0]->color, "a heading's color follows the text's");

[, $st] = $rv('x `hello world` y');
same('`world`', $st->copyMarkdown([$part($elemOf($st, 1), $paraText($st, 1), 8, 13)]), 'part of a code span copies inside its ticks');

[, $st] = $rv('1\\. first thing *here*');
same('first', $st->copyMarkdown([$part($elemOf($st, 1), $paraText($st, 1), 3, 8)]), 'after an escape');

[, $st] = $rv("| a | b |\n|---|---|\n| 1 | 2 |\n");
$cell = 0;
for ($i = 1; $i < $st->doc->len(); $i++) {
    $b = $st->doc->block($i);
    if ($b->kind === \MyPHP\UI\Markdown\Parser\Block::TABLE_CELL && substr($st->doc->source(), $b->start, $b->end - $b->start) === '1') { $cell = $i; }
}
same('1', $st->copyMarkdown([$part($elemOf($st, $cell), '1', 0, 1)]), 'a cell selected whole copies itself');

[, $st] = $rv("- foo\n\n\t\tbar\n");
$code = 0;
for ($i = 1; $i < $st->doc->len(); $i++) { if ($st->doc->block($i)->kind === \MyPHP\UI\Markdown\Parser\Block::CODE_BLOCK) { $code = $i; } }
[$line] = $st->doc->line($code, 0);
$len = mb_strlen($line);
same('ba', $st->copyMarkdown([$part($elemOf($st, $code), $line, $len - 3, $len - 1)]), 'a code line with a tab partly taken by indentation');
