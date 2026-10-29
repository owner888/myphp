<?php
// The Markdown parser (mygo's ui/markdown/parser tests): the CommonMark
// 0.31.2 spec's examples and GitHub's extensions; healing a streaming
// document's end; text appended a character at a time, in chunks and
// edited parsing as when parsed whole; inputs quadratic without cmark's
// guards in bounded, linear time; the bugs found reviewing it. The random
// differential tests run with ZZFUZZ=1 (ZZSEED varies them).
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\Markdown\Parser\{Block, Document, Extensions, Inline};

/** $src rendered as HTML, parsed whole with $ext (mygo's render). */
function mdRender(string $src, int $ext): string
{
    $d = new Document($ext);
    $d->set($src);
    return $d->html();
}

/** $src rendered streaming, healed (mygo's streamed). */
function mdStreamed(string $src): string
{
    $d = new Document(Extensions::DEFAULT);
    $d->setStreaming(true);
    $d->set($src);
    return $d->html();
}

/** @return list<array{markdown: string, html: string, example: int, section: string}> */
function mdSpec(string $name): array
{
    return json_decode(file_get_contents(__DIR__ . '/testdata/markdown/' . $name), true, flags: JSON_THROW_ON_ERROR);
}

/** The documents streamed in tests: an answer of a language model, and extras.md, which exercises what the spec examples do not. @return list<string> */
function mdCorpus(): array
{
    return [file_get_contents(__DIR__ . '/testdata/markdown/llm.md'), file_get_contents(__DIR__ . '/testdata/markdown/extras.md')];
}

/** The runes of $s, each its bytes. @return list<string> */
function mdRunes(string $s): array
{
    return preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
}

// The CommonMark spec, 0.31.2.
$fails = [];
foreach (mdSpec('commonmark.json') as $ex) {
    if (($got = mdRender($ex['markdown'], 0)) !== $ex['html']) {
        $fails[] = $ex['example'];
    }
}
same([], $fails, 'the CommonMark examples that fail');

// GitHub's extensions in the GFM spec.
$fails = [];
foreach (mdSpec('gfm.json') as $ex) {
    if (!str_contains($ex['section'], '(extension)') || $ex['section'] === 'Disallowed Raw HTML (extension)') {
        continue;
    }
    if (mdRender($ex['markdown'], Extensions::GFM) !== $ex['html']) {
        $fails[] = $ex['example'];
    }
}
same([], $fails, 'the GFM extension examples that fail');

// Healing.
foreach ([
    ['Some **bol', "<p>Some <strong>bol</strong></p>\n"],
    ['Some **bold** and *ita', "<p>Some <strong>bold</strong> and <em>ita</em></p>\n"],
    ['**a *b', "<p><strong>a <em>b</em></strong></p>\n"],
    ['a ~~gone', "<p>a <del>gone</del></p>\n"],
    ['run `go te', "<p>run <code>go te</code></p>\n"],
    ['run ``a`', "<p>run <code>a</code></p>\n"],
    ['hello **', "<p>hello</p>\n"],
    ['hello *', "<p>hello</p>\n"],
    ['hello `', "<p>hello</p>\n"],
    ['2*3 is', "<p>2*3 is</p>\n"],
    ['snake_case_name', "<p>snake_case_name</p>\n"],
    ['see [the docs](https://exa', "<p>see <a href=\"https://exa\">the docs</a></p>\n"],
    ['see [the docs](', "<p>see <a href=\"\">the docs</a></p>\n"],
    ['math $$x^2', "<p>math <span class=\"math display\">x^2</span></p>\n"],
    ["A paragraph\n-", "<p>A paragraph</p>\n"],
    ["A paragraph\n==", "<p>A paragraph</p>\n"],
    ["A paragraph\n---\n", "<h2>A paragraph</h2>\n"],
    ["Intro\n\n| a | b |\n", "<p>Intro</p>\n"],
    ["Intro\n\n| a | b |\n|--", "<p>Intro</p>\n"],
    ["| a | b |\n|---|---|\n| 1 | 2", "<table>\n<thead>\n<tr>\n<th>a</th>\n<th>b</th>\n</tr>\n</thead>\n<tbody>\n<tr>\n<td>1</td>\n<td>2</td>\n</tr>\n</tbody>\n</table>\n"],
    ["| a | b |\n|---|---|\n| 1 | **2", "<table>\n<thead>\n<tr>\n<th>a</th>\n<th>b</th>\n</tr>\n</thead>\n<tbody>\n<tr>\n<td>1</td>\n<td><strong>2</strong></td>\n</tr>\n</tbody>\n</table>\n"],
    ["```go\nfunc main() {", "<pre><code class=\"language-go\">func main() {\n</code></pre>\n"],
    ["caf\xc3", "<p>caf</p>\n"],
    ["Done **bold**\n\nnext", "<p>Done <strong>bold</strong></p>\n<p>next</p>\n"],
    // Found reviewing it.
    ['about ~200 keys. See', "<p>about ~200 keys. See</p>\n"],
    ['这是**重要', "<p>这是<strong>重要</strong></p>\n"],
    ['Edit **`config.ya', "<p>Edit <strong><code>config.ya</code></strong></p>\n"],
    ['Use `$$` for display math', "<p>Use <code>$$</code> for display math</p>\n"],
    ['See [Go](https://en.wikipedia.org/wiki/Go_(lang', "<p>See <a href=\"https://en.wikipedia.org/wiki/Go_(lang)\">Go</a></p>\n"],
    ['See the [Go blog', "<p>See the Go blog</p>\n"],
    ['a ~~struck~', "<p>a <del>struck</del></p>\n"],
    ['see [a](<htt', "<p>see <a href=\"htt\">a</a></p>\n"],
] as [$src, $want]) {
    same($want, mdStreamed($src), 'healed ' . json_encode($src));
}
same("<p>a **b</p>\n<p>c <strong>d</strong></p>\n", mdStreamed("a **b\n\nc **d"), 'blocks already finished show as written');

