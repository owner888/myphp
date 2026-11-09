<?php
// The system's services on Windows (windows_services_test.php): a global shortcut registered, its
// WM_HOTKEY told, the same keys refused a second time, unregistered; a notification shown as a
// balloon of a temporary icon of the app's, its click told, the icon gone with it; a secret kept in the
// Credential Manager, read back, replaced, deleted; the network's status, its changes watched; HTML and
// an image on the clipboard as other apps read them (CF_HTML, "PNG", CF_DIB) and read back, and a
// bitmap another app put there read as a PNG.
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
    $log('the clipboard');
    $c = App::backend()->clipboard();
    $html = '<p>caf<b>\u{00E9}</b> \u{1F600}</p>';
    $c->writeData(new \MyPHP\Transfer\Data(new \MyPHP\Transfer\Item(\MyPHP\Transfer\Representation::bytes('text/html', $html), \MyPHP\Transfer\Representation::bytes('text/plain', 'cafe'))), null);
    $r['html'] = [$c->readHTML() === $html, in_array('HTML Format', $c->availableFormats(), true), $c->readText()];
    $raw = '';
    foreach ([[[255, 0, 0, 255], [0, 255, 0, 128]], [[0, 0, 255, 255], [10, 20, 30, 255]]] as $row) { $raw .= "\0"; foreach ($row as $p) { $raw .= pack('C4', ...$p); } }
    $chunk = fn(string $type, string $data): string => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    $png = "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', 2, 2, 8, 6, 0, 0, 0)) . $chunk('IDAT', \MyPHP\Native\Zlib::deflate($raw, 15, 6)) . $chunk('IEND', '');
    $c->writeData(new \MyPHP\Transfer\Data(new \MyPHP\Transfer\Item(\MyPHP\Transfer\Representation::bytes('image/png', $png))), null);
    $formats = $c->availableFormats();
    $r['image'] = [$c->readImage() === $png, in_array('PNG', $formats, true), in_array('CF_DIB', $formats, true), $c->isImage($png), $c->isImage('not an image')];
    // another app's bitmap alone: a 2 × 2, 24-bit, bottom-up DIB
    $dib = pack('VllvvVVllVV', 40, 2, 2, 1, 24, 0, 0, 0, 0, 0, 0) . "\x01\x02\x03\x04\x05\x06\0\0" . "\x07\x08\x09\x0a\x0b\x0c\0\0";
    $k = Win32::kernel32();
    $u->OpenClipboard($hwnd);
    $u->EmptyClipboard();
    $h = $k->GlobalAlloc(0x2, strlen($dib));
    FFI::memcpy($k->GlobalLock($h), $dib, strlen($dib));
    $k->GlobalUnlock($h);
    $u->SetClipboardData(8, $h); // CF_DIB
    $u->CloseClipboard();
    $read = $c->readImage();
    [$w, $hh, $px] = $read === '' ? [0, 0, []] : \MyPHP\Native\Build\Png::decode($read);
    $r['bitmap'] = [$w, $hh, array_map('intval', array_slice($px, 0, 8)), in_array('image/png', $c->formats(), true)];
    $c->clear();
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
