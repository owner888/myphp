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

// The wheel a handler leaves goes to the handlers around it (mygo's 8e7c49c): an editor scrolls under what shields its presses;
// a handler around a scrolling container sees it before the container moves.
$wheeled = []; $zoom = false;
$h = new Headless(function (Context $c) use (&$wheeled, &$zoom): void {
    $c->column()->size(300, 200)->label('editor')->handleInput(function (\MyPHP\UI\InputEvent $e) use (&$wheeled): bool {
        if ($e->kind !== \MyPHP\UI\InputEvent::SCROLL) { return false; }
        $wheeled[] = 'editor'; return true;
    });
      $c->column()->size(100, 40)->label('annotation')->handleInput(fn(\MyPHP\UI\InputEvent $e): bool => $e->kind === \MyPHP\UI\InputEvent::POINTER_DOWN); // takes its presses, not the wheel
      $c->end();
    $c->end();
    $c->column()->size(300, 200)->label('canvas')->handleInput(function (\MyPHP\UI\InputEvent $e) use (&$wheeled, &$zoom): bool {
        if ($e->kind !== \MyPHP\UI\InputEvent::SCROLL || !$zoom) { return false; }
        $wheeled[] = 'canvas'; return true;
    });
      $c->scroll()->size(300, 200)->label('scroller');
        for ($i = 0; $i < 30; $i++) { $c->text("line $i")->height(20); }
      $c->end();
    $c->end();
}, 400, 500);
$h->frame();
[$x, $y] = $h->middle($h->byLabel('annotation'));
$h->input->push(['scroll', $x, $y, 0, -30]); $h->frame();
same(['editor'], $wheeled, 'the wheel over what takes only presses: the handler around it scrolls');
[$x, $y] = $h->middle($h->byLabel('scroller'));
$zoom = true;
$h->input->push(['scroll', $x, $y, 0, -30]); $h->frame();
same(['editor', 'canvas'], $wheeled, 'a handler around a scrolling container takes the wheel first');
same(0.0, $h->byLabel('scroller')->scroll->y, 'and the container stays');
$zoom = false;
$h->input->push(['scroll', $x, $y, 0, -30]); $h->frame();
check($h->byLabel('scroller')->scroll->y > 0, 'left by it: the container scrolls');

// The Edit menu's Paste, which takes Command+V before the window sees it, runs the window's Cmd+V where no field has the
// focus, and pastes into one that does (mygo's TestPasteCommandShortcut).
$typed = new TextState(''); $pastes = 0;
$h = new Headless(function (Context $c) use ($typed, &$pastes): void {
    $c->input($typed)->width(200)->label('field');
    if ($c->shortcut('v', Key::COMMAND)) { $pastes++; }
}, 300, 100);
$h->frame();
$h->input->push(['edit', 'paste']); $h->frame();
same(1, $pastes, 'the menu\'s Paste, no field focused: the window\'s Cmd+V');
$h->clickLabel('field');
setClip($h, 'hi'); $h->input->push(['edit', 'paste']); $h->frame();
same(['hi', 1], [$typed->text, $pastes], 'a field focused: pasted into it, the shortcut not run');