// Math a single dollar opens heals as it streams (Extensions::DOLLAR_MATH, mygo's 610df69 TestHealingDollarMath): its _ and * are TeX; money, escaped dollars and finished text as before.
$dollar = function (string $src, bool $streaming = true): string {
    $d = new Document(Extensions::DEFAULT | Extensions::DOLLAR_MATH);
    $d->setStreaming($streaming);
    $d->set($src);
    return $d->html();
};
$inline = fn(string $tex) => '<span class="math inline">' . $tex . '</span>';
foreach ([
    ['where $x_i', '<p>where ' . $inline('x_i') . "</p>\n"],
    ['where $a*b', '<p>where ' . $inline('a*b') . "</p>\n"],
    ['where $x_i^2 + y_', '<p>where ' . $inline('x_i^2 + y_') . "</p>\n"],
    ['where $\\frac{a}{b', '<p>where ' . $inline('\\frac{a}{b') . "</p>\n"],
    ['where $x \\', '<p>where ' . $inline('x') . "</p>\n"],
    ['where $x $', '<p>where ' . $inline('x') . "</p>\n"],
    ['*see $x_i', '<p><em>see ' . $inline('x_i') . "</em></p>\n"],
    ['**see $a_b', '<p><strong>see ' . $inline('a_b') . "</strong></p>\n"],
    ['$a$ and $b_', '<p>' . $inline('a') . ' and ' . $inline('b_') . "</p>\n"],
    ['see [link $x', '<p>see link ' . $inline('x') . "</p>\n"],
    ['math $$x^2', "<p>math <span class=\"math display\">x^2</span></p>\n"],
    ['math $$x_i *y', "<p>math <span class=\"math display\">x_i *y</span></p>\n"],
    ['costs $5 and *more', '<p>costs $5 and <em>more</em></p>' . "\n"],
    ['costs $ 5 and _more', '<p>costs $ 5 and <em>more</em></p>' . "\n"],
    ['costs \\$x and *more', '<p>costs $x and <em>more</em></p>' . "\n"],
    ['costs $', "<p>costs</p>\n"],
    ['costs 5$', '<p>costs 5$</p>' . "\n"],
] as [$src, $want]) {
    same($want, $dollar($src), 'streaming ' . json_encode($src));
}
same('<p>where $x_i <em>y</em></p>' . "\n", mdStreamed('where $x_i *y'), 'without the extension, a dollar is text');
foreach ([
    ['where $x_i', '<p>where $x_i</p>' . "\n"],
    ['where $a *b*', '<p>where $a <em>b</em></p>' . "\n"],
    ['costs $5 and $x$', '<p>costs $5 and ' . $inline('x') . "</p>\n"],
] as [$src, $want]) {
    same($want, $dollar($src, false), 'finished ' . json_encode($src));
}

