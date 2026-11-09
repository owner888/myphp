<?php
// The tray on Windows (windows_tray_test.php): a notification area icon numbered by its ID, a template
// image, a tooltip and a menu; the notification area's callback message sent to the app's window as
// a click and a right click, which shows the menu (closed again); the icon destroyed.
declare(strict_types=1);

use MyPHP\{App, Menu, MenuItem, Tray, TrayOptions};
use MyPHP\Platform\Windows\{Backend, Win32};

require __DIR__ . '/../../src/autoload.php';

$log = function (string $where): void { fwrite(STDERR, "at: $where\n"); fflush(STDERR); };
$r = ['clicks' => 0, 'rightClicks' => 0];
// A black, transparent-cornered 32 × 32 template image.
$raw = '';
for ($y = 0; $y < 32; $y++) {
    $raw .= "\0";
    for ($x = 0; $x < 32; $x++) { $raw .= "\0\0\0" . (($x - 15.5) ** 2 + ($y - 15.5) ** 2 < 196 ? "\xff" : "\0"); }
}
$chunk = fn(string $type, string $data): string => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
$png = "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', 32, 32, 8, 6, 0, 0, 0)) . $chunk('IDAT', \MyPHP\Native\Zlib::deflate($raw, 15, 6)) . $chunk('IEND', '');
App::setName('TrayTest');
App::whenReady(function () use (&$r, $log, $png) {
    $log('ready');
    $tray = new Tray(new TrayOptions(icon: $png, iconIsTemplate: true, toolTip: 'Tray test', id: 'myphp-tray-test', menu: new Menu([new MenuItem('Open'), new MenuItem('Quit')])));
    $tray->onClick(function () use (&$r) { $r['clicks']++; });
    $tray->onRightClick(function () use (&$r) { $r['rightClicks']++; });
    App::after(0.3, function () use ($tray, &$r, $log) {
        $log('clicking');
        $u = Win32::user32();
        $hwnd = App::backend()->hwnd();
        $id = hexdec(hash('fnv1a32', 'myphp-tray-test')) | (1 << 31);
        $u->SendMessageW($hwnd, Backend::WM_APP_TRAY, $id, Win32::WM_LBUTTONUP);
        $r['clicked'] = $r['clicks'];
        $log('its menu');
        $m = FFI::cdef('int32_t EndMenu(void);', 'user32.dll');
        App::after(0.2, fn() => $m->EndMenu());
        $u->SendMessageW($hwnd, Backend::WM_APP_TRAY, $id, Win32::WM_RBUTTONUP);
        $r['rightClicked'] = $r['rightClicks'];
        $r['dark'] = $tray->dark();
        $r['bounds'] = (array)$tray->bounds();
        $tray->destroy();
        $r['destroyed'] = true;
        echo json_encode($r), "\n";
        App::quit();
    });
});
App::run();
