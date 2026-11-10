<?php
// A page of WebView2 on Windows (Platform\Windows\WebView), in an app of its own
// (testdata/windows_webview_app.php): the runtime found and its environment made without
// WebView2Loader.dll, a window of HTML shown, its document read, the bridge's call to PHP answered,
// new HTML loaded, a scheme's page and its fetch served, cookies set and deleted before a page shows
// and after, content rules applied.
declare(strict_types=1);

namespace MyPHP\Tests;

(function (): void {
    if (PHP_OS_FAMILY !== 'Windows') {
        echo "  skipped: Windows's\n";
        return;
    }
    $logs = [(string)tempnam(sys_get_temp_dir(), 'myphp-out'), (string)tempnam(sys_get_temp_dir(), 'myphp-err')];
    $p = proc_open([PHP_BINARY, '-d', 'ffi.enable=1', __DIR__ . '/testdata/windows_webview_app.php'], [0 => ['file', DEVNULL, 'r'], 1 => ['file', $logs[0], 'w'], 2 => ['file', $logs[1], 'w']], $pipes);
    $start = microtime(true);
    while (($st = proc_get_status($p))['running'] && microtime(true) - $start < 90) { usleep(50000); }
    $hung = $st['running'];
    if ($hung) { proc_terminate($p); }
    $code = proc_close($p);
    $out = (string)file_get_contents($logs[0]);
    // How it ended, then where it got to (the app's "at:" lines), then the log's end: what fits in a failure's message first.
    $log = (string)file_get_contents($logs[1]);
    preg_match_all('/^at: .*$/m', $log, $at);
    preg_match_all('/\[windows\]\[\w\] (.*)$/m', $log, $said); // the backend's last words of WebView2 (its steps, debug), first: a failure's message is cut at 200 bytes
    $err = ($hung ? 'hung' : "exit $code") . ': ' . implode(' / ', array_map('trim', array_slice($said[1], -3))) . ' | at ' . implode(', ', array_map(fn(string $l) => substr(trim($l), 4), $at[0])) . ' | ' . substr($log, -1200);
    $oneLine = fn(string $s): string => str_replace(["\r\n", "\n", "\r"], ' / ', trim($s)); // a failure's message is one line (run.php --each reads them so)
    array_map('unlink', $logs);
    $line = array_values(array_filter(explode("\n", $out), fn(string $l) => str_starts_with($l, '{')))[0] ?? '';
    $r = json_decode($line, true);
    if (!is_array($r)) { check(false, "the app's report: {$oneLine($out)} | its log: {$oneLine($err)}"); return; }
    echo "  $line\n";
    check(!$hung, 'the app ends as it quits');
    check($r['available'], 'the WebView2 Runtime found');
    same('Hello', $r['heading'], 'a window of HTML shown, its document read');
    same('From JS', $r['title'], "the page's bridge called PHP, which answered" . (isset($r['bridgeError']) ? ": {$r['bridgeError']}" : ''));
    same('Second', $r['second'], 'new HTML loaded');
    check(is_float($r['zoom']) && abs($r['zoom'] - 1.0) < 1e-6, 'its zoom the controller\'s');
    same('served at wvtest://localhost/index.html', $r['served'] ?? null, 'a page of a scheme PHP serves, the request\'s URL the scheme\'s');
    same('wvtest://localhost/index.html', $r['servedURL'] ?? null, 'its URL the scheme\'s, not WebView2\'s http://wvtest.localhost/');
    same('POST:hi', $r['fetched'] ?? null, 'its fetch of the scheme answered, with its method and body');
    foreach (['cookiesHidden' => 'before a page shows (a hidden web view\'s)', 'cookiesShown' => 'of a page shown'] as $k => $what) {
        same(['listed' => ['k=v;example.com;/;' . (new \DateTimeImmutable('+30 days 00:00 UTC'))->format('Y-m-d')], 'after' => 0], $r[$k] ?? null, "cookies set, listed and deleted $what");
    }
    check(!isset($r['rulesError']), 'content rules compiled' . (isset($r['rulesError']) ? ": {$r['rulesError']}" : ''));
    same('none', $r['hidden'] ?? null, 'content rules hide what a css-display-none rule selects');
    same('false', $r['ran'] ?? null, 'content rules block a script');
})();
