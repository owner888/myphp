<?php
// The Windows backend without windows (Platform\Windows), in an app of its own
// (testdata/windows_backend_app.php): the event loop's order, a wait, the paths in
// AppData, the displays in points, the appearance, the languages and formats, the
// clipboard's text, power, the shell.
declare(strict_types=1);

namespace MyPHP\Tests;

(function (): void {
    if (PHP_OS_FAMILY !== 'Windows') {
        echo "  skipped: Windows's\n";
        return;
    }
    // Its output and log to files, read as it goes: a report that never comes, or an app that does not end after it, is told.
    $logs = [(string)tempnam(sys_get_temp_dir(), 'myphp-out'), (string)tempnam(sys_get_temp_dir(), 'myphp-err')];
    $p = proc_open([PHP_BINARY, '-d', 'ffi.enable=1', __DIR__ . '/testdata/windows_backend_app.php'], [0 => ['file', 'NUL', 'r'], 1 => ['file', $logs[0], 'w'], 2 => ['file', $logs[1], 'w']], $pipes);
    $start = microtime(true);
    while (($st = proc_get_status($p))['running'] && microtime(true) - $start < 30) { usleep(50000); }
    if ($st['running']) { proc_terminate($p); }
    proc_close($p);
    $out = (string)file_get_contents($logs[0]); $err = substr((string)file_get_contents($logs[1]), -1500);
    array_map('unlink', $logs);
    check(!$st['running'], 'the app ends as it quits' . ($st['running'] ? ": it ran on, its output: $out | its log: $err" : ''));
    $line = array_values(array_filter(explode("\n", (string)$out), fn(string $l) => str_starts_with($l, '{')))[0] ?? '';
    $r = json_decode($line, true);
    if (!is_array($r)) { check(false, "the app's report: $out $err"); return; }
    echo '  ' . $line . "\n"; // what the runner's Windows has, for the log
    same(['ready', 'later', 'after 0', 'after 30 ms', 'after 80 ms'], $r['events'], 'the loop: ready, what later() queued, the timers by their time');
    check($r['waited'] >= 0.09 && $r['waited'] < 1, "a wait turns the loop till a timer ends it ({$r['waited']} s)");
    same($r['appData'] . '\\BackendTest', $r['userData'], 'the user data in the roaming AppData');
    check(count($r['displays']) >= 1 && array_filter($r['displays'], fn(array $d) => $d['primary']) !== [], 'a display, one of them primary');
    check(array_filter($r['displays'], fn(array $d) => $d['w'] <= 0 || $d['h'] <= 0 || $d['scale'] < 1) === [], 'each of a size, its scale 1 or more');
    check(is_bool($r['dark']) && $r['preferences']['textScale'] >= 1, 'the appearance and the preferences read');
    check($r['languages'] !== [] && preg_match('/^[a-z]{2,3}(-[A-Za-z0-9]+)*$/', $r['languages'][0]) === 1, 'the display languages, as tags');
    check($r['region'][0] !== '' && $r['region'][1] >= 0 && in_array($r['region'][2], [12, 24], true), 'the regional formats: a locale, a first weekday, an hour cycle');
    check($r['doubleClick'] > 0 && $r['doubleClick'] < 2, 'the double-click time');
    same('myphp 剪贴板 ✓', $r['clipboard'], 'the clipboard: text written read back');
    check(in_array('CF_UNICODETEXT', $r['clipboardFormats'] ?? [], true), 'the clipboard\'s formats named');
    same([true, false], $r['local'], 'a file of a drive here is local, a share\'s not');
})();