// A pending link.
$d = new Document(Extensions::DEFAULT);
$d->setStreaming(true);
$d->set('see [docs](https://exa');
$in = $d->inlines(1);
check(count($in) >= 2 && $in[1]->kind === Inline::LINK && $in[1]->pending, 'a link being written is pending');
$d->append('mple.com) now');
$in = $d->inlines(1);
check($in[1]->kind === Inline::LINK && !$in[1]->pending && $in[1]->dest === 'https://example.com', 'written, it is a link');

// Pathological inputs, within a time bound.
$n = 30000;
foreach ([
    'mismatched openers' => [str_repeat('*a_ ', $n), Extensions::DEFAULT],
    'emphasis openers' => [str_repeat('*a ', $n), Extensions::DEFAULT],
    'unclosed links' => [str_repeat('[a](<b', $n), Extensions::DEFAULT],
    'unclosed brackets' => [str_repeat('[', $n) . 'a' . str_repeat(']', $n), Extensions::DEFAULT],
    'nested quotes' => [str_repeat('>', $n) . ' a', Extensions::DEFAULT],
    'nested lists' => [str_repeat('* ', 1000) . 'a', Extensions::DEFAULT],
    'backticks' => [str_repeat('`a``', $n), Extensions::DEFAULT],
    'unclosed comments' => [str_repeat('<!--', $n), Extensions::DEFAULT],
    'unclosed math' => [str_repeat('$$a ', $n), Extensions::DEFAULT],
    'link definitions' => [str_repeat("[a]: u\n", $n), Extensions::DEFAULT],
    'many emphasis' => [str_repeat('**a** ', $n), Extensions::DEFAULT],
    'underscores' => [str_repeat('_a ', $n) . str_repeat('a_ ', $n), Extensions::DEFAULT],
    'tables' => ["| a |\n|---|\n" . str_repeat("|\n", $n), Extensions::DEFAULT],
    'pipes' => [str_repeat('| ', $n) . "\n|" . str_repeat('-|', $n), Extensions::DEFAULT],
    'footnote brackets' => [str_repeat('[^a', 20000) . str_repeat(']', 20000), Extensions::DEFAULT],
    'dollar openers' => [str_repeat('$a ', $n), Extensions::DEFAULT | Extensions::DOLLAR_MATH],
    '\( openers' => [str_repeat('\(', 100000), Extensions::DEFAULT],
] as $name => [$src, $ext]) {
    $t0 = microtime(true);
    mdRender($src, $ext);
    $el = microtime(true) - $t0;
    check($el < 2.0, sprintf('%s took %.2fs', $name, $el));
}

// Linear time: four times as much takes about four times as long, not sixteen.
$timeOf = function (string $src): float {
    $best = INF;
    for ($k = 0; $k < 3; $k++) {
        $t0 = microtime(true);
        mdRender($src, Extensions::DEFAULT);
        $best = min($best, microtime(true) - $t0);
    }
    return $best;
};
foreach (['mismatched openers' => '*a_ ', 'emphasis openers' => '*a ', 'unclosed links' => '[a](<b', 'brackets' => '[', 'backticks' => '`a``', 'escapes' => '\*'] as $name => $unit) {
    for ($k = 0; $k < 3; $k++) {
        $small = $timeOf(str_repeat($unit, 5000)); $large = $timeOf(str_repeat($unit, 20000));
        if ($large <= 10 * $small || $large <= 0.02) {
            break;
        }
    }
    check($large <= 10 * $small || $large <= 0.02, sprintf('%s: %.3fs for 5000, %.3fs for 20000', $name, $small, $large));
}
$t0 = microtime(true); Document::parse(str_repeat("a\r", 200000)); $cr = microtime(true) - $t0;
$t0 = microtime(true); Document::parse(str_repeat("a\n", 200000)); $lf = microtime(true) - $t0;
check($cr <= 10 * $lf, sprintf('lines ending in \r alone: %.3fs, in \n: %.3fs', $cr, $lf));

