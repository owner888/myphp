<?php
// Text inside a paragraph (mygo's inline_test.go; TestInlineOnlyText is in
// theme_units_test, text_test has the spans and the boxes of a link): a
// link wrapping with its paragraph, clicked on any of its lines, reached
// by Tab and Enter and by assistive technology; inline text that clicks,
// shows a tooltip and is disabled.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{AccessNode, Context, Cursor, Role};

$url = 'https://example.com/guide';
$para = null; $link = null; $opened = [];
$h = new Headless(function (Context $c) use ($url, &$para, &$link): void {
    $c->column(10);
      $para = $c->richText([], width: 180)->children(function () use ($c, $url, &$link): void {
          $c->text('Read ');
          $link = $c->link('the documentation of the toolkit', $url);
          $c->text(' to get started.');
      });
    $c->end();
}, 200, 200);
$h->rt->openURL = function (string $u) use (&$opened): void { $opened[] = $u; };
$h->frame();
same('Read the documentation of the toolkit to get started.', $para->plainText(), 'the paragraph reads it all');
check(count($link->frags) >= 2, 'the link wraps with the paragraph: ' . count($link->frags) . ' boxes');
$first = $link->frags[0]; $last = $link->frags[count($link->frags) - 1];
$p = $h->rect($para);
check($first[0] > $p[0] && abs($first[1] - $p[1]) < 0.01 && $last[1] > $first[1], 'it goes on from "Read " and onto the next line');
$h->hover($last[0] + $last[2] / 2, $last[1] + $last[3] / 2);
same(Cursor::Pointer, $h->rt->cursor, 'the pointer over the link');
$h->hover($p[0] + 5, $first[1] + $first[3] / 2);
same(Cursor::Default, $h->rt->cursor, 'over the text around it');
$h->click($p[0] + 5, $first[1] + $first[3] / 2);
$h->click($last[0] + $last[2] / 2, $last[1] + $last[3] / 2); $h->frame();
same([$url], $opened, 'a click on its last line opens it, one on the text around it does not');
$h->tab();
same($link->id, $h->rt->focus, 'Tab reaches it');
$h->enter(); $h->frame();
same(2, count($opened), 'Enter opens it');
$h->input->push(['accessOn']); $h->frame();
$tree = $h->rt->accessTree;
$text = null; $ln = null;
foreach ($tree->nodes as $n) {
    if ($n->role === Role::TEXT && str_starts_with($n->label, 'Read the documentation')) { $text = $n; }
    if ($n->role === Role::LINK) { $ln = $n; }
}
check($text !== null && $ln !== null && $tree->nodes[$ln->parent]->id === $text->id && ($ln->actions & AccessNode::ACTION_PRESS) !== 0 && $ln->bounds[2] > 0, 'the link a node under the paragraph\'s, which it presses');
same('Read the documentation of the toolkit to get started.', $text?->label, 'the paragraph reads it all');
check(array_filter($tree->nodes, fn($n) => $n->label === 'Read ' || $n->label === ' to get started.') === [], 'the plain text inside it has no node of its own');
$h->input->push(['access', $ln->id, AccessNode::PRESS]); $h->frame();
same(3, count($opened), 'pressing the link\'s node opens it');

// Inline text that clicks, shows its tooltip over its words, and stays still disabled.
$clicks = 0; $disabled = false; $more = null; $now = 0;
$h = poseTester(function (Context $c) use (&$clicks, &$disabled, &$more): void {
    $c->richText([])->selectable()->children(function () use ($c, &$clicks, &$disabled, &$more): void {
        $c->text('Twelve files changed. ');
        $more = $c->text('Show all')->textColor($c->theme->accent)->tooltip('Lists every file');
        $more->disabled = $disabled;
        if ($c->clicked($more)) { $clicks++; }
    });
}, $now, 400, 100);
$h->frame(1);
$f = $more->frags[0];
[$x, $y] = [$f[0] + $f[2] / 2, $f[1] + $f[3] / 2];
$h->click($x, $y);
same(1, $clicks, 'a click on inline text');
$h->hover(0, 90); $h->hover($x, $y);
$now += 2_000_000_000; $h->frame(1); $h->frame(1);
same('Lists every file', $h->rt->tip[0] ?? null, 'its tooltip over its words'); // the window draws the text tips
$disabled = true; $h->frame(1);
$f = $more->frags[0];
$h->click($f[0] + $f[2] / 2, $f[1] + $f[3] / 2);
same(1, $clicks, 'disabled, it takes no click');
