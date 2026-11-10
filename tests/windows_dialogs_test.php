<?php
// The dialogs on Windows (Platform\Windows\Dialogs), in an app of its own
// (testdata/windows_dialogs_app.php): an open and a save dialog of the Common Item Dialog shown and
// canceled; a task dialog's button pressed and its check box checked; one closed as its cancel button;
// a prompt checked, taken and canceled, and a login.
declare(strict_types=1);

namespace MyPHP\Tests;

(function (): void {
    if (PHP_OS_FAMILY !== 'Windows') {
        echo "  skipped: Windows's\n";
        return;
    }
    $logs = [(string)tempnam(sys_get_temp_dir(), 'myphp-out'), (string)tempnam(sys_get_temp_dir(), 'myphp-err')];
    $p = proc_open([PHP_BINARY, '-d', 'ffi.enable=1', __DIR__ . '/testdata/windows_dialogs_app.php'], [0 => ['file', DEVNULL, 'r'], 1 => ['file', $logs[0], 'w'], 2 => ['file', $logs[1], 'w']], $pipes);
    $start = microtime(true);
    while (($st = proc_get_status($p))['running'] && microtime(true) - $start < 60) { usleep(50000); }
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
    same(['Open test' => true, 'Save test' => true, 'Message test' => true, 'Cancel test' => true, 'DialogsTest' => true], $r['found'] ?? [], 'each dialog showed, titled as asked');
    same([], $r['open'], 'an open dialog canceled chooses nothing');
    same('', $r['save'], 'a save dialog canceled, no path');
    same([1, $r['taskDialog']], $r['message'], 'a ' . ($r['taskDialog'] ? 'task dialog' : 'plain message box (no task dialog)') . '\'s second button pressed, its check box checked');
    same([1, false], $r['canceled'], 'one closed as its cancel button');
    same('Too short', $r['complaint'] ?? null, "a prompt's check complains under its field");
    same('abc', $r['prompt'], 'a prompt: OK refused while its check complains, then its text');
    same(null, $r['promptCanceled'], 'a prompt canceled, null');
    same(['me', 'pw'], $r['login'], "a login's name and password");
})();
