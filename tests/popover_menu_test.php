<?php
// Menus drawn in the window (mygo's popovermenu_test.go, 34c735c, d728256
// and 85fd4bb): a context menu opening at the pointer, flipping at the
// window's edges and right to left; the keys, type-ahead and submenus;
// choosing by click and by key; Escape, Tab and presses outside closing,
// the focus going back; the access roles; editing items; a menu in a list;
// a menu button's menu below it; radio items of the system's menus.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Context, DrawnMenu, Key, ListState, Menu, Node, Role, ScrollState, TextState};

final class MenuApp
{
    public array $chosen = [];
    public bool $pinned = false, $hidden = false;
    public string $sort = '';
    /** @var array<string, Node> */
    public array $n = [];

    public function view(Context $c): void
    {
        $c->column(20, 10, 1);
          $this->n['edit'] = $c->button('Edit');
          if (!$this->hidden) {
              $this->n['row'] = $c->row(8)->focusable()->label('Report');
                $c->text('Report.pdf');
              $c->end();
              $this->n['row']->popoverMenu($this->menu(...));
          }
        $c->end();
    }

    public function menu(Menu $m): void
    {
        if ($m->item('Open')->shortcut(Key::COMMAND, Key::O)->chosen()) { $this->chosen[] = 'Open'; }
        if ($m->item('Pinned')->checked($this->pinned)->chosen()) { $this->pinned = !$this->pinned; }
        $m->separator();
        $m->submenu('Move to', function (Menu $m) {
            foreach (['Desktop', 'Documents'] as $f) { if ($m->item($f)->chosen()) { $this->chosen[] = "Move to $f"; } }
        });
        $m->submenu('Sort by', function (Menu $m) {
            foreach (['Name', 'Date'] as $by) { if ($m->item($by)->radio($this->sort === $by)->chosen()) { $this->sort = $by; } }
        });
        if ($m->item('Delete')->disabled(true)->chosen()) { $this->chosen[] = 'Delete'; }
    }
}

/** The node showing text $s, or null. */
function shown(Headless $h, string $s): ?Node { return $h->byText($s); }

/** The node labeled $label under $n, and its parent, or nulls. @return array{0: ?Node, 1: ?Node} */
function labeled(Node $n, string $label, ?Node $parent = null): array
{
    if ($n->label === $label && $n->role !== Role::AUTO) { return [$n, $parent]; }
    foreach ($n->kids as $k) { $f = labeled($k, $label, $n); if ($f[0] !== null) { return $f; } }
    return [null, null];
}

/** The menu item that has the focus, as assistive technology reads it: the panel focused, its active descendant. */
function menuFocus(Headless $h): ?string
{
    $f = $h->rt->focus === null ? null : $h->find($h->rt->focus);
    if ($f === null || $f->role !== Role::MENU) { return null; }
    return $f->activeDescendant?->label;
}

// It opens at the pointer, the system showing none, the menu taking the focus; frames after do not close it.
$a = new MenuApp;
$h = new Headless($a->view(...), 400, 300);
$h->frame();
$r = $h->rect($h->byText('Report.pdf'));
[$x, $y] = [$r[0] + 10, $r[1] + $r[3] / 2];
$h->rightClick($x, $y);
check(shown($h, 'Open') !== null, 'a right-click opens the drawn menu: ' . json_encode($h->texts()));
same(null, $h->menu, 'the system shows none');
$open = $h->rect(labeled($h->root, 'Open')[0]);
check($open[0] >= $x && $open[1] >= $y && $open[0] <= $x + 20 && $open[1] <= $y + 20, 'below and after the click: ' . json_encode([$open, $x, $y]));
same(Role::MENU, $h->find($h->rt->focus)?->role, 'the menu has the focus');
$h->frame();
check(shown($h, 'Open') !== null && shown($h, 'Pinned') !== null && shown($h, 'Move to') !== null && shown($h, 'Delete') !== null, 'it stays, all its items');
check(shown($h, '⌘O') !== null, 'Open shows its shortcut as macOS writes it');
$h->rightClick($x + 250, $y);
$open = $h->rect($h->byText('Open'));
check($open[0] + $open[2] <= $x + 250, 'a right-click near the right edge flips it before the click: ' . json_encode($open));

// An element scrolled partly out of view: at the pointer all the same (85fd4bb).
$s = new ScrollState;
$h2 = new Headless(function (Context $c) use ($s) {
    $c->column(0, 0, 1)->scroll($s);
      $col = $c->column(0, 10);
        for ($i = 0; $i < 60; $i++) { $c->text("Line $i"); }
      $c->end();
      $col->popoverMenu(function (Menu $m) { $m->item('Open'); });
    $c->end();
}, 400, 300);
$h2->frame();
$s->y = 400; $h2->frame();
$h2->rightClick(100, 200);
$open = $h2->rect(labeled($h2->root, 'Open')[0]);
check($open[0] >= 100 && $open[1] >= 200 && $open[0] <= 120 && $open[1] <= 220, 'scrolled, at the pointer: ' . json_encode($open));

