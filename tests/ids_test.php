<?php
// Node ids as widgets' parts come and go: each widget built first and a
// scroll after it, the scroll keeps its id (its place, its state) as the
// widget opens, shows an error, gets a clear button (a node's id its
// parent's mixed with its place there, as mygo's); two widgets keying
// their parts alike one after the other share no id; the video player's
// nodes keep theirs whatever is built before it.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Context, TextState, VideoPlayerState};

$st = ['open' => false, 'sel' => 'a', 'idx' => 0, 'tokens' => [], 'date' => new \DateTimeImmutable('2026-01-05 10:00'), 'rgb' => 0xff0000, 'a' => 1.0, 'n' => 3,
    'auto' => new TextState('a'), 'search' => new TextState('')];
$cases = [
    'a collapsible' => function (Context $c) use (&$st) { return $c->collapsible('More', $st['open'], function () use ($c) { $c->text('inside'); }); },
    'an accordion\'s item' => function (Context $c) use (&$st) { return $c->accordion(function () use ($c, &$st) { $c->accordionItem('A', $st['open'], function () use ($c) { $c->text('inside'); }); }); },
    'a field\'s error' => function (Context $c) use (&$st) { return $c->field('Name', fn() => $c->text('x'), '', $st['open'] ? 'Required' : ''); },
    'a search field\'s clear button' => function (Context $c) use (&$st) { return $c->searchField($st['search']); },
    'a combobox' => function (Context $c) use (&$st) { return $c->combobox($st['sel'], ['a', 'b', 'c']); },
    'a select' => function (Context $c) use (&$st) { return $c->select(['a', 'b', 'c'], $st['idx']); },
    'an autocomplete' => function (Context $c) use (&$st) { return $c->autocomplete($st['auto'], ['apple', 'apricot']); },
    'a token field' => function (Context $c) use (&$st) { return $c->tokenField($st['tokens'], ['one', 'two']); },
    'a date input' => function (Context $c) use (&$st) { return $c->dateInput($st['date']); },
    'a color well' => function (Context $c) use (&$st) { return $c->colorWell($st['rgb'], $st['a']); },
    'a menu button' => function (Context $c) { return $c->menuButton('Menu', function () use ($c) { $c->text('item'); }); },
    'a tooltip' => function (Context $c) { return $c->button('tip')->tooltip('a tooltip'); },
];
foreach ($cases as $what => $build) {
    $st['open'] = false; $st['search']->text = '';
    $sid = null; $w = null;
    $h = new Headless(function (Context $c) use ($build, &$sid, &$w) {
        $c->column(10, 10, 1);
          $w = $build($c);
          $sid = $c->scroll(0, 0)->size(100, 100)->id; $c->text('in scroll'); $c->end();
        $c->end();
    }, 600, 600);
    $h->frame();
    $ids = [$sid];
    [$x, $y, $ww, $wh] = $h->rect($w);
    $st['open'] = true;
    $h->hover($x + 5, $y + $wh / 2); $h->frame(); $ids[] = $sid;
    $h->click($x + 5, $y + $wh / 2); $h->frame(); $ids[] = $sid;
    $h->click($x + $ww - 5, $y + $wh / 2); $h->frame(); $ids[] = $sid;
    $h->type('p'); $h->frame(); $ids[] = $sid;
    check(count(array_unique($ids)) === 1, "$what opening, closing, typed into: what is built after it keeps its id " . json_encode($ids));
}

$a = false; $b = true;
$h = new Headless(function (Context $c) use (&$a, &$b) {
    $c->collapsible('One', $a, fn() => $c->text('1'));
    $c->collapsible('Two', $b, fn() => $c->text('2'));
}, 400, 300);
$h->frame(); // their parts keyed under each one's own node: no duplicate (which throws in the tests)
check($h->byText('2') !== null, 'two collapsibles one after the other: their parts apart');

$s = new VideoPlayerState();
$banner = false; $id = null;
$h = new Headless(function (Context $c) use ($s, &$banner, &$id) {
    if ($banner) { $c->text('a message above the player'); }
    $id = $c->videoPlayer($s)->id;
}, 800, 450);
$h->frame(); $first = $id;
$banner = true; $h->frame();
same($first, $id, 'the video player keeps its ids whatever the app builds before it');
