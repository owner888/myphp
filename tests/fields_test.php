<?php
// What v1.14 added to text fields from mygo's textarea_test.go and
// editor_input_test.go: a password shows bullets in the frames that ask
// for it, copies nothing, and tells assistive technology only that it is
// one; a text area is never one. And the Control keys of macOS text
// fields (ui_test.go TestEmacsKeys).
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{AccessNode, Context, Key, Role, TextState};

require_once __DIR__ . '/SelectionTest.php';

// A password shows a bullet a character, until its build stops asking (TestInputPasswordToggles).
$key = new TextState('sécret');
$hidden = true;
$h = new Headless(function (Context $c) use ($key, &$hidden) {
    $c->column(10, 0, 1);
      $in = $c->input($key, 'Key', 200);
      if ($hidden) { $in->password(); }
    $c->end();
}, 300, 80);
$h->frame();
same('••••••', $key->shown(), 'a password shows bullets');
same([0, 3, 6, 18], [$key->shownIndex(0), $key->shownIndex(1), $key->shownIndex(3), $key->shownIndex(strlen($key->text))], 'its bytes map onto the bullets, é one of them');
same([0, 1, 3], [$key->fromShown(0), $key->fromShown(3), $key->fromShown(6)], 'and back, as a click finds them');
$hidden = false;
$h->frame(2);
same('sécret', $key->shown(), 'the key shown again');

// It copies and cuts nothing; assistive technology hears only that it is one (TestWidgetInputPrivacyAndReadonly).
$hidden = true;
$h->frame();
$h->clickOn($h->byText('Key') ?? $h->root->kids[0]->kids[0]);
$h->frame();
$key->selectAll();
check($key->hasSelection() && !$key->copyable(), 'selected, but nothing for Copy and Cut to take');
$h->type('!'); $h->frame();
same('!', $key->text, 'typing replaces the selection, as in any field');
$h->input->push(['accessOn']);
$h->frame();
$field = null;
foreach ($h->rt->accessTree->nodes as $n) { if ($n->role === Role::TEXT_FIELD) { $field = $n; } }
check($field !== null && ($field->states & AccessNode::PASSWORD) !== 0 && $field->value === '', 'a secure field, its value not read out');
same([], array_values(array_filter($h->rt->editCommands($h->rt->focus ?? 0), fn($c) => in_array($c[1], ['cut', 'copy'], true) && $c[2])), 'its menu offers no Cut or Copy');

// A text area is never a password (TestTextAreaPassword).
$notes = new TextState("first\nsecond");
$h = new Headless(function (Context $c) use ($notes) { $c->column(10, 0, 1); $c->textarea($notes)->password(); $c->end(); }, 400, 200);
$h->frame();
same([false, "first\nsecond"], [$notes->password, $notes->shown()], 'password() leaves a text area alone');

// The window's shortcuts built behind a dialog wait while it shows; its own work (TestShortcutsWaitBehindADialog).
$d = ['open' => false, 'behind' => 0, 'inside' => 0, 'escape' => 0];
$name = new TextState('');
$h = new Headless(function (Context $c) use (&$d, $name) {
    $c->row(0, 8);
      if ($c->clicked($c->button('Open'))) { $d['open'] = true; }
    $c->end();
    if ($c->shortcut('n', Key::COMMAND)) { $d['behind']++; }
    $c->onShortcut('e', Key::COMMAND, function () use (&$d) { $d['escape']++; });
    $c->modal($d['open'], function () use ($c, &$d, $name) {
        $c->input($name, 'Name');
        if ($c->shortcut('s', Key::COMMAND)) { $d['inside']++; }
    });
}, 500, 400);
$h->frame();
$h->shortcut('n', Key::COMMAND); $h->shortcut('e', Key::COMMAND);
same([1, 1], [$d['behind'], $d['escape']], 'with no dialog, the window\'s shortcuts');
$h->clickOn($h->byText('Open')); $h->frame(2);
$h->shortcut('n', Key::COMMAND); $h->shortcut('e', Key::COMMAND); $h->shortcut('s', Key::COMMAND);
same([1, 1, 1], [$d['behind'], $d['escape'], $d['inside']], 'with the dialog open: those behind it wait, its own work');
$h->escape(); $h->frame(2);
same(false, $d['open'], 'Escape closes it');
$h->shortcut('n', Key::COMMAND);
same(2, $d['behind'], 'and the window has its shortcuts again');

// The Control keys of macOS text fields, as the text system sends them (mygo's ui_test.go TestEmacsKeys): Control-K deletes to the paragraph's end, at its end joining the next.
$text = new TextState("one two\nthree");
$h = new Headless(function (Context $c) use ($text) { $c->column(10, 0, 1); $c->textarea($text)->autoFocus(); $c->end(); }, 300, 200);
$h->frame();
$ctrl = fn(int $code, string $selector) => $h->key($code, $selector, Key::CONTROL);
$ctrl(Key::A, 'moveToBeginningOfParagraph:'); // the start of "three"
$h->type('>');
same("one two\n>three", $text->text, 'Control-A, then typing');
$ctrl(Key::P, 'moveUp:');
$ctrl(Key::E, 'moveToEndOfParagraph:');
$ctrl(Key::K, 'deleteToEndOfParagraph:');
same('one two>three', $text->text, 'Control-P, -E, -K: at the end, it joins the next paragraph');
$ctrl(Key::A, 'moveToBeginningOfParagraph:');
$ctrl(Key::F, 'moveForward:');
$ctrl(Key::D, 'deleteForward:');
same('oe two>three', $text->text, 'Control-A, -F, -D');
$ctrl(Key::K, 'deleteToEndOfParagraph:');
$ctrl(Key::H, 'deleteBackward:');
same('', $text->text, 'Control-K, -H');
$field = new TextState('name: value');
$h = new Headless(function (Context $c) use ($field) { $c->input($field)->autoFocus(); }, 300, 200);
$h->frame();
$field->setCursor(5);
$h->key(Key::K, 'deleteToEndOfLine:', Key::CONTROL);
same('name:', $field->text, 'deleteToEndOfLine: in a field, to its end');
