<?php
// The dialogs on Windows (windows_dialogs_test.php): an open and a save dialog shown and canceled; a
// task dialog's button pressed, its check box checked, then one closed as its cancel button. Each is
// modal: a timer, which its loop runs, finds its window by its title and answers it.
declare(strict_types=1);

use MyPHP\{App, FileFilter, MessageOptions, OpenDialogOptions, SaveDialogOptions};

require __DIR__ . '/../../src/autoload.php';

$log = function (string $where): void { fwrite(STDERR, "at: $where\n"); fflush(STDERR); };
$r = [];
$u = FFI::cdef('void *FindWindowW(const uint16_t *cls, const uint16_t *title); int32_t PostMessageW(void *hwnd, uint32_t msg, uintptr_t wp, intptr_t lp); intptr_t SendMessageW(void *hwnd, uint32_t msg, uintptr_t wp, intptr_t lp);', 'user32.dll');
$wide = function (string $s) use ($u): FFI\CData {
    $w = (string)iconv('UTF-8', 'UTF-16LE', $s) . "\0\0";
    $buf = $u->new('uint16_t[' . (strlen($w) >> 1) . ']');
    FFI::memcpy($buf, $w, strlen($w));
    return $buf;
};
// Answers the dialog of $title with $answer(hwnd) once it shows, looking every 0.2 s for 10 s; records whether it showed.
$answer = function (string $title, Closure $act) use ($u, $wide, &$r, &$answer): void {
    $tries = 0;
    $look = function () use ($u, $wide, $title, $act, &$tries, &$look, &$r): void {
        $h = $u->FindWindowW($wide('#32770'), $wide($title));
        if ($h !== null) {
            fwrite(STDERR, "at: found $title\n");
            $r['found'][$title] = true;
            $act($h);
        } elseif (++$tries < 50) {
            App::after(0.2, $look);
        }
    };
    App::after(0.2, $look);
};
App::setName('DialogsTest');
App::whenReady(function () use (&$r, $log, $u, $answer) {
    $d = App::backend()->dialogs();
    $close = fn($h) => $u->PostMessageW($h, 0x0010, 0, 0); // WM_CLOSE
    $log('open');
    $answer('Open test', $close);
    $r['open'] = $d->open(new OpenDialogOptions(title: 'Open test', defaultPath: sys_get_temp_dir(), filters: [new FileFilter('Text', ['txt', 'md'])], multiple: true));
    $log('save');
    $answer('Save test', $close);
    $r['save'] = $d->save(new SaveDialogOptions(title: 'Save test', defaultPath: sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'notes.txt'));
    $log('a message');
    // A task dialog's check box checked and its second button pressed (TDM_CLICK_VERIFICATION, TDM_CLICK_BUTTON); a plain message box's No (IDNO), where there is no task dialog.
    $answer('Message test', function ($h) use ($u) {
        $u->SendMessageW($h, 0x0400 + 113, 1, 0);
        $u->PostMessageW($h, 0x0400 + 102, 101, 0);
        $u->PostMessageW($h, 0x0111, 7, 0);
    });
    $m = $d->message(new MessageOptions(type: 'warning', title: 'Message test', message: 'Delete the file?', detail: 'It cannot be undone.', checkboxLabel: 'Do not ask again'), ['Keep', 'Delete', 'Cancel'], 2);
    $r['message'] = [$m->button, $m->checkboxChecked];
    $r['taskDialog'] = (new ReflectionProperty(\MyPHP\Platform\Windows\Dialogs::class, 'taskDialog'))->getValue() !== false;
    $log('a message canceled');
    $answer('Cancel test', $close);
    $m = $d->message(new MessageOptions(title: 'Cancel test', message: 'Closed as canceled'), ['OK', 'Cancel'], 1);
    $r['canceled'] = [$m->button, $m->checkboxChecked];
    echo json_encode($r), "\n";
    App::quit();
});
App::run();
