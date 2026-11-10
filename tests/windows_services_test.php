<?php
// The system's services on Windows, in an app of its own (testdata/windows_services_app.php): a
// global shortcut registered, its WM_HOTKEY told, the same keys refused a second time, unregistered;
// a notification shown as a balloon of a temporary icon of the app's, its click told; a secret kept in
// the Credential Manager, read back byte for byte, replaced, deleted; the network's status; what plays
// shown in the system's media controls (SystemMediaTransportControls).
declare(strict_types=1);

namespace MyPHP\Tests;

(function (): void {
    if (PHP_OS_FAMILY !== 'Windows') {
        echo "  skipped: Windows's\n";
        return;
    }
    $logs = [(string)tempnam(sys_get_temp_dir(), 'myphp-out'), (string)tempnam(sys_get_temp_dir(), 'myphp-err')];
    $p = proc_open([PHP_BINARY, '-d', 'ffi.enable=1', __DIR__ . '/testdata/windows_services_app.php'], [0 => ['file', DEVNULL, 'r'], 1 => ['file', $logs[0], 'w'], 2 => ['file', $logs[1], 'w']], $pipes);
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
    same(1, $r['pressed'], 'a global shortcut\'s WM_HOTKEY ran its function');
    same('refused', $r['twice'], 'the same keys refused a second time');
    check($r['unregistered'], 'and unregistered');
    check($r['secret'], 'a secret read back byte for byte');
    same('second', $r['replaced'], 'and replaced');
    same('', $r['empty'], 'an empty one');
    check($r['deleted'], 'one deleted is not found, and deleting none succeeds');
    same(true, $r['nowPlaying'] ?? null, 'what plays shown in the system\'s media controls, then nothing');
    same([true, 'other'], $r['network'], 'the runner online, the interface untold');
    same([true, true, 'cafe'], $r['html'], 'HTML on the clipboard read back, as CF_HTML too, its text besides');
    same([true, true, true, true, false], $r['image'], 'a PNG read back, as "PNG" and a bitmap too; a PNG an image, other bytes none');
    same([2, 2, [9, 8, 7, 255, 12, 11, 10, 255], true], $r['bitmap'], 'another app\'s bitmap read as a PNG, its rows bottom up');
    if ($r['shown'] === 'shown') {
        same(1, $r['clicked'], 'a notification shown, its balloon\'s click told');
    } else {
        same('myphp: cannot show the notification', $r['shown'], 'a notification refused only where the session has no notification area');
    }
})();