// Appended a character at a time, each prefix parses as when parsed whole.
$examples = mdSpec('commonmark.json');
foreach (mdSpec('gfm.json') as $ex) {
    if (str_contains($ex['section'], '(extension)')) {
        $examples[] = $ex;
    }
}
$bad = null;
foreach ($examples as $ex) {
    foreach ([0, Extensions::DEFAULT] as $ext) {
        $d = new Document($ext);
        $src = '';
        foreach (mdRunes($ex['markdown']) as $r) {
            $d->append($r);
            $src .= $r;
            if ($d->html() !== mdRender($src, $ext)) {
                $bad ??= sprintf('example %d, ext %d, after %s', $ex['example'], $ext, json_encode($src));
                break 2;
            }
        }
    }
}
same(null, $bad, 'appended a character at a time, the first prefix parsing otherwise');
$bad = null;
foreach (mdCorpus() as $src) {
    $d = new Document(Extensions::DEFAULT);
    $at = 0; $len = strlen($src);
    foreach (mdRunes($src) as $r) {
        $d->append($r);
        $at += strlen($r);
        if (($at % 997 === 0 || $at === $len) && $d->html() !== mdRender(substr($src, 0, $at), Extensions::DEFAULT)) {
            $bad ??= "after $at bytes";
            break;
        }
    }
}
same(null, $bad, 'the corpus appended a character at a time');

// Appended in random chunks, a stream's tokens: 5 times each, mygo 20, as PHP parses some fifty times slower.
mt_srand(1);
$bad = 0;
foreach (mdCorpus() as $src) {
    $want = mdRender($src, Extensions::DEFAULT);
    for ($k = 0; $k < 5; $k++) {
        $d = new Document(Extensions::DEFAULT);
        for ($i = 0, $len = strlen($src); $i < $len;) {
            $m = min(1 + mt_rand(0, 11), $len - $i);
            $d->append(substr($src, $i, $m));
            $i += $m;
        }
        if ($d->html() !== $want) { $bad++; }
    }
}
same(0, $bad, 'the corpus appended in random chunks, parsing otherwise');

// Streamed with healing, a document parses exactly once streaming is turned off.
foreach (mdCorpus() as $src) {
    $d = new Document(Extensions::DEFAULT);
    $d->setStreaming(true);
    foreach (mdRunes($src) as $r) {
        $d->append($r);
        if ($d->tail > 0) {
            $d->inlines($d->tail); // the tail's inlines, healed (mygo renders the whole document here)
        }
    }
    $d->setStreaming(false);
    same(mdRender($src, Extensions::DEFAULT), $d->html(), 'streamed, then not');
}

// Edits at random places parse as when parsed whole: 100 a document, mygo 400.
mt_srand(3);
$pieces = ['', "\n", "\n\n", '- ', '> ', '# ', '```', '**', '*', '_', '[a]', "[a]: /u\n", '|', "| a | b |\n|---|---|\n", '    ', '1. ', "---\n", 'text', '`', '<div>', '$$', '[^1]'];
$bad = null;
foreach (mdCorpus() as $src) {
    $d = new Document(Extensions::DEFAULT);
    $d->set($src);
    for ($k = 0; $k < 100 && $bad === null; $k++) {
        $cur = $d->source();
        $from = mt_rand(0, strlen($cur));
        $to = min(strlen($cur), $from + mt_rand(0, 29));
        if (mt_rand(0, 2) === 0) {
            $to = $from;
        }
        $text = $pieces[mt_rand(0, count($pieces) - 1)];
        $next = substr($cur, 0, $from) . $text . substr($cur, $to);
        if (!mb_check_encoding($next, 'UTF-8')) {
            continue;
        }
        $d->edit($from, $to, $text);
        if ($d->source() !== $next || $d->html() !== mdRender($next, Extensions::DEFAULT)) {
            $bad = sprintf('edit [%d, %d) to %s', $from, $to, json_encode($text));
        }
    }
}
same(null, $bad, 'edits at random places parsing otherwise');

// A definition appended after its link makes the link a link, and removing it unmakes it.
$d = new Document(Extensions::DEFAULT);
$d->append("See [docs].\n\nMore text.\n\n");
check(!str_contains($d->html(), '<a '), 'no link before its definition');
$d->append("[docs]: https://example.com\n");
check(str_contains($d->html(), '<a href="https://example.com">docs</a>'), 'a link after the definition');
check($d->block(1)->revision() !== 0, "the paragraph's revision changed");
$d->set("See [docs].\n\nMore text.\n\n");
check(!str_contains($d->html(), '<a '), 'no link once its definition went');

