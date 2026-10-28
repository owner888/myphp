<?php
// Context menus and menu buttons (mygo's menu_test.go, and the menus of
// textselection_test.go): items, check marks, submenus, disabled items,
// shortcuts, the innermost menu, the keyboard, text inputs' and selectable
// text's editing items, editItems().
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{AccessNode, Context, Key, Menu, Node, Role, Runtime, TextState};

require_once __DIR__ . '/SelectionTest.php';

// Items, a check mark, a submenu, a disabled item, a shortcut; a choice reaches the function in the frame after.
$chosen = []; $pinned = false; $locked = true; $rightClicked = 0; $n = [];
$h = new Headless(function (Context $c) use (&$chosen, &$pinned, &$locked, &$rightClicked, &$n) {
    $c->column(20, 10, 1);
      $n['row'] = $c->row(8)->contextMenu(function (Menu $m) use (&$chosen, &$pinned, &$locked) {
          $m->header('Report');
          if ($m->item('Open')->shortcut(Key::COMMAND, Key::O)->chosen()) { $chosen[] = 'Open'; }
          if ($m->item('Pinned')->checked($pinned)->chosen()) { $pinned = !$pinned; }
          $m->separator();
          $m->submenu('Move to', function (Menu $m) use (&$chosen) {
              foreach (['Desktop', 'Documents'] as $folder) {
                  if ($m->item($folder)->chosen()) { $chosen[] = "Move to $folder"; }
              }
          });
          if ($m->item('Delete')->disabled($locked)->chosen()) { $chosen[] = 'Delete'; }
      });
        $c->text('Report.pdf');
      $c->end();
      $n['plain'] = $c->column()->size(60, 30); $c->end(); // without a context menu, a right-click is the node's
      if ($c->rightClicked($n['plain'])) { $rightClicked++; }
      $c->text($pinned ? 'pinned' : 'loose');
    $c->end();
}, 400, 300);
$h->frame();
same(null, $h->menuLabels(), 'no menu before a click');
$h->rightClick(...$h->middle($n['row']));
same(['# Report', 'Open', 'Pinned', '-', 'Move to', 'Delete'], $h->menuLabels(), 'the menu, under its header');
check(!$h->chooseMenuItem('Report') && $h->menu !== null, 'a header is chosen by none');
$items = array_slice($h->menu['items'], 1);
same('Super+o', $items[0]->accelerator, 'Open shows its shortcut');
same([\MyPHP\MenuItem::CHECKBOX, false], [$items[1]->type, $items[1]->checked], 'Pinned is an unchecked check box item');
check($items[4]->disabled, 'Delete is disabled while locked');
check(!$h->chooseMenuItem('Delete'), 'a disabled item cannot be chosen');
check($h->chooseMenuItem('Pinned'), 'Pinned is chosen');
same(null, $h->menuLabels(), 'the menu goes with the choice');
check($pinned && $h->byText('pinned') !== null, 'choosing Pinned pins, and the frame shows it');
$h->rightClick(...$h->middle($n['row']));
check($h->menu['items'][2]->checked, 'Pinned has its check mark once pinned');
check($h->chooseMenuItem('Move to', 'Documents'), 'an item of the submenu');
$locked = false; $h->frame();
$h->rightClick(...$h->middle($n['row']));
$h->chooseMenuItem('Delete');
same(['Move to Documents', 'Delete'], $chosen, 'the submenu and the enabled item reached the function');
$h->rightClick(...$h->middle($n['row']));
$h->closeMenu(); $h->frame();
same(2, count($chosen), 'a menu closed without a choice chooses nothing');
$h->rightClick(...$h->middle($n['plain']));
same([null, 1], [$h->menuLabels(), $rightClicked], 'a right-click on a node without a menu is its own');

// The innermost node with a context menu gets the click.
$got = ''; $n = [];
$h = new Headless(function (Context $c) use (&$got, &$n) {
    $n['card'] = $c->column(20)->contextMenu(function (Menu $m) use (&$got) { if ($m->item('Remove Card')->chosen()) { $got = 'Remove Card'; } });
      $n['text'] = $c->text('Card');
      $n['share'] = $c->button('Share')->contextMenu(function (Menu $m) use (&$got) { if ($m->item('Copy Link')->chosen()) { $got = 'Copy Link'; } });
    $c->end();
}, 400, 300);
$h->frame();
$h->rightClick(...$h->middle($n['share']));
same(['Copy Link'], $h->menuLabels(), "the button's menu");
$h->closeMenu();
$h->rightClick(...$h->middle($n['text']));
$h->chooseMenuItem('Remove Card');
same('Remove Card', $got, "the card's around its text");

