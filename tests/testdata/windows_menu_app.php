<?php
// The menus on Windows (windows_menu_test.php): the application menu as a window's menu bar, its
// items' labels and shortcuts as Windows shows them, an item chosen by its path, by its shortcut
// and by WM_COMMAND, a check mark kept current, a submenu filled as it opens, the content's size
// kept with the bar, a context menu shown and closed.
declare(strict_types=1);

use MyPHP\{App, Menu, MenuItem, Window, WindowOptions};
use MyPHP\Platform\Windows\Win32;
use MyPHP\UI\Content;

require __DIR__ . '/../../src/autoload.php';

$log = function (string $where): void { fwrite(STDERR, "at: $where\n"); fflush(STDERR); };
$r = ['hi' => 0, 'opened' => 0];
// What the test reads of the native menus.
$m = FFI::cdef(<<<'C'
    void *GetMenu(void *hwnd);
    int32_t GetMenuItemCount(void *menu);
    int32_t GetMenuStringW(void *menu, uint32_t item, uint16_t *text, int32_t max, uint32_t flags);
    void *GetSubMenu(void *menu, int32_t position);
    uint32_t GetMenuState(void *menu, uint32_t id, uint32_t flags);
    uint32_t GetMenuItemID(void *menu, int32_t position);
    int32_t GetKeyboardState(uint8_t *s);
    int32_t SetKeyboardState(uint8_t *s);
    int32_t EndMenu(void);
    C, 'user32.dll');
$labels = function ($menu) use ($m): array {
    $out = [];
    for ($i = 0, $n = $m->GetMenuItemCount($menu); $i < $n; $i++) {
        $buf = $m->new('uint16_t[256]');
        $len = $m->GetMenuStringW($menu, $i, $buf, 256, 0x400);
        $out[] = $len > 0 ? (string)iconv('UTF-16LE', 'UTF-8', FFI::string($buf, $len * 2)) : '';
    }
    return $out;
};
App::setName('MenuTest');
App::setMenu(new Menu([
    new MenuItem('File', submenu: [
        new MenuItem('Say Hi', click: function () use (&$r) { $r['hi']++; }, accelerator: 'CmdOrCtrl+H'),
        new MenuItem(type: 'separator'),
        new MenuItem('Pinned', type: 'checkbox'),
    ]),
    new MenuItem('Recent', onOpen: function (MenuItem $it) use (&$r) {
        $r['opened']++;
        $it->setSubmenu([new MenuItem('One'), new MenuItem('Two')]);
    }),
]));
App::whenReady(function () use (&$r, $log, $m, $labels) {
    $log('ready');
    $w = new Window(new WindowOptions(title: 'Menus', width: 400, height: 300, content: Content::view(fn() => Color(Color::rgb(0xff0000)))));
    $w->show();
    App::after(0.4, function () use ($w, &$r, $log, $m, $labels) {
        $log('the bar');
        $hwnd = $w->native()->hwnd;
        $u = Win32::user32();
        $bar = $m->GetMenu($hwnd);
        $r['bar'] = $bar === null ? null : $labels($bar);
        $file = $m->GetSubMenu($bar, 0);
        $r['file'] = $labels($file);
        $r['content'] = $w->contentSize();
        $w->native()->chooseMenuItem(['File', 'Say Hi']);
        $r['chosen'] = $r['hi'];
        // Ctrl held, as the thread's keyboard state has it, and H.
        $state = $m->new('uint8_t[256]');
        $m->GetKeyboardState($state);
        $state[0x11] = 0x80; $state[0xA2] = 0x80;
        $m->SetKeyboardState($state);
        $w->input->events = [];
        $u->SendMessageW($hwnd, Win32::WM_KEYDOWN, 0x48, 1 | (0x23 << 16));
        $u->SendMessageW($hwnd, Win32::WM_CHAR, 0x08, 1 | (0x23 << 16));
        $state[0x11] = 0; $state[0xA2] = 0;
        $m->SetKeyboardState($state);
        $r['shortcut'] = [$r['hi'], count($w->input->events)];
        $u->SendMessageW($hwnd, Win32::WM_COMMAND, $m->GetMenuItemID($file, 0), 0);
        $r['command'] = $r['hi'];
        $w->native()->chooseMenuItem(['File', 'Pinned']);
        $r['checked'] = ($m->GetMenuState($file, 2, 0x400) & 0x8) !== 0;
        $recent = $m->GetSubMenu($bar, 1);
        $u->SendMessageW($hwnd, Win32::WM_INITMENUPOPUP, \MyPHP\Native\Mem::addr($recent), 1);
        $r['recent'] = [$r['opened'], $labels($recent)];
        $log('a context menu');
        App::after(0.2, fn() => $m->EndMenu());
        $menu = new Menu([new MenuItem('Copy here'), new MenuItem('Move here')]);
        $menu->popupAt($w, 20, 20);
        $r['popupClosed'] = true;
        $w->close();
        echo json_encode($r), "\n";
        App::quit();
    });
});
App::run();
