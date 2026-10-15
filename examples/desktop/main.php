<?php
// Menus and dialogs around a native window: a menu bar with the standard
// menus plus a Notes menu (an accelerator, a checkbox, a disabled item), a
// context menu on right click, and the open/save/message dialogs as sheets
// of the window (parent: Window::key()); without a parent they are app-modal.
// Run: ./run.sh desktop
declare(strict_types=1);

use MyPHP\{App, Dialog, FileFilter, Menu, MenuItem, MessageOptions, OpenDialogOptions, SaveDialogOptions, Window, WindowOptions};
use MyPHP\UI\{Content, Context};

spl_autoload_register(fn($c) => require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php');

$s = ['log' => ['Use the menu, the buttons, or right-click.'], 'wrap' => true, 'window' => null];
$log = function (string $line) use (&$s) { $s['log'][] = $line; $s['window']?->update(); };

$open = function () use ($log) {
    $paths = Dialog::open(new OpenDialogOptions(parent: Window::key(), title: 'Open notes', multiple: true,
        filters: [new FileFilter('Text', ['txt', 'md']), new FileFilter('PHP', ['php'])]));
    $log($paths ? 'opened: ' . implode(', ', array_map('basename', $paths)) : 'open canceled');
};
$save = function () use ($log) {
    $path = Dialog::save(new SaveDialogOptions(parent: Window::key(), defaultPath: getenv('HOME') . '/notes.txt', filters: [new FileFilter('Text', ['txt'])]));
    $log($path !== '' ? "save to $path" : 'save canceled');
};
$ask = function () use ($log) {
    $r = Dialog::message(new MessageOptions(parent: Window::key(), type: 'question', message: 'Discard the changes?',
        detail: 'There is no undo for this.', buttons: ['Discard', 'Keep editing'], cancelButton: 1, checkboxLabel: "Don't ask again"));
    $log("message: button {$r->button}" . ($r->checkboxChecked ? ', checkbox on' : ''));
};

$wrapItem = new MenuItem('Wrap lines', type: MenuItem::CHECKBOX, checked: true, accelerator: 'Cmd+Alt+W',
    click: function (MenuItem $it) use (&$s, $log) { $s['wrap'] = $it->checked; $log('wrap: ' . ($it->checked ? 'on' : 'off')); });
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
$context = new Menu([new MenuItem('Open…', $open), new MenuItem('Save As…', $save), MenuItem::separator(), new MenuItem('Clear log', function () use (&$s) { $s['log'] = []; })]);

App::setName('Desktop');
App::whenReady(function () use (&$s, $open, $save, $ask, $context) {
    $s['window'] = $w = new Window(new WindowOptions(title: 'MyPHP desktop — menus and dialogs', width: 640, height: 420,
        content: Content::view(function (Context $c) use (&$s, $open, $save, $ask, $context, &$w) {
            $c->column(16, 10, 1);
              $c->row(0, 8);
                if ($c->clicked($c->button('Open…'))) $open();
                if ($c->clicked($c->button('Save As…'))) $save();
                if ($c->clicked($c->button('Ask…'))) $ask();
              $c->end();
              $area = $c->column(12, 4, 1, 0xf5f5f7);
                foreach (array_slice($s['log'], -12) as $line) {
                    $c->text($line, 13, 0x444444);
                }
              $c->end();
              if ($c->rightClicked($area)) $context->popup($w);
            $c->end();
        })));
});
App::run();