// Found reviewing the inline parser.
foreach (['a &amp; b x@y.com', 'a \* b x@y.com'] as $src) {
    $d = Document::parse($src);
    foreach ($d->inlines(1) as $in) {
        if ($in->kind === Inline::LINK) {
            same('x@y.com', substr($d->source(), $in->start, $in->end - $in->start), "an email link's offsets in " . json_encode($src));
        }
    }
}
same("<p>&amp;copyright; &amp;notit;</p>\n", mdRender('&copyright; &notit;', Extensions::DEFAULT), "entities are names of the table followed by ;, not a legacy name's prefix");

// Found reviewing the block parser.
$d = new Document(0);
$d->set("# a\nb");
$d->set("c\n\nb");
$d->append('x');
same(mdRender("c\n\nbx", 0), $d->html(), 'an edit shrinking a heading before a quiet block, then text appended');
$d = new Document(0);
$d->set("# a\nb");
$d->set("# a\n\nb");
$d->append('x');
same(mdRender("# a\n\nbx", 0), $d->html(), 'a blank line inserted, then text appended');
$d = new Document(0);
$d->append("[a]: /u\n    b\n=\n");
$d->append('x');
same(mdRender("[a]: /u\n    b\n=\nx", 0), $d->html(), 'a setext heading whose paragraph began with a definition, then text appended');
$d = new Document(Extensions::GFM);
$d->append("e\n2) a|b\n-|-\n");
$d->append('x');
same(mdRender($d->source(), Extensions::GFM), $d->html(), 'a table from a paragraph\'s later line, then text appended');
$d = new Document(Extensions::GFM);
$d->set("e\n2) a|b\n-|-\n\nz\n");
$d->edit(14, 15, 'y');
same(mdRender($d->source(), Extensions::GFM), $d->html(), 'a table from a paragraph\'s later line, then edited');
$d = new Document(0);
$d->set("[b]: /1\n\n[b]\n\n[b]: /2\n");
$d->edit(0, 1, '');
same(mdRender($d->source(), 0), $d->html(), 'a second definition takes over when an edit removes the first');

/** Blocks and their inlines with offsets (mygo's zzDump). */
function mdDump(Document $d): string
{
    $sb = '';
    for ($i = 1; $i < $d->len(); $i++) {
        $b = $d->block($i);
        $sb .= sprintf("%d %s p%d n%d [%d,%d) open=%d L%d %d%d%s t%d task%d f%d h%d %s %s\n", $i, Block::kindName($b->kind), $b->parent, $b->next, $b->start, $b->end, $b->open, $b->level, $b->ordered, $b->number, $b->marker, $b->tight, $b->task, $b->fenced, $b->header, json_encode($b->align), json_encode($b->info));
        if ($b->kind === Block::CODE_BLOCK || $b->kind === Block::MATH_BLOCK || $b->kind === Block::HTML_BLOCK) {
            for ($k = 0; $k < $d->lineCount($i); $k++) {
                [$l, $o] = $d->line($i, $k);
                $sb .= sprintf("   line %s @%d\n", json_encode($l), $o);
            }
        }
        foreach ($d->inlines($i) ?? [] as $in) {
            $sb .= sprintf("   %s %s %s [%d,%d) n%d\n", Inline::kindName($in->kind), json_encode($in->text), json_encode($in->dest), $in->start, $in->end, $in->next);
        }
    }
    return $sb;
}

$d = new Document(0);
$d->set("a\nb\n\nc\n");
$d->html();
$d->edit(2, 2, ' ');
$f = new Document(0);
$f->set($d->source());
same(mdDump($f), mdDump($d), "an edit to a continuation line's indentation gives the paragraph's inlines new offsets");
same("<ul>\n<li>a\n<x y=\"\"></li>\n</ul>\n", mdRender("- a\n<x y=\"\">\n", 0), 'an HTML block of type 7 does not interrupt an item\'s lazy paragraph');
same("<blockquote>\n<p>a\n<x></p>\n</blockquote>\n", mdRender("> a\n<x>\n", 0), "nor a quote's");
same("y\n", Document::parse("\$\$\ny\n\$\$\n")->lines(1), "a math block's opening line adds no line");
same("x\ny\n", Document::parse("\$\$ x\ny\n\$\$\n")->lines(1), 'the TeX on it is its first');
same("<div class=\"math display\">a</div>\n<p>text</p>\n", mdRender("\\[\na\n\\]\n\ntext\n", Extensions::DEFAULT), '\[ math closes at \]');

