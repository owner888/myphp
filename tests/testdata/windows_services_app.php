<?php
// The system's services on Windows (windows_services_test.php): a global shortcut registered, its
// WM_HOTKEY told, the same keys refused a second time, unregistered; a notification shown as a
// balloon of a temporary icon of the app's, its click told, the icon gone with it; a secret kept in the
// Credential Manager, read back, replaced, deleted; the network's status, its changes watched.
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
    $log('secrets');
    $s = App::backend()->secrets();
    $secret = "pa\u{00DF}w\u{00F6}rd \u{1F511}\0\xff"; // UTF-8 and bytes that are none
    $s->setSecret('myphp-test', 'alice', $secret);
    $r['secret'] = $s->secret('myphp-test', 'alice') === $secret;
    $s->setSecret('myphp-test', 'alice', 'second');
    $r['replaced'] = $s->secret('myphp-test', 'alice');
    $s->setSecret('myphp-test', 'empty', '');
    $r['empty'] = $s->secret('myphp-test', 'empty');
    $s->deleteSecret('myphp-test', 'alice');
    $s->deleteSecret('myphp-test', 'empty');
    try {
        $s->secret('myphp-test', 'alice');
        $r['deleted'] = false;
    } catch (\MyPHP\SecretNotFound) {
        $r['deleted'] = true;
    }
    $s->deleteSecret('myphp-test', 'alice'); // none: succeeds
    $log('the network');
    $n = App::backend()->network();
    $status = $n->status();
    $r['network'] = [$status->online, $status->type];
    $n->watch();
    App::after(0.2, function () use (&$r) {
        echo json_encode($r), "\n";
        App::quit();
    });
});
App::run();
