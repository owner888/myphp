<?php
// Shortcuts on layouts that type no US key there go by the key's place
// (mygo's physical_key_test.go TestPhysicalKeyMatchesShortcutOnOtherLayout,
// TestPhysicalPunctuationShortcutOnOtherLayout, TestPhysicalKeyKeepsCyrillicTyping):
// Cmd-S on a Cyrillic layout saves, plain typing is untouched. And the
// modifiers held go with the window's keyboard (modifiers_test.go).
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Context, Key, TextState};

$hits = 0;
$h = new Headless(function (Context $c) use (&$hits): void {
    if ($c->shortcut('s', Key::COMMAND)) { $hits++; }
}, 200, 100);
$h->frame();
$h->key(Key::S, '', Key::COMMAND, 'ы');
same(1, $hits, 'Cmd and the key of S, which types ы');
$h->key(Key::S, '', 0, 'ы'); $h->key(Key::S, '', Key::SHIFT, 'Ы');
same(1, $hits, 'without Command, Control or Option the place is not asked');
$h->key(Key::D, '', Key::COMMAND, 's');
same(2, $hits, 'a layout typing s elsewhere goes by what it types');

foreach ([['!', Key::N1, Key::CONTROL | Key::SHIFT, '!'], ['-', Key::MINUS, Key::CONTROL, 'ß'], ['s', Key::S, Key::OPTION, 'ß']] as [$want, $code, $mods, $chars]) {
    $hits = 0;
    $h = new Headless(function (Context $c) use (&$hits, $want, $mods): void {
        if ($c->shortcut($want, $mods)) { $hits++; }
    }, 200, 100);
    $h->frame();
    $h->key($code, '', $mods, $chars);
    same(1, $hits, "$want by its place, typing $chars");
}

// Typing on a Cyrillic layout types.
$text = new TextState('x');
$h = new Headless(function (Context $c) use ($text): void { $c->input($text)->autoFocus(); }, 300, 100);
$h->frame();
$h->key(Key::S, '', 0, 'ы');
$h->type('ы');
same('xы', $text->text, 'ы typed');

// The modifiers held go with the window's keyboard (mygo's modifiers_test.go TestModifiersHeld).
$seen = [];
$h = new Headless(function (Context $c) use (&$seen): void {
    $seen[] = $c->modifiers();
    if ($c->modifiers() & Key::COMMAND) { $c->text('Shortcuts'); }
}, 200, 100);
$h->frame();
check($h->byText('Shortcuts') === null, 'nothing held');
$h->input->mods = Key::COMMAND; $h->frame(); // as the view's flagsChanged sets them
check($h->byText('Shortcuts') !== null, 'Cmd held');
$h->rt->setWindowFocus(false); $h->frame();
check($h->byText('Shortcuts') === null && end($seen) === 0, 'the window losing the keyboard lets go of Cmd');
