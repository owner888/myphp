<?php
// Menus and dialogs around a native window: a menu bar with the standard
// menus plus a Notes menu (an accelerator, a checkbox, a disabled item), a
// context menu on right click, and the open/save/message dialogs as sheets
// of the window (parent: Window::focused()); without a parent they are app-modal.
// Also a tray icon with its own menu, a global shortcut (Cmd+Shift+Space
// shows the window from anywhere), window events in the log, the window's
// frame remembered across runs, and a notification (from a built .app).
// Run: myphp run -d macos examples/desktop
declare(strict_types=1);

use MyPHP\{App, CloseEvent, Dialog, FileDropEvent, FileFilter, GlobalShortcut, Menu, MenuItem, MessageOptions, Notification, NotificationOptions, OpenDialogOptions, SaveDialogOptions, Tray, TrayOptions, Window, WindowOptions};
use MyPHP\UI\Content;

require __DIR__ . '/../../src/autoload.php'; // MyPHP, and its declarative views (VStack, Text…)

/** The window's state: its log, the line wrapping, the window, and what its buttons and context menu do (set below). */
$s = (object)['log' => ['Use the menu, the buttons, or right-click.'], 'wrap' => true, 'window' => null, 'closing' => false, 'actions' => [], 'open' => null, 'save' => null];
$log = function (string $line) use ($s) { $s->log[] = $line; $s->window?->invalidate(); };

$open = function () use ($log) {
    $paths = Dialog::open(new OpenDialogOptions(parent: Window::focused(), title: 'Open notes', multiple: true,
        filters: [new FileFilter('Text', ['txt', 'md']), new FileFilter('PHP', ['php'])]));
    $log($paths ? 'opened: ' . implode(', ', array_map('basename', $paths)) : 'open canceled');
};
$save = function () use ($log) {
    $path = Dialog::save(new SaveDialogOptions(parent: Window::focused(), defaultPath: getenv('HOME') . '/notes.txt', filters: [new FileFilter('Text', ['txt'])]));
    $log($path !== '' ? "save to $path" : 'save canceled');
};
$ask = function () use ($log) {
    $r = Dialog::message(new MessageOptions(parent: Window::focused(), type: 'question', message: 'Discard the changes?',
        detail: 'There is no undo for this.', buttons: ['Discard', 'Keep editing'], cancelButton: 1, checkboxLabel: "Don't ask again"));
    $log("message: button {$r->button}" . ($r->checkboxChecked ? ', checkbox on' : ''));
};

$wrapItem = new MenuItem('Wrap lines', type: MenuItem::CHECKBOX, checked: true, accelerator: 'Cmd+Alt+W',
    click: function (MenuItem $it) use (&$s, $log) { $s->wrap = $it->checked; $log('wrap: ' . ($it->checked ? 'on' : 'off')); });
App::setMenu(new Menu([
    new MenuItem(role: 'appMenu'),
    new MenuItem('File', submenu: [
        new MenuItem('Open…', $open, 'CmdOrCtrl+O'),
        new MenuItem('Save As…', $save, 'CmdOrCtrl+Shift+S'),
        MenuItem::separator(),
        new MenuItem(role: 'close'),
    ]),
    new MenuItem(role: 'editMenu'),
    new MenuItem('Notes', submenu: [
        $wrapItem,
        new MenuItem('Ask…', $ask, 'F5'),
        new MenuItem('Not yet', fn() => null, disabled: true),
    ]),
    new MenuItem(role: 'windowMenu'),
]));

$notify = function () use ($log) {
    if (!Notification::supported()) { $log('notifications need a built .app (myphp build); run from the bundle to try'); return; }
    (new Notification(new NotificationOptions(title: 'Desktop', body: 'Hello from PHP', id: 'hello')))->show();
    $log('notification shown');
};
App::onNotificationClick(fn(string $id) => $log("notification $id clicked"));
$s->actions = ['Open…' => $open, 'Save As…' => $save, 'Ask…' => $ask, 'Notify' => $notify];
[$s->open, $s->save] = [$open, $save]; // the context menu's, which view.php makes

/** A 16×16 PNG: a black ring, drawn as a template so the menu bar tints it. */
function ringPng(): string
{
    $rows = '';
    for ($y = 0; $y < 16; $y++) {
        $rows .= "\0";
        for ($x = 0; $x < 16; $x++) {
            $d = hypot($x - 7.5, $y - 7.5);
            $rows .= "\0\0\0" . chr($d >= 4 && $d <= 7 ? 255 : 0);
        }
    }
    $chunk = fn(string $type, string $data) => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    return "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', 16, 16, 8, 6, 0, 0, 0)) . $chunk('IDAT', gzcompress($rows)) . $chunk('IEND', '');
}

App::setName('Desktop');
App::whenReady(function () use ($s, $notify, $log) {
    $s->window = $w = new Window(new WindowOptions(title: 'MyPHP desktop — menus and dialogs', width: 640, height: 420, stateKey: 'main',
        content: Content::file(__DIR__ . '/view.php', $s)));
    foreach (['focus', 'blur', 'resize', 'move', 'minimize', 'restore', 'enterFullScreen', 'leaveFullScreen'] as $event) {
        $w->on($event, function (Window $w) use ($event, $log) { [$width, $height] = $w->size(); $log("$event ({$width}×{$height})"); });
    }
    $w->on('fileDrop', fn(FileDropEvent $e) => $log('dropped ' . implode(', ', array_map('basename', $e->paths)) . " at {$e->x},{$e->y}"));
    $w->on('close', function (CloseEvent $e) use ($s, $log) {
        if (!$s->closing) { $s->closing = true; $e->cancel(); $log('close canceled once; close again to quit'); }
    });
    $tray = new Tray(new TrayOptions(icon: ringPng(), iconIsTemplate: true, toolTip: 'MyPHP desktop',
        menu: new Menu([new MenuItem('Show window', fn() => $w->show()), new MenuItem('Notify', $notify), MenuItem::separator(), new MenuItem(role: 'quit')])));
    GlobalShortcut::register('CmdOrCtrl+Shift+Space', function () use ($w, $log) {
        $w->show();
        App::focus();
        $log('global shortcut pressed');
    });
    $log('tray icon added; Cmd+Shift+Space shows the window from any app');
});
App::run();