// The random differential tests (mygo's zz tests), with ZZFUZZ=1.
if (getenv('ZZFUZZ') !== false && getenv('ZZFUZZ') !== '') {
    $zzPieces = ['', "\n", "\n\n", "\r\n", "\r", "\t", '- ', '* ', '+ ', '> ', '>', '# ', '## ', '```', '~~~', '**', '*', '_', '[a]', "[a]: /u\n", "[b]: /v \"t\"\n",
        '|', "| a | b |\n", "|---|---|\n", "a | b\n", "--- | ---\n", '    ', '  ', '1. ', '2) ', "---\n", "===\n", 'text', 'word ', '`', '<div>', "</div>\n", '<!-- ', '-->', '<pre>',
        '$$', "\$\$\n", '\[', '\]', "\\[\n", "\\]\n", '[^1]', '[^1]: ', "[^x]: note\n", "    more\n", 'é', '日本', "\u{a0}", '[ ] ', '[x] ', '<http://a.b>', 'www.x.com ', '$x$', '\(y\)'];
    $zzExt = getenv('ZZTABLES') ? Extensions::DEFAULT : Extensions::DEFAULT & ~Extensions::TABLES;
    $known = fn(string $src) => substr_count($src, '[a]:') > 1 || substr_count($src, '[b]:') > 1 || substr_count($src, '[a]') > 1 && str_contains($src, '[a]:') || substr_count($src, '[b]') > 1 && str_contains($src, '[b]:');
    $randDoc = function (int $n) use ($zzPieces): string {
        $s = '';
        for ($k = 0; $k < $n; $k++) { $s .= $zzPieces[mt_rand(0, count($zzPieces) - 1)]; }
        return $s;
    };
    mt_srand(11 + (int)getenv('ZZSEED'));
    $bad = null;
    for ($iter = 0; $iter < 3000 && $bad === null; $iter++) {
        $ext = mt_rand(0, 2) === 0 ? $zzExt | Extensions::DOLLAR_MATH : $zzExt;
        $src = $randDoc(1 + mt_rand(0, 13));
        $stream = mt_rand(0, 1) === 0;
        $d = new Document($ext);
        $d->setStreaming($stream);
        $seen = false; $got = '';
        for ($i = 0, $len = strlen($src); $i < $len;) {
            $k = min(1 + mt_rand(0, 5), $len - $i);
            $d->append(substr($src, $i, $k));
            $i += $k;
            $d->html();
            $seen = $seen || $known(substr($src, 0, $i));
            if (!$stream && $d->html() !== mdRender(substr($src, 0, $i), $ext) && !$seen) {
                $bad = "iter $iter append " . json_encode(substr($src, 0, $i));
                break;
            }
        }
        $d->setStreaming(false);
        if ($bad === null && $d->html() !== mdRender($src, $ext) && !$seen) {
            $bad = "iter $iter stream=$stream " . json_encode($src);
        }
    }
    same(null, $bad, 'random appends');
    mt_srand(21 + (int)getenv('ZZSEED'));
    $bad = null;
    for ($iter = 0; $iter < 1000 && $bad === null; $iter++) {
        $d = new Document($zzExt);
        $d->set($randDoc(1 + mt_rand(0, 19)));
        $seen = $known($d->source());
        $stream = mt_rand(0, 2) === 0;
        for ($e = 0; $e < 30; $e++) {
            $cur = $d->source();
            $from = mt_rand(0, strlen($cur));
            $to = min(strlen($cur), $from + mt_rand(0, 11));
            if (mt_rand(0, 2) === 0) { $to = $from; }
            $text = $zzPieces[mt_rand(0, count($zzPieces) - 1)];
            $next = substr($cur, 0, $from) . $text . substr($cur, $to);
            if (!mb_check_encoding($next, 'UTF-8')) { continue; }
            if ($stream) { $d->setStreaming(true); }
            if (mt_rand(0, 2) === 0) { $d->set($next); } else { $d->edit($from, $to, $text); }
            if ($stream) { $d->html(); $d->setStreaming(false); }
            $seen = $seen || $known($next);
            if (($d->source() !== $next || $d->html() !== mdRender($next, $zzExt)) && !$seen) {
                $bad = sprintf('iter %d edit %d [%d,%d)->%s prev %s', $iter, $e, $from, $to, json_encode($text), json_encode($cur));
                break;
            }
        }
    }
    same(null, $bad, 'random edits');
}