// The keys: the menu key opens it on the first item; Down, Up, Home, End past separators and disabled items; Right and Left in and out of submenus; Enter, Escape.
$a = new MenuApp;
$h = new Headless($a->view(...), 400, 400);
$h->rt->clock = fn() => 1_000_000_000;
$h->frame();
$h->clickOn($a->n['row']);
same($a->n['row']->id, $h->rt->focus, 'the row has the focus');
$h->key(Key::CONTEXT_MENU, '');
check(shown($h, 'Open') !== null, 'the menu key opens it');
same('Open', menuFocus($h), 'on its first item');
foreach ([[Key::DOWN, 'moveDown:', 'Pinned'], [Key::DOWN, 'moveDown:', 'Move to'], [Key::DOWN, 'moveDown:', 'Sort by'], [Key::DOWN, 'moveDown:', 'Open'],
          [Key::UP, 'moveUp:', 'Sort by'], [Key::HOME, 'moveToBeginningOfDocument:', 'Open'], [Key::END, 'moveToEndOfDocument:', 'Sort by'],
          [Key::RIGHT, 'moveRight:', 'Name'], [Key::DOWN, 'moveDown:', 'Date'], [Key::LEFT, 'moveLeft:', 'Sort by'], [Key::UP, 'moveUp:', 'Move to'],
          [Key::RETURN, 'insertNewline:', 'Desktop'], [Key::ESCAPE, 'cancelOperation:', 'Move to']] as $i => [$k, $sel, $want]) {
    $h->key($k, $sel);
    check(shown($h, 'Open') !== null, "step $i: the menu shows");
    same($want, menuFocus($h), "step $i");
}
same(null, shown($h, 'Desktop'), 'Escape closed the submenu');
$h->type('p');
same('Pinned', menuFocus($h), 'typing a first letter goes to the item');
$h->rt->clock = fn() => 5_000_000_000; $h->frame();
$h->type('s');
same('Sort by', menuFocus($h), 'and another later');
$h->rt->clock = fn() => 10_000_000_000; $h->frame();
$h->type('d');
same('Sort by', menuFocus($h), 'Delete, disabled, is passed over');
$h->key(Key::UP, 'moveUp:');
$h->key(Key::RIGHT, 'moveRight:');
$h->key(Key::DOWN, 'moveDown:');
same('Documents', menuFocus($h), 'into the submenu');
$h->key(Key::RETURN, 'insertNewline:');
check(shown($h, 'Open') === null, 'Enter chooses, and the menu goes');
same(['Move to Documents'], $a->chosen, 'the item chosen');
same($a->n['row']->id, $h->rt->focus, 'the focus goes back to the row');
$h->key(Key::F10, '', Key::SHIFT);
same('Open', menuFocus($h), 'Shift+F10 opens it');
$h->rt->clock = fn() => 20_000_000_000; $h->frame();
$h->space();
check(shown($h, 'Open') === null && $a->chosen === ['Move to Documents', 'Open'], 'Space chooses');
foreach ([[Key::ESCAPE, 'cancelOperation:'], [Key::TAB, 'insertTab:']] as [$k, $sel]) {
    $h->key(Key::CONTEXT_MENU, '');
    $h->key($k, $sel);
    check(shown($h, 'Open') === null && $h->rt->focus === $a->n['row']->id, "key $k closes it, the focus back on the row");
}

// Right to left, the submenus open to the left, Left goes into them.
$a = new MenuApp;
$h = new Headless($a->view(...), 400, 400);
$h->rt->rightToLeft = true;
$h->frame();
$h->clickOn($a->n['row']);
$h->key(Key::CONTEXT_MENU, '');
$h->key(Key::END, 'moveToEndOfDocument:');
$h->key(Key::RIGHT, 'moveRight:');
same('Sort by', menuFocus($h), 'right to left, Right goes into no submenu');
$h->key(Key::LEFT, 'moveLeft:');
same('Name', menuFocus($h), 'Left does');
$sort = $h->rect($h->byText('Sort by')); $name = $h->rect($h->byText('Name'));
check($name[0] + $name[2] <= $sort[0] + 1, 'the submenu left of its item: ' . json_encode([$name, $sort]));
$h->key(Key::RIGHT, 'moveRight:');
same('Sort by', menuFocus($h), 'and Right out');

