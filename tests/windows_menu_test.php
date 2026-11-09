<?php
// The menus on Windows (Platform\Windows\Menus), in an app of its own
// (testdata/windows_menu_app.php): the application menu as a window's menu bar, its items'
// labels and shortcuts as Windows shows them, an item run as chosen by its path, by its shortcut
// (taking the key from the content) and by WM_COMMAND, a check mark kept current, a submenu
// filled as it opens, the content's size kept with the bar, a context menu shown and closed; on
// Windows 11 the popups' items drawn in its style (MenuDraw).
declare(strict_types=1);

namespace MyPHP\Tests;

(function (): void {
    if (PHP_OS_FAMILY !== 'Windows') {
        echo "  skipped: Windows's\n";
        return;
    }
    $logs = [(string)tempnam(sys_get_temp_dir(), 'myphp-out'), (string)tempnam(sys_get_temp_dir(), 'myphp-err')];
    $p = proc_open([PHP_BINARY, '-d', 'ffi.enable=1', __DIR__ . '/testdata/windows_menu_app.php'], [0 => ['file', DEVNULL, 'r'], 1 => ['file', $logs[0], 'w'], 2 => ['file', $logs[1], 'w']], $pipes);
    $start = microtime(true);
    while (($st = proc_get_status($p))['running'] && microtime(true) - $start < 30) { usleep(50000); }
    $hung = $st['running'];
    if ($hung) { proc_terminate($p); }
    $code = proc_close($p);
    $out = (string)file_get_contents($logs[0]);
    // How it ended, then where it got to (the app's "at:" lines), then the log's end: what fits in a failure's message first.
    $log = (string)file_get_contents($logs[1]);
    preg_match_all('/^at: .*$/m', $log, $at);
    $err = ($hung ? 'hung' : "exit $code") . ', at ' . implode(', ', array_map(fn(string $l) => substr(trim($l), 4), $at[0])) . ' | ' . substr($log, -1200);
    $oneLine = fn(string $s): string => str_replace(["\r\n", "\n", "\r"], ' / ', trim($s)); // a failure's message is one line (run.php --each reads them so)
    array_map('unlink', $logs);
    $line = array_values(array_filter(explode("\n", $out), fn(string $l) => str_starts_with($l, '{')))[0] ?? '';
    $r = json_decode($line, true);
    if (!is_array($r)) { check(false, "the app's report: {$oneLine($out)} | its log: {$oneLine($err)}"); return; }
    echo "  $line\n";
    check(!$hung, 'the app ends as it quits');
    same(['File', 'Recent'], $r['bar'], 'the menu bar, the application menu\'s');
    same(["Say Hi\tCtrl+H", '', 'Pinned'], $r['file'], 'File\'s items, the shortcut as Windows shows it');
    same([400, 300], $r['content'], 'the content of the size asked, the bar besides (Windows 11\'s taller)');
    same($r['drawn'][0], $r['drawn'][1], 'popup items drawn by the app on Windows 11, by Windows before (' . ($r['drawn'][0] ? 'Windows 11' : 'Windows 10') . ')');
    check($r['icon'], 'Say Hi\'s icon, ' . ($r['drawn'][0] ? 'drawn in its column' : 'its bitmap'));
    same([16, 16, 'FFFF8000', '00000000'], $r['iconPixels'], 'the icon fit to its box, a template in the color asked');
    same(1, $r['chosen'], 'an item chosen by its path ran');
    same([2, 0], $r['shortcut'], 'Ctrl+H ran it, the key and its character the content\'s no more');
    same(3, $r['command'], 'WM_COMMAND ran it');
    check($r['checked'], 'Pinned checked once chosen');
    same([1, ['One', 'Two']], $r['recent'], 'Recent filled as it opens');
    check($r['popupClosed'], 'a context menu shown, and closed');
})();
