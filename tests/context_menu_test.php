<?php
// A page's context menu (mygo's context_menu_test.go, the part without
// WebKit): the names of WebKit's items, the listeners' changes compared
// with the menu as it was, separators tidied, an item shown once, the
// page's record of what was clicked matched to the menu, Menu::remove.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\{ContextMenuEvent as E, Menu, MenuItem};
use MyPHP\Native\ContextMenus as C;

same(['reload', 'copyLink', 'WKMenuItemIdentifierTranslateMenu', 'back', 'spellingGuess', '', 'pasteAndMatchStyle', 'selectAll', ''],
    [C::name('WKMenuItemIdentifierReload', 'forwardContextMenuAction:', 0), C::name('WKMenuItemIdentifierCopyLink', '', 0), C::name('WKMenuItemIdentifierTranslateMenu', 'x:', 0),
     C::name('', 'forwardContextMenuAction:', 9), C::name('', 'forwardContextMenuAction:', 15), C::name('', 'forwardContextMenuAction:', 999),
     C::name('', 'pasteAsPlainText:', 0), C::name('', 'selectAll:', 0), C::name('', 'orderFrontFontPanel:', 0)],
    'WebKit\'s items named by identifier, tag or action, the shared ones as every platform');

// WebKit's items as describe() makes them: numbered, with what each was.
$webkit = function (): array {
    $mk = function (int $k, string $id, string $label, string $type = '') { $it = $type === MenuItem::SEPARATOR ? MenuItem::separator() : new MenuItem($label, id: $id); $it->webKit = $k; return $it; };
    $items = [$mk(1, E::BACK, 'Back'), $mk(2, E::FORWARD, 'Forward'), $mk(3, E::RELOAD, 'Reload'), $mk(4, '', '', MenuItem::SEPARATOR), $mk(5, E::COPY, 'Copy'), $mk(6, E::INSPECT, 'Inspect Element')];
    $was = [1 => ['Back', true, false, '', null], 2 => ['Forward', true, false, '', null], 3 => ['Reload', true, false, '', null], 4 => ['', true, false, '', null], 5 => ['Copy', true, false, '', null], 6 => ['Inspect Element', true, false, '', null]];
    return [$items, [1, 2, 3, 4, 5, 6], $was];
};
[$items, $nums, $was] = $webkit();
check(C::same($items, $nums, $was), 'the menu as it was: the web view shows its own');
$items[2]->label = 'Refresh';
check(!C::same($items, $nums, $was), 'an item renamed: changed');
[$items, $nums, $was] = $webkit();
$items[4]->click = fn() => null;
check(!C::same($items, $nums, $was), 'an item of the web view given a click of the app\'s: changed');
[$items, $nums, $was] = $webkit();
$m = new Menu($items); $m->transient = true;
$m->remove(E::BACK, E::FORWARD, E::RELOAD);
same([E::COPY, E::INSPECT], array_map(fn(MenuItem $i) => $i->id, C::tidy(array_values($m->items))), 'the separator first after Back, Forward and Reload removed: dropped');
same(['a', '', 'b'], array_map(fn(MenuItem $i) => $i->id, C::tidy([MenuItem::separator(), new MenuItem('A', id: 'a'), MenuItem::separator(), MenuItem::separator(), new MenuItem('B', id: 'b'), MenuItem::separator()])), 'none first, last or after another');
$sub = new MenuItem('More', submenu: [new MenuItem('X', id: 'x', submenu: []), new MenuItem('Reload', id: E::RELOAD)]);
$m2 = new Menu([new MenuItem('Reload', id: E::RELOAD), $sub]); $m2->transient = true;
$m2->remove(E::RELOAD);
same([['More'], ['X']], [array_map(fn($i) => $i->label, $m2->items), array_map(fn($i) => $i->label, $sub->submenu)], 'remove: in submenus too');

[$items] = $webkit();
$twice = [$items[4], $items[0], $items[4]];
$shown = [];
same([E::COPY, E::BACK], array_map(fn($i) => $i->id, C::onceEach($twice, $shown)), 'a web view\'s item in one place at most');

check(C::recordOf(100.4, 50, true, 100, 50, 1.0), 'the page\'s event of the menu the pointer opened there');
check(C::recordOf(50, 25, true, 101, 51, 2.0), 'at the page\'s zoom');
check(!C::recordOf(10, 10, true, 100, 50, 1.0), 'another click\'s');
check(C::recordOf(10, 10, false, 0, 0, 1.0), 'a menu the keyboard opened takes the latest');
same(true, in_array('contextMenu', \MyPHP\Page::EVENTS, true), 'pages\' contextMenu listeners');
$e = new E(linkURL: 'https://x.org', menu: new Menu());
$e->preventDefault();
same([true, 'https://x.org'], [$e->prevented, $e->linkURL], 'a context menu event, prevented');