// The pointer: a click chooses; a disabled item takes none; moving onto a submenu's item opens it, onto another closes it.
$a = new MenuApp;
$h = new Headless($a->view(...), 400, 400);
$h->frame();
$h->rightClick(...$h->middle($a->n['row']));
$h->clickText('Pinned');
check($a->pinned && shown($h, 'Open') === null, 'clicking Pinned');
$h->rightClick(...$h->middle($a->n['row']));
$h->clickText('Delete');
check($a->chosen === [] && shown($h, 'Open') !== null, 'clicking Delete, disabled, chooses none and the menu stays');
$h->hover(...$h->middle($h->byText('Sort by')));
check(shown($h, 'Name') !== null, 'the pointer opens a submenu');
$h->clickText('Date');
check($a->sort === 'Date' && shown($h, 'Open') === null, 'clicking Date');
$h->rightClick(...$h->middle($a->n['row']));
$h->hover(...$h->middle($h->byText('Move to')));
$h->hover(...$h->middle($h->byText('Open')));
same(null, shown($h, 'Desktop'), 'moving onto another item closes the submenu');

// Closing: Escape, a press outside, on the row; not a press in a submenu; the menu goes with its node; the focus goes back.
$a = new MenuApp;
$h = new Headless($a->view(...), 400, 400);
$h->frame();
$h->clickOn($a->n['edit']);
same($a->n['edit']->id, $h->rt->focus, 'the button has the focus');
$h->rightClick(...$h->middle($a->n['row']));
check($h->rt->focus !== $a->n['edit']->id && shown($h, 'Open') !== null, 'the menu takes it');
$h->escape();
check(shown($h, 'Open') === null && $h->rt->focus === $a->n['edit']->id, 'Escape closes it, the focus back on the button');
$h->rightClick(...$h->middle($a->n['row']));
$h->click(390, 390);
same(null, shown($h, 'Open'), 'a press outside closes it');
$h->rightClick(...$h->middle($a->n['row']));
$rr = $h->rect($a->n['row']);
$h->click($rr[0] + 1, $rr[1] + 1);
same(null, shown($h, 'Open'), 'as one on the row');
$h->rightClick(...$h->middle($a->n['row']));
$h->hover(...$h->middle($h->byText('Move to')));
$d = $h->rect($h->byText('Desktop'));
$h->press($d[0] + 1, $d[1] + 1);
check(shown($h, 'Desktop') !== null, 'a press in the submenu keeps it');
$h->release($d[0] + 1, $d[1] + 1);
$h->rightClick(...$h->middle($a->n['row']));
$a->hidden = true; $h->frame();
same(null, shown($h, 'Open'), 'the menu goes with its node');

// What assistive technology sees: a menu, items with their places, check box and radio items, disabled, expandable.
$a = new MenuApp; $a->sort = 'Date'; $a->pinned = true;
$h = new Headless($a->view(...), 400, 400);
$h->frame();
$h->rightClick(...$h->middle($a->n['row']));
$item = fn(string $label) => labeled($h->root, $label)[0];
[$open, $openIn] = labeled($h->root, 'Open');
same([Role::MENU_ITEM, 1, 5, '⌘O', Role::MENU], [$open->role, $open->setPos, $open->setSize, $open->description, $openIn?->role], 'Open: a menu item, its place, its shortcut, in the menu');
check($item('Pinned')->role === Role::MENU_ITEM_CHECK_BOX && $item('Pinned')->checked === 2, 'Pinned: a check box item, checked');
check($item('Delete')->disabled, 'Delete disabled');
check($item('Sort by')->expandable && !$item('Sort by')->expanded, 'Sort by: expandable, closed');
$h->key(Key::END, 'moveToEndOfDocument:');
$h->key(Key::RIGHT, 'moveRight:');
check($item('Sort by')->expanded, 'expanded with its submenu open');
check($item('Name')->role === Role::MENU_ITEM_RADIO && $item('Name')->checked !== 2 && $item('Date')->checked === 2, 'radio items, Date chosen');
same('Sort by', labeled($h->root, 'Name')[1]?->label, 'the submenu named by its item');

// Editing items act on the text it opened on.
$name = new TextState('hello'); $cleared = false; $in = null;
$h = new Headless(function (Context $c) use ($name, &$cleared, &$in) {
    $c->column(20);
      $in = $c->input($name, '', 200)->label('Name');
      $in->popoverMenu(function (Menu $m) use (&$cleared) {
          if ($m->item('Clear')->chosen()) { $cleared = true; }
          $m->separator();
          $m->editItems();
      });
    $c->end();
}, 400, 300);
$h->frame();
$h->rightClick(...$h->middle($in));
check(shown($h, 'Select All') !== null && shown($h, 'Clear') !== null, 'its editing items: ' . json_encode($h->texts()));
$h->clickText('Select All');
$h->rightClick(...$h->middle($in));
$h->clickText('Copy');
check(($h->rt->readClipboard)() === 'hello' && !$cleared, 'Select All, Copy: on the text');

