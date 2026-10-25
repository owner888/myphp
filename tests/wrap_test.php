<?php
// Text wraps at the width layout gives it, as mygo's: in a column, beside
// what takes its room in a row, Chinese between its characters, up to its
// largest number of lines; not on one line or without wrapping; again at
// each width of the window, in the frame of the resize.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Context, Node};

$long = 'A card with a shadow and a long text that wraps across lines when the window is narrow enough to need it.';
$lineH = fn(Node $n) => $n->kind === Node::RICH ? $n->textLayout()->lines[0]->height : $n->h;
$lines = fn(Node $n) => $n->kind === Node::RICH ? count($n->textLayout()->lines) : 1;

// In a column of the window, the card's text wraps within its padding; wider, on fewer lines; in the frame of the resize.
$h = new Headless(function (Context $c) use ($long): void {
    $c->column(20, 8)->align(Node::START);
      $c->column(12, 0, 0, 0xffffff)->round(8)->label('card');
        $c->text($long)->label('text');
      $c->end();
      $c->text('After')->label('after');
    $c->end();
}, 300, 400);
$h->frame(1);
$t = $h->byLabel('text'); $card = $h->rect($h->byLabel('card'));
check($lines($t) >= 3 && $card[2] <= 300 - 40 + 0.5 && $h->rect($t)[0] + $h->rect($t)[2] <= $card[0] + $card[2] - 12 + 0.5, 'narrow: on ' . $lines($t) . ' lines, within the card: ' . json_encode([$card, $h->rect($t)]));
$after = $h->rect($h->byLabel('after'));
check($after[1] >= $card[1] + $card[3], 'what comes after, below the lines');
$was = $lines($t);
$h->w = 600; $h->frame(1);
$t = $h->byLabel('text');
check($lines($t) < $was, "wider, on {$lines($t)} lines, in the frame of the resize");
$h->w = 1200; $h->frame(1);
same(Node::TEXT, $h->byLabel('text')->kind, 'with room, on one line, as text measures it');

// In a row beside a fixed box: it takes the room left, and wraps in it.
$h = new Headless(function (Context $c) use ($long): void {
    $c->row(0, 10)->width(300)->align(Node::START);
      $c->box(100, 40, 0x333333);
      $c->text($long)->label('text');
    $c->end();
}, 400, 400);
$h->frame(1);
$r = $h->rect($h->byLabel('text'));
check($r[0] == 110.0 && $r[2] <= 190.5 && $lines($h->byLabel('text')) > 1, 'beside the box, in the 190 left: ' . json_encode($r));

// Chinese breaks between its characters; a word no line breaks in keeps its width.
$h = new Headless(function (Context $c): void {
    $c->column()->width(100)->align(Node::START);
      $c->text(str_repeat('中文排版测试', 4))->label('zh');
      $c->text(str_repeat('x', 30))->label('word');
    $c->end();
}, 400, 400);
$h->frame(1);
check($lines($h->byLabel('zh')) > 1 && $h->rect($h->byLabel('zh'))[2] <= 100.5, 'Chinese, on ' . $lines($h->byLabel('zh')) . ' lines');
check($h->rect($h->byLabel('word'))[2] > 100, 'a long word overflows, unbroken');

// At most two lines, the second ending in an ellipsis; on one line, cut; not wrapping, one line wider than the room.
$h = new Headless(function (Context $c) use ($long): void {
    $c->column(0, 4)->width(150)->align(Node::START);
      $c->text($long)->maxLines(2)->label('two');
      $c->text($long)->singleLine()->label('one');
      $c->text($long)->noWrap()->label('nowrap');
    $c->end();
}, 400, 400);
$h->frame(1);
$two = $h->byLabel('two');
check($lines($two) === 2 && $two->textLayout()->truncated, 'at most two lines, cut');
check($lines($h->byLabel('one')) === 1 && $h->rect($h->byLabel('one'))[2] <= 150.5, 'single line: one, cut to the room');
check($lines($h->byLabel('nowrap')) === 1 && $h->rect($h->byLabel('nowrap'))[2] > 150, 'not wrapping: one line, wider than the room');
