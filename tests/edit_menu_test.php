<?php
// The Edit menu's items enabled only while the focus can act on them
// (mygo's TestEditMenuEnabled and TestEditMenuEnabledSelectable): a text
// input as its own menu says, a read-only one Copy and Select All, a
// password neither Cut nor Copy, a node taking its input every command,
// what the focus leaves when a shortcut of its key would take it, a
// disabled input or paragraph none; Select All of selectable text counts
// its container's paragraphs.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Context, Key, TextState};

require_once __DIR__ . '/SelectionTest.php';

$value = new TextState(''); $notes = new TextState('read only'); $pin = new TextState('1234');
$notes->readOnly = true; $pin->readOnly = true;
$disabled = false;
$t = new Headless(function (Context $c) use ($value, $notes, $pin, &$disabled): void {
    $c->shortcut('v', Key::COMMAND); // the window's Cmd+V
    $c->column(0, 8);
      $c->input($value)->label('field')->disabled($disabled);
      $c->input($notes)->label('notes');
      $c->input($pin)->label('pin')->password();
      $c->column()->size(100, 20)->focusable()->label('list')->onShortcut('c', Key::COMMAND, function (): void {});
      $c->end();
      $c->column()->size(100, 20)->focusable()->label('terminal')->handleInput(fn() => false);
      $c->end();
    $c->end();
}, 300, 240);
$t->frame();
$enabled = function (array $want) use ($t): array {
    $got = [];
    foreach ($want as $name => $_) { $got[$name] = $t->rt->commandEnabled($name); }
    return $got;
};
$check = function (string $where, array $want) use ($enabled): void { same($want, $enabled($want), $where); };

$check('no focus, with the window\'s Cmd+V', ['copy' => false, 'paste' => true, 'selectAll' => false]);
$t->clickLabel('field'); $t->frame();
$check('an empty text input', ['undo' => false, 'redo' => false, 'cut' => false, 'copy' => false, 'paste' => true, 'pasteAndMatchStyle' => true, 'delete' => false, 'selectAll' => false]);
$t->type('hi');
$t->input->push(['edit', 'selectAll']); $t->frame();
$check('a text input with its text selected', ['undo' => true, 'redo' => false, 'cut' => true, 'copy' => true, 'delete' => true, 'selectAll' => true]);
$t->input->push(['edit', 'undo']); $t->frame();
$check('a text input after undo', ['undo' => false, 'redo' => true]);

$t->clickLabel('notes'); $t->frame();
$t->input->push(['edit', 'selectAll']); // a menu asks before the next frame takes the command
$check('a read-only text input with Select All on its way', ['copy' => true, 'cut' => false]);
$t->frame();
$t->clickLabel('pin'); $t->frame();
$t->input->push(['edit', 'selectAll']);
$check('a read-only password with Select All on its way', ['copy' => false, 'cut' => false]);
$t->frame();
$t->clickLabel('notes'); $t->frame();
$t->input->push(['edit', 'selectAll']); $t->frame();
$check('a read-only text input', ['copy' => true, 'selectAll' => true, 'cut' => false, 'paste' => true, 'delete' => false]);

$t->clickLabel('list'); $t->frame();
$check('a node copying with Cmd+C', ['copy' => true, 'paste' => true, 'selectAll' => false]);
$t->clickLabel('terminal'); $t->frame();
$check('a node taking its input', ['copy' => true, 'paste' => true]);

$t->clickLabel('field'); $t->frame();
$t->type('hi');
$t->input->push(['edit', 'selectAll']); $t->frame();
$disabled = true; $t->frame();
same('field', $t->focused()?->label, 'a text input disabled keeps the focus');
$check('a text input disabled with the focus', ['copy' => false, 'paste' => false, 'selectAll' => false]);

// Select All of selectable text: the container's paragraphs, and the newlines between them.
$off = false;
$t = new Headless(function (Context $c) use (&$off): void {
    $c->column(0, 8)->selectable();
      $c->text('First')->disabled($off);
      $c->text('Last');
      $c->text('')->size(150, 25)->label('empty');
    $c->end();
    $c->column()->selectable();
      $c->text('')->size(150, 25)->label('blank');
      $c->text('')->size(150, 25);
    $c->end();
    $c->column()->selectable();
      $c->text('')->size(150, 25)->label('alone');
    $c->end();
}, 200, 300);
$t->frame();
$selectAll = fn(): bool => $t->rt->commandEnabled('selectAll');
$t->clickLabel('empty'); $t->frame();
check($selectAll(), 'an empty paragraph: Select All, for its container\'s text');
$t->clickLabel('blank'); $t->frame();
check($selectAll(), 'empty paragraphs: Select All, for the newline between them');
$t->clickLabel('alone'); $t->frame();
check(!$selectAll(), 'a lone empty paragraph: nothing to select');
selectBetween($t, 'First', 0, 'Last', 4);
$off = true; $t->frame();
check(!$selectAll(), 'the focused paragraph disabled: it takes no command');

// Only input that edits makes a command count while it waits: the pointer moving does not.
$st = new TextState('');
$t = new Headless(function (Context $c) use ($st): void { $c->input($st)->label('field'); }, 300, 60);
$t->frame();
$t->clickLabel('field'); $t->frame();
$t->input->push(['move', 10, 10]);
check(!$t->rt->commandEnabled('copy'), 'the pointer moving: an empty field still copies nothing');
$t->input->push(['insert', 'x']);
check($t->rt->commandEnabled('copy'), 'text on its way: the command goes behind it');