// A menu of a list's rows.
$ls = new ListState; $ls->selected = 0; $openedRow = -1;
$h = new Headless(function (Context $c) use ($ls, &$openedRow) {
    $c->list($ls, 50, function (int $i) use ($c, &$openedRow) {
        $row = $c->row()->height(28);
          $c->text('Row ' . chr(65 + $i % 26));
        $c->end();
        $row->popoverMenu(function (Menu $m) use ($i, &$openedRow) { if ($m->item('Open row')->chosen()) { $openedRow = $i; } });
    })->grow = 1;
}, 300, 300);
$h->frame();
$h->rightClick(...$h->middle($h->byText('Row C')));
$h->clickText('Open row');
check($openedRow === 2 && shown($h, 'Open row') === null, "a list row's menu: opened $openedRow");

// A menu button's menu, drawn below it, opened by the pointer and the keys; a press on it closes it.
$picked = []; $btn = null;
$h = new Headless(function (Context $c) use (&$picked, &$btn) {
    $c->column(20);
      $btn = $c->popoverMenuButton('More', function (Menu $m) use (&$picked) {
          if ($m->item('Duplicate')->chosen()) { $picked[] = 'Duplicate'; }
          if ($m->item('Rename')->chosen()) { $picked[] = 'Rename'; }
      });
    $c->end();
}, 400, 300);
$h->frame();
same(Role::MENU_BUTTON, $btn->role, 'a menu button');
$b = $h->rect($btn);
$h->press(...$h->middle($btn));
$h->release(...$h->middle($btn));
$dup = $h->byText('Duplicate');
check($dup !== null && $h->rect($dup)[1] >= $b[1] + $b[3] && abs($h->rect($dup)[0] - $b[0]) < 20, 'its menu below it, at its start');
check($btn->expanded, 'expanded while it shows');
same('', menuFocus($h) ?? '', 'opened by the pointer, on no item');
$h->clickText('Rename');
same(['Rename'], $picked, 'an item chosen');
$h->rt->focusOn($btn->id); $h->frame();
$h->key(Key::DOWN, 'moveDown:');
same('Duplicate', menuFocus($h), 'Down opens it on its first item');
$h->key(Key::RETURN, 'insertNewline:');
same(['Rename', 'Duplicate'], $picked, 'Enter chooses');
same($btn->id, $h->rt->focus, 'the focus back on the button');
$h->press(...$h->middle($btn)); $h->release(...$h->middle($btn));
check($h->byText('Duplicate') !== null, 'opened again');
$h->press(...$h->middle($btn)); $h->release(...$h->middle($btn));
same(null, $h->byText('Duplicate'), 'a press on the button closes it');

// At the window's bottom, above the button.
$h = new Headless(function (Context $c) use (&$btn) {
    $c->column(4, 0, 1)->justify(Node::END);
      $btn = $c->popoverMenuButton('More', function (Menu $m) { $m->item('Duplicate'); $m->item('Rename'); });
    $c->end();
}, 400, 300);
$h->frame();
$h->press(...$h->middle($btn)); $h->release(...$h->middle($btn));
check($h->rect($h->byText('Rename'))[1] < $h->rect($btn)[1], 'at the bottom, the menu above the button');

// Radio items of the system's menus.
$sort = 'Date'; $files = null;
$h = new Headless(function (Context $c) use (&$sort, &$files) {
    $files = $c->text('Files')->contextMenu(function (Menu $m) use (&$sort) {
        foreach (['Name', 'Date'] as $by) { if ($m->item($by)->radio($sort === $by)->chosen()) { $sort = $by; } }
    });
}, 300, 200);
$h->frame();
$h->rightClick(...$h->middle($files));
$items = $h->menu['items'];
check($items[0]->type === \MyPHP\MenuItem::RADIO && !$items[0]->checked && $items[1]->checked, 'radio items, Date checked');
check($h->chooseMenuItem('Name') && $sort === 'Name', 'Name chosen');

same(['⇧⌘Z', '⌃⌥↩', 'F5', '⌘,'], [DrawnMenu::shortcutText('Super+Shift+z'), DrawnMenu::shortcutText('Ctrl+Alt+Enter'), DrawnMenu::shortcutText('F5'), DrawnMenu::shortcutText('Super+,')], 'shortcuts as macOS writes them');
