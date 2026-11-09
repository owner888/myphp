<?php
// The system's services on Windows (windows_services_test.php): a global shortcut registered, its
// WM_HOTKEY told, the same keys refused a second time, unregistered; a notification shown as a
// balloon of a temporary icon of the app's, its click told, the icon gone with it.
declare(strict_types=1);

use MyPHP\{App, GlobalShortcut, Notification, NotificationOptions};
use MyPHP\Platform\Windows\{Backend, Win32};

require __DIR__ . '/../../src/autoload.php';

$log = function (string $where): void { fwrite(STDERR, "at: $where\n"); fflush(STDERR); };
$r = ['hotkey' => 0, 'clicked' => 0];
App::setName('ServicesTest');
App::whenReady(function () use (&$r, $log) {
    $log('a global shortcut');
    $u = Win32::user32();
    $hwnd = App::backend()->hwnd();
    GlobalShortcut::register('Ctrl+Alt+Shift+F9', function () use (&$r) { $r['hotkey']++; });
    $u->SendMessageW($hwnd, Win32::WM_HOTKEY, 1, 0); // the first registered is 1
    $r['pressed'] = $r['hotkey'];
    try {
        App::backend()->registerHotkey(99, 'Ctrl+Alt+Shift+F9');
        $r['twice'] = 'registered';
    } catch (\RuntimeException $e) {
        $r['twice'] = 'refused';
    }
    GlobalShortcut::unregister('Ctrl+Alt+Shift+F9');
    $r['unregistered'] = !GlobalShortcut::isRegistered('Ctrl+Alt+Shift+F9');
    $log('a notification');
    $n = new Notification(new NotificationOptions(title: 'Hello', body: 'From the test', subtitle: 'myphp'));
    $n->onClick(function () use (&$r) { $r['clicked']++; });
    try {
        $n->show();
        $r['shown'] = 'shown';
        // the balloon clicked, as the notification area tells the temporary icon (the first icon made, 1)
        $u->SendMessageW($hwnd, Backend::WM_APP_TRAY, 1, Win32::WM_USER + 5);
    } catch (\RuntimeException $e) {
        $r['shown'] = $e->getMessage(); // a session without a notification area
    }
    App::after(0.2, function () use (&$r) {
        echo json_encode($r), "\n";
        App::quit();
    });
});
App::run();
