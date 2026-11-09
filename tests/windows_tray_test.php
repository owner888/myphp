<?php
// The tray on Windows (Platform\Windows\Tray), in an app of its own (testdata/windows_tray_app.php):
// an icon numbered by its ID, of a template image, a tooltip and a menu; the notification area's
// callback message as a click and as a right click, which shows its menu; the taskbar's appearance;
// the icon destroyed.
declare(strict_types=1);

namespace MyPHP\Tests;

(function (): void {
    if (PHP_OS_FAMILY !== 'Windows') {
        echo "  skipped: Windows's\n";
        return;
    }
    $logs = [(string)tempnam(sys_get_temp_dir(), 'myphp-out'), (string)tempnam(sys_get_temp_dir(), 'myphp-err')];
    $p = proc_open([PHP_BINARY, '-d', 'ffi.enable=1', __DIR__ . '/testdata/windows_tray_app.php'], [0 => ['file', DEVNULL, 'r'], 1 => ['file', $logs[0], 'w'], 2 => ['file', $logs[1], 'w']], $pipes);
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
    same(1, $r['clicked'], 'a left button\'s release on the icon a click');
    same(1, $r['rightClicked'], 'a right one\'s a right click, its menu shown and closed');
    check(is_bool($r['dark']), 'the taskbar dark or light');
    check($r['destroyed'], 'the icon destroyed');
})();
