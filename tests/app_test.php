<?php
// The app, its menus and its windows' state without AppKit (mygo's
// app_test.go, menu_test.go, window_state_test.go, modules' events): the
// quit sequence, the last window, directories, window state and its
// store, menu items' radio groups and the zoom steps, events, the theme's
// preferences as pages get them.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\{App, Event, Menu, MenuItem, Notification, PathName, QuitEvent, Rectangle, ThemePreferences, WindowState, WindowStateStore};
use MyPHP\Native\{WindowStateFile, WindowStates};

// The quit sequence: onBeforeQuit, the windows (none here), onWillQuit; either may keep the app.
$said = [];
$offBefore = App::onBeforeQuit(function (QuitEvent $e) use (&$said): void { $said[] = 'before'; });
$cancelWill = true;
$offWill = App::onWillQuit(function (QuitEvent $e) use (&$said, &$cancelWill): void { $said[] = 'will'; if ($cancelWill) { $e->cancel(); } });
check(!App::shouldQuit(), 'onWillQuit keeps the app');
same(['before', 'will'], $said, 'before, then will');
$cancelWill = false;
check(App::shouldQuit() && !App::quitting(), 'then it quits, the windows closed');
$offBefore(); $offWill();

// The last window: the onWindowAllClosed listeners, which keep the app.
$closed = 0;
$off = App::onWindowAllClosed(function () use (&$closed): void { $closed++; });
App::lastWindowClosed();
same(1, $closed, 'the listener heard it');
$off();

// Directories: the Mac's, or what setPath set.
$home = (string)getenv('HOME');
same(["$home/Movies", "$home/Library/Application Support"], [App::path(PathName::Videos), App::path(PathName::AppData)], 'the Mac\'s names');
App::setPath(PathName::Downloads, '/tmp/dl');
same('/tmp/dl', App::path(PathName::Downloads), 'setPath overrides');

// Window state: as window-state.json keeps it, mygo's JSON; whether a frame shows on a display.
$st = new WindowState(10, 20, 800, 600, maximized: true);
same(['x' => 10, 'y' => 20, 'width' => 800, 'height' => 600, 'maximized' => true], $st->toArray(), 'the state\'s JSON');
check(WindowState::fromArray(['x' => 1, 'y' => 2, 'w' => 3, 'h' => 4]) === null, 'the old shape is no state');
$work = [new Rectangle(0, 25, 1440, 875)];
check(WindowStates::onDisplay(new Rectangle(1300, 800, 400, 300), $work), 'a window mostly off the display still reaches it');
check(!WindowStates::onDisplay(new Rectangle(1400, 860, 400, 300), $work), 'one with 40 by 15 points on it does not');
$dir = sys_get_temp_dir() . '/myphp-ws-' . getmypid();
@mkdir($dir);
$file = new WindowStateFile("$dir/window-state.json");
same(null, $file->loadWindowStates(), 'no file: no states');
$file->saveWindowStates(['main' => $st]);
same(['main' => $st->toArray()], array_map(fn(WindowState $s) => $s->toArray(), $file->loadWindowStates()), 'saved and read back');
@unlink("$dir/window-state.json"); @rmdir($dir);
$store = new class implements WindowStateStore {
    public array $saved = [];
    public function loadWindowStates(): ?array { return ['doc' => new WindowState(1, 2, 300, 200)]; }
    public function saveWindowStates(array $states): void { $this->saved = $states; }
};
App::setWindowStateStore($store);
WindowStates::reset();
same(300, WindowStates::get('doc')?->width, 'the app\'s store gives the states');
WindowStates::set('doc', new WindowState(5, 6, 320, 240, fullScreen: true));
WindowStates::save();
check(($store->saved['doc'] ?? null)?->fullScreen === true, 'and keeps them');
App::setWindowStateStore(null);
WindowStates::reset();

// Menu items: radio items next to each other are a group; hidden, checked and enabled states.
$a = new MenuItem('Small', type: MenuItem::RADIO, checked: true);
$b = new MenuItem('Large', type: MenuItem::RADIO);
$other = new MenuItem('Other', type: MenuItem::RADIO, checked: true);
$m = new Menu([$a, $b, MenuItem::separator(), $other]);
$b->setChecked(true);
same([false, true, true], [$a->isChecked(), $b->isChecked(), $other->isChecked()], 'checking one unchecks its group, not past the separator');
$sub = new MenuItem('Size', submenu: [$x = new MenuItem('S', type: MenuItem::RADIO, checked: true), $y = new MenuItem('L', type: MenuItem::RADIO)]);
$y->setChecked(true);
check(!$x->isChecked(), 'in a submenu too');
$b->setVisible(false); $b->setEnabled(false);
check(!$b->isVisible() && !$b->isEnabled(), 'hidden and disabled');
$m->append($c = new MenuItem('Extra', id: 'extra'));
$m->insert(0, $first = new MenuItem('First'));
same(['First', 'Small', 'Large', '', 'Other', 'Extra'], array_map(fn(MenuItem $i) => $i->label, $m->items()), 'append and insert');
check($m->itemById('extra') === $c, 'an item appended is found by its id');
same([1.1, 0.9, 5.0, 0.25], [Menu::nextZoom(1.0, 1), Menu::nextZoom(1.0, -1), Menu::nextZoom(5.0, 1), Menu::nextZoom(0.25, -1)], 'the zoom steps');

// Events: names of their own, once each.
$e = new Event('app-test-progress', 'int');
same('int', Event::declared()['app-test-progress'] ?? null, 'declared with its type');
try { new Event('app-test-progress'); check(false, 'a name twice'); } catch (\InvalidArgumentException) { check(true, 'a name is declared once'); }
try { new Event('myphp:x'); check(false, 'a reserved name'); } catch (\InvalidArgumentException) { check(true, 'myphp: names are reserved'); }

// A notification's click: its listeners, then the app's.
$heard = [];
App::onNotificationClick(function (string $id) use (&$heard): void { $heard[] = "app $id"; });
Notification::clicked('earlier-run');
same(['app earlier-run'], $heard, 'one of an earlier run reaches the app');

// The theme's preferences, as pages get them.
$p = new ThemePreferences(0x007aff, reduceMotion: true, textScale: 1.0);
$json = json_encode($p);
same('{"accent":"#007aff","reduceMotion":true,"reduceTransparency":false,"highContrast":false,"textScale":1}', $json, 'the JSON of pages');
check($p == ThemePreferences::fromJSON($json), 'and back');
same(null, ThemePreferences::fromJSON('{"accent":null}')->accent, 'no accent');
