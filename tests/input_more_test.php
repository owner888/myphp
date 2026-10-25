<?php
// More of mygo's tests of input (paste_test.go TestOnPaste, richtext_test.go
// TestMeasureTextAgain, gridview_test.go TestGridViewResizes): a paste the
// app takes or leaves, by the keys and the menus, and what undo makes of
// it; text measured again the same; a grid view widened, its columns and
// keys.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Context, GridState, Key, Span, TextState};

require_once __DIR__ . '/SelectionTest.php';

// A paste: left, it inserts; taken, by Cmd+V or the menus, nothing changes and the selection stays; nothing to undo; an empty clipboard seen too.
$text = new TextState('hello world'); $seen = []; $attached = 0;
$text->onPaste = function (string $s) use (&$seen, &$attached): bool {
    $seen[] = $s;
    if (strlen($s) > 5) { $attached++; return true; }
    return false;
};
$h = new Headless(function (Context $c) use ($text, &$attached): void {
    $c->column();
      $c->text("$attached attached");
      $c->textarea($text, '', 200)->label('draft');
    $c->end();
}, 300, 200);
$h->frame();
$h->clickLabel('draft');
$h->key(Key::DOWN, 'moveToEndOfDocument:', Key::COMMAND);
setClip($h, '!'); $h->input->push(['edit', 'paste']); $h->frame(); // Cmd+V: the Edit menu's Paste
same('hello world!', $text->text, 'a paste left inserts');
setClip($h, 'a large block'); $h->input->push(['edit', 'paste']); $h->frame();
check($h->byText('1 attached') !== null && $text->text === 'hello world!', 'taken by Cmd+V: nothing inserted');
$edit = function (string $command) use ($h): void { $h->input->push(['edit', $command]); $h->frame(); }; // the Edit menu's, as Cmd+A, Cmd+V and Cmd+Z go
$edit('selectAll');
$edit('paste');
check($h->byText('2 attached') !== null && $text->text === 'hello world!', 'taken by the menu');
$edit('delete');
same('', $text->text, 'the selection it left stayed');
$edit('undo');
same('hello world!', $text->text, 'undone');
$edit('undo');
same('hello world', $text->text, 'undone twice: the paste taken left nothing to undo');
setClip($h, '');
$h->input->push(['edit', 'paste']); $h->frame();
same(['!', 'a large block', 'a large block', ''], $seen, 'what it saw, an empty clipboard too');

// Measured again, the same; longer, wider; bolder and bigger, wider.
$h = new Headless(function (Context $c): void {}, 100, 100);
$h->frame();
$c = new Context($h->metrics, $h->rt, 100, 100, $h->theme);
[$short] = $c->measureText(0, new Span('000'));
[$long] = $c->measureText(0, new Span('000000'));
[$bold] = $c->measureText(0, new Span('000', size: 30, weight: 800));
[$again] = $c->measureText(0, new Span('000'));
check($again == $short && $long > $short && $bold > $short, json_encode([$short, $long, $bold, $again]));

// A grid view widened: its columns from its width, and Down moves by them.
$s = new GridState(); $s->selects = true; $s->selected = 13;
$h = new Headless(function (Context $c) use ($s): void {
    $c->column(0, 0, 1);
      $c->gridView($s, 100, 100, 80, fn(int $i) => $c->text("Item $i"))->grow = 1;
    $c->end();
}, 448, 300);
$h->frame();
same(4, $s->cols, '4 columns in 448');
$h->w = 664; $h->frame();
same(6, $s->cols, '(664 + 8) / 108: 6 columns');
$h->clickText('Item 13');
$h->down();
same(19, $s->selected, 'Down from 13 in 6 columns');

// A list's rows of buttons reorder by a drag from a button, which a press that does not move clicks (mygo's dragdrop_test.go TestDragAroundControls).
$items = ['a', 'b', 'c', 'd']; $moves = []; $clicked = '';
$ls = new \MyPHP\UI\ListState();
$ls->reorder = function (array $rows, int $to) use (&$moves): void { $moves[] = [...$rows, $to]; };
$h = new Headless(function (Context $c) use ($ls, $items, &$clicked): void {
    $c->column(0, 0, 1);
      $c->list($ls, count($items), function (int $i) use ($c, $items, &$clicked): void {
          $b = $c->buttonBase()->padding(8); $c->text($items[$i]); $c->end();
          if ($c->clicked($b)) { $clicked .= $items[$i]; }
      })->height(200);
    $c->end();
}, 300, 400);
$h->frame();
$rb = $h->rect($h->byText('b')); $rd = $h->rect($h->byText('d'));
$h->hover($rb[0], $rb[1] + $rb[3] / 2); $h->press($rb[0], $rb[1] + $rb[3] / 2);
for ($k = 1; $k <= 4; $k++) { $h->drag($rb[0], $rb[1] + $rb[3] / 2 + ($rd[1] + $rd[3] + 8 - $rb[1] - $rb[3] / 2) * $k / 4); }
$h->release($rd[0], $rd[1] + $rd[3] + 8);
check($moves === [[1, 4]] && $clicked === '', 'b dragged from its button below d: to the end, no click: ' . json_encode($moves));
$h->clickText('a');
check($clicked === 'a' && count($moves) === 1, 'a press that does not move clicks the button');