// From the keyboard: the menu key, Shift+F10.
$chosenCount = 0;
$h = new Headless(function (Context $c) use (&$chosenCount) {
    $c->column(20, 8);
      $c->button('First');
      $c->button('Second')->contextMenu(function (Menu $m) use (&$chosenCount) { if ($m->item('Duplicate')->chosen()) { $chosenCount++; } });
    $c->end();
}, 400, 300);
$h->frame();
$h->tab();
$h->key(Key::CONTEXT_MENU, '');
same(null, $h->menuLabels(), 'the menu key over a button without a menu');
$h->tab();
$h->key(Key::CONTEXT_MENU, '');
same(['Duplicate'], $h->menuLabels(), 'the menu key opens the focused one');
$h->closeMenu();
$h->key(Key::F10, '', Key::SHIFT);
check($h->chooseMenuItem('Duplicate'), 'Shift+F10 too');
same(1, $chosenCount, 'chosen once');

// A text input's menu: the right-click focuses it, in its selection keeps it; Copy, Paste.
$value = new TextState('hello world'); $n = [];
$h = new Headless(function (Context $c) use ($value, &$n) {
    $c->column(20);
      $n['field'] = $c->input($value);
    $c->end();
}, 400, 200);
$h->frame();
[$fx, $fy, $fw, $fh] = $h->rect($n['field']);
$h->rightClick($fx + $fw - 4, $fy + $fh / 2);
same($n['field']->id, $h->rt->focus, 'the right-click focuses the input');
foreach (['Cut', 'Copy', 'Paste', 'Select All'] as $label) {
    check(in_array($label, $h->menuLabels() ?? [], true), "the menu has $label");
}
check(!$h->chooseMenuItem('Copy'), 'Copy is disabled without a selection');
check($h->chooseMenuItem('Select All'), 'Select All');
$h->rightClick($fx + 30, $fy + $fh / 2);
$h->chooseMenuItem('Copy');
same('hello world', clip($h), 'a right-click in the selection keeps it');
setClip($h, 'bye');
$h->key(Key::CONTEXT_MENU, '');
$h->chooseMenuItem('Paste');
same('bye', $value->text, 'the menu key, then Paste over the selection');

same(['Super+c', 'Ctrl+Shift+z', 'Alt+F4', 'Delete', 'Ctrl+,', ''], [
    Runtime::accelerator(Key::COMMAND, Key::C), Runtime::accelerator(Key::CONTROL | Key::SHIFT, Key::Z), Runtime::accelerator(Key::OPTION, Key::F4),
    Runtime::accelerator(0, Key::FORWARD_DELETE), Runtime::accelerator(Key::CONTROL, Key::COMMA), Runtime::accelerator(Key::CONTROL, Key::CONTEXT_MENU),
], 'accelerators as menus take them');

// A menu button: its menu opens as it goes down, below it; Enter, Space and Down open it; a disabled one does not.
$sort = 'Name'; $disabled = false; $n = [];
$h = new Headless(function (Context $c) use (&$sort, &$disabled, &$n) {
    $c->column(20, 10)->align(Node::START);
      $n['sort'] = $c->menuButton('Sort by', function (Menu $m) use (&$sort) {
          foreach (['Name', 'Date', 'Size'] as $by) {
              if ($m->item($by)->checked($sort === $by)->chosen()) { $sort = $by; }
          }
      });
      $n['sort']->disabled = $disabled;
      $c->button('Other');
    $c->end();
}, 400, 300);
$h->frame();
$r = $h->rect($n['sort']);
$h->press($r[0] + $r[2] / 2, $r[1] + $r[3] / 2);
same(['Name', 'Date', 'Size'], $h->menuLabels(), 'the menu opens as the button goes down');
[$ax, $ay] = $h->menu['at'];
check($ax <= $r[0] && $ay >= $r[1] + $r[3] && $ay <= $r[1] + $r[3] + 20, 'below the button');
check($h->menu['items'][0]->checked, 'Name is checked');
$h->release($r[0] + $r[2] / 2, $r[1] + $r[3] / 2);
$h->chooseMenuItem('Date');
same(['Date', $n['sort']->id], [$sort, $h->rt->focus], 'Date chosen, the button focused');
foreach ([[Key::RETURN, 'insertNewline:', "\r"], [Key::SPACE, '', ' '], [Key::DOWN, 'moveDown:', '']] as [$code, $sel, $chars]) {
    $h->closeMenu();
    $code === Key::SPACE ? $h->space() : $h->key($code, $sel, 0, $chars);
    check($h->menuLabels() !== null, "key $code opens the menu");
}
$h->closeMenu();
$h->tab(); $h->enter();
same(null, $h->menuLabels(), 'Enter on the next button opens no menu');
$disabled = true; $h->frame();
$h->clickOn($n['sort']);
same(null, $h->menuLabels(), 'a disabled menu button opens none');

