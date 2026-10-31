<?php
// More of ui_test.go (mygo's TestClickCounts, TestTyping, TestTabFocus,
// TestAutoFocus, TestElementsGoneInARebuild, TestHoverWhilePressed): a
// small window clicked and typed in, the focus moving with Tab and taken
// by what comes, the hover held by a press.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Context, Key, Node, TextState};

// Clicks count, a check box toggles, a radio button chooses; typing, a word back, undo; Tab and Enter.
$d = (object)['count' => 0, 'agree' => false, 'choice' => '', 'name' => new TextState('')];
$h = new Headless(function (Context $c) use ($d): void {
    $c->column(20, 14, 1);
      $c->row(0, 8);
        if ($c->clicked($c->button('Increment'))) { $d->count++; }
        if ($c->clicked($c->primaryButton('Reset'))) { $d->count = 0; }
        $c->text("Count: {$d->count}");
      $c->end();
      $c->row(0, 16);
        $c->checkbox('I agree', $d->agree);
        $c->radio('Alpha', $d->choice, 'a');
        $c->radio('Beta', $d->choice, 'b');
      $c->end();
      $c->input($d->name, 'Your name', 0)->label('name');
    $c->end();
}, 640, 600);
$h->frame();
for ($k = 0; $k < 3; $k++) { $h->clickText('Increment'); }
check($d->count === 3 && $h->byText('Count: 3') !== null, 'three clicks counted');
$h->clickText('I agree');
check($d->agree, 'the check box toggled');
$h->clickText('Beta');
same('b', $d->choice, 'the radio button chose');
$h->clickOn($h->byLabel('name'));
$h->type('Héllo wörld');
same('Héllo wörld', $d->name->text, 'typed');
$h->key(Key::DELETE, 'deleteBackward:');
$h->key(Key::LEFT, 'moveWordLeft:', Key::OPTION);
$h->type('big ');
same('Héllo big wörl', $d->name->text, 'a word back, typed there');
$d->name->undo(); $h->frame();
same('Héllo wörl', $d->name->text, 'undo takes the typing back');
$h->rt->focus = null; $h->frame();
$h->tab();
same('Increment', $h->focused()?->text ?? \MyPHP\Tests\Headless::label($h->focused()), 'Tab: the first button');
$d->count = 0; $h->enter();
same(1, $d->count, 'Enter on it clicks it');
$h->tab();
check(str_contains(Headless::label($h->focused()), 'Reset'), 'Tab: the next button');

// An input asking for the focus in a dialog takes it as the dialog opens, and again as it opens again.
$open = false; $name = new TextState('');
$h = new Headless(function (Context $c) use (&$open, $name): void {
    if ($c->clicked($c->button('Rename'))) { $open = true; }
    $c->modal($open, function () use ($c, &$open, $name): void {
        $c->input($name)->label('Name')->autoFocus();
        if ($c->clicked($c->button('Done'))) { $open = false; }
    });
}, 400, 300);
$h->frame();
$h->clickText('Rename');
same('Name', $h->focused()?->label, 'the input of the dialog has the focus');
$h->type('Ada');
$h->tab();
check(str_contains(Headless::label($h->focused()), 'Done'), 'Tab moves on from the input');
$h->enter();
check(!$open && $name->text === 'Ada', 'Enter on Done closes it, the name typed');
$h->clickText('Rename');
same('Name', $h->focused()?->label, 'reopened, the input has the focus again');

// A field asking for the focus takes it again when it comes back.
$text = new TextState(''); $open = true; $opener = null;
$h = new Headless(function (Context $c) use ($text, &$open, &$opener): void {
    $c->column(0, 8);
      $opener = $c->button('Open');
      if ($c->clicked($opener)) { $open = true; }
      if ($open) {
          $c->input($text)->autoFocus()->label('Field');
          if ($c->clicked($c->button('Close'))) { $open = false; $c->runtime->focusOn($opener->id); }
      }
    $c->end();
}, 300, 200);
$h->frame();
same('Field', $h->focused()?->label, 'the field takes the focus at first');
$h->clickText('Close');
$h->enter(); // on Open
check($open && $h->focused()?->label === 'Field', 'the field that came back takes the focus again');

// A press holds the hover: the row keeps it while its button is pressed; pressed elsewhere, nothing hovers until the release.
$rowHovered = false; $otherHovered = false; $removes = 0; $keeps = 0;
$h = new Headless(function (Context $c) use (&$rowHovered, &$otherHovered, &$removes, &$keeps): void {
    $c->column();
      $row = $c->row()->size(200, 40);
      $rowHovered = $c->hovered($row);
        if ($c->clicked($c->button('Keep'))) { $keeps++; }
        $c->spacer();
        if ($rowHovered && $c->clicked($c->button('Remove'))) { $removes++; }
      $c->end();
      $other = $c->box(200, 40, 0);
      $otherHovered = $c->hovered($other);
    $c->end();
}, 300, 200);
$h->frame();
$h->hover(150, 20);
$press = function (string $text) use ($h): array { [$x, $y] = $h->middle($h->byText($text)); $h->press($x, $y); return [$x, $y]; };
[$x, $y] = $press('Remove');
check($rowHovered, 'pressing the button keeps the row around it hovered');
$h->release($x, $y);
same(1, $removes, 'the button over the row clicked');
$press('Keep');
$h->drag(20, 60);
check(!$rowHovered && !$otherHovered, 'a button pressed, the pointer elsewhere: neither hovers');
$h->release(20, 60);
check($otherHovered && $keeps === 0, 'after the release, what is under the pointer hovers; no click');
$h->hover(150, 20);
[$x, $y] = $press('Remove');
$h->drag(20, 60);
check(!$rowHovered && $otherHovered && $h->byText('Remove') === null, 'the button over the row gone with the hover: what is under the pointer hovers');
$h->release(20, 60);
same(1, $removes, 'released away from it: no click');
// A press on what is inside a node, in a part of the window that takes presses around it, keeps that node hovered, as the nodes under the pointer as the press began do (mygo's Hovered): a page's scroll bars stayed hidden while a press was held on it.
$inner = false;
$h = new Headless(function (Context $c) use (&$inner): void {
    $outer = $c->column(0, 0, 1)->fill();
    $c->clicked($outer); // takes presses around what is inside it
      $box = $c->column(20, 0)->size(200, 100);
        $c->text('Inside');
      $c->end();
      $inner = $c->hovered($box);
    $c->end();
}, 300, 200);
$h->frame();
$h->hover(50, 50); $h->frame();
$h->press(50, 50); $h->frame();
check($inner, 'pressed in it, a node around the point pressed stays hovered');
$h->drag(250, 150); $h->frame();
check(!$inner, 'and hovers no more as the pointer leaves it');
$h->release(250, 150);