// A menu button inside what keeps the focus (Node::keepFocus), as a player's sidebar: its menu opens, the focus stays where it was, so Space is not the button's.
$n = []; $spaces = 0;
$h = new Headless(function (Context $c) use (&$n, &$spaces) {
    $c->column(20, 10)->align(Node::START);
      $n['other'] = $c->button('Other');
      $c->column()->keepFocus();
        $n['eq'] = $c->menuButton('Preset', function (Menu $m) { $m->item('Rock')->chosen(); });
      $c->end();
    $c->end();
    if ($c->shortcut(Key::SPACE)) { $spaces++; }
}, 400, 300);
$h->frame();
$h->rt->focus = null; $h->frame();
$r = $h->rect($n['eq']);
$h->press($r[0] + $r[2] / 2, $r[1] + $r[3] / 2);
check($h->menuLabels() !== null, 'its menu opens');
$h->release($r[0] + $r[2] / 2, $r[1] + $r[3] / 2);
$h->chooseMenuItem('Rock');
same(null, $h->rt->focus, 'the focus where it was: none');
$h->key(Key::SPACE, 'insertText:', 0, ' ');
same([null, 1], [$h->menuLabels(), $spaces], 'Space opens no menu, the view has it');

// Assistive technology presses a menu button: its menu opens.
$disabled = false; $h->frame();
$h->input->push(['accessOn']); $h->frame();
$an = null; foreach ($h->rt->accessTree->nodes as $x) { if ($x->role === Role::MENU_BUTTON) { $an = $x; } }
check($an !== null, 'a menu button node');
$h->input->push(['access', $an->id, AccessNode::PRESS]); $h->frame();
check($h->menuLabels() !== null, 'pressing it opens the menu');

// A node with a menu and a context menu: a click shows the one, a right-click the other.
$chosen = []; $n = [];
$h = new Headless(function (Context $c) use (&$chosen, &$n) {
    $n['both'] = $c->column()->size(100, 40)->menu(function (Menu $m) use (&$chosen) {
        if ($m->item('From the menu')->chosen()) { $chosen[] = 'menu'; }
    })->contextMenu(function (Menu $m) use (&$chosen) {
        if ($m->item('From the context menu')->chosen()) { $chosen[] = 'context'; }
    });
    $c->end();
}, 300, 200);
$h->frame();
$h->clickOn($n['both']);
same(['From the menu'], $h->menuLabels(), 'a click shows the menu');
$h->chooseMenuItem('From the menu');
$h->rightClick(...$h->middle($n['both']));
same(['From the context menu'], $h->menuLabels(), 'a right-click the context menu');
$h->chooseMenuItem('From the context menu');
same(['menu', 'context'], $chosen, 'each reached its function');

// Selectable text: its menu, a container's own with editItems(), a menu beside the text.
$replied = 0; $n = [];
$h = new Headless(function (Context $c) use (&$replied, &$n) {
    $n['bubble'] = $c->column(20, 10)->contextMenu(function (Menu $m) { $m->item('Bubble'); });
      $c->column(0, 10)->selectable()->contextMenu(function (Menu $m) use (&$replied) {
          if ($m->item('Reply')->chosen()) { $replied++; }
          $m->separator();
          $m->editItems();
          $m->separator();
          $m->item('After');
      });
        $c->text('Alpha');
        $c->text('Beta');
      $c->end();
      $c->column()->selectable(); $c->text('Gamma'); $c->end();
    $c->end();
}, 300, 200);
$h->frame();
selectBetween($h, 'Alpha', 0, 'Beta', 4);
[$x, $y] = selectableAt($h, 'Beta', 2);
$h->rightClick($x, $y);
same(['Reply', '-', 'Copy', '-', 'Select All', '-', 'After'], $h->menuLabels(), "the container's menu, its text's items in it");
$h->chooseMenuItem('Reply'); $h->frame();
same(1, $replied, 'Reply reached the function once');
$h->rightClick($x, $y);
$h->chooseMenuItem('Copy'); $h->frame();
same(['Alpha' . "\n" . 'Beta', 1], [clip($h), $replied], 'Copy copies the selection, without reaching the function');
$h->rightClick($x, $y);
$h->chooseMenuItem('Select All');
cmdKey($h, 'c');
same("Alpha\nBeta", clip($h), "Select All selects the container's text");
[$x, $y] = selectableAt($h, 'Gamma', 2);
$h->rightClick($x, $y);
same(['Copy', '-', 'Select All'], $h->menuLabels(), 'text without a menu has its own');
$h->closeMenu();
$h->rightClick(5, 5);
same(['Bubble'], $h->menuLabels(), 'the menu beside the text');

// A text input's own menu with editItems(): they edit it.
$value = new TextState('hello'); $n = [];
$h = new Headless(function (Context $c) use ($value, &$n) {
    $n['field'] = $c->input($value)->contextMenu(function (Menu $m) {
        $m->item('Custom');
        $m->separator();
        $m->editItems();
    });
}, 300, 100);
$h->frame();
[$fx, $fy, , $fh] = $h->rect($n['field']);
$h->click($fx + 10, $fy + $fh / 2);
$value->selectAll(); $h->frame();
$h->rightClick($fx + 10, $fy + $fh / 2);
$labels = $h->menuLabels() ?? [];
check(count($labels) >= 4 && $labels[0] === 'Custom' && $labels[1] === '-' && in_array('Copy', $labels, true) && in_array('Select All', $labels, true), 'Custom, then the editing items');
$h->chooseMenuItem('Copy');
same('hello', clip($h), "the input's Copy copies");
