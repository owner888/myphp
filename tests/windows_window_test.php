<?php
// A window of native UI on Windows (Platform\Windows\Window), in an app of its own
// (testdata/windows_window_app.php): its content in points at its display's scale, the frame
// around it, bounds set and read back, its title, minimized, restored, maximized, full screen
// and back, the events the core hears, closed; its content drawn by Direct3D 11 and read back.
declare(strict_types=1);

namespace MyPHP\Tests;

(function (): void {
    if (PHP_OS_FAMILY !== 'Windows') {
        echo "  skipped: Windows's\n";
        return;
    }
    $logs = [(string)tempnam(sys_get_temp_dir(), 'myphp-out'), (string)tempnam(sys_get_temp_dir(), 'myphp-err')];
    $p = proc_open([PHP_BINARY, '-d', 'ffi.enable=1', __DIR__ . '/testdata/windows_window_app.php'], [0 => ['file', DEVNULL, 'r'], 1 => ['file', $logs[0], 'w'], 2 => ['file', $logs[1], 'w']], $pipes);
    $start = microtime(true);
    while (($st = proc_get_status($p))['running'] && microtime(true) - $start < 30) { usleep(50000); }
    if ($st['running']) { proc_terminate($p); }
    proc_close($p);
    $out = (string)file_get_contents($logs[0]); $err = substr((string)file_get_contents($logs[1]), -1500);
    $oneLine = fn(string $s): string => str_replace(["\r\n", "\n"], ' / ', trim($s)); // a failure's message is one line (run.php --each reads them so)
    array_map('unlink', $logs);
    $line = array_values(array_filter(explode("\n", $out), fn(string $l) => str_starts_with($l, '{')))[0] ?? '';
    $r = json_decode($line, true);
    if (!is_array($r)) { check(false, "the app's report: {$oneLine($out)} | its log: {$oneLine($err)}"); return; }
    echo "  $line\n";
    check(!$st['running'], 'the app ends as it quits');
    check($r['scale'] >= 1, 'its scale, of its display\'s DPI');
    same([400, 300], $r['content'], 'its content of the size asked, in points');
    check(str_starts_with($r['draw'], 'Direct3D 11'), 'its content drawn by ' . $r['draw']);
    [$pw, $ph, $square, $rest] = $r['frame'];
    same([(int)round(400 * $r['scale']), (int)round(300 * $r['scale'])], [$pw, $ph], 'its last frame read back, of its pixels');
    same([0, 0, 255, 255], $square, 'the red square in its corner, BGRA');
    same([true, [$pw, $ph]], $r['capture'], 'its capture a PNG of its last frame');
    same([true, false], $r['icon'], "the app's icon on it, then the executable's");
    same(1, $r['bounce'], 'its taskbar button flashed');
    same([true, false, false, true], $r['hide'], 'the app hidden with its window, then shown again');
    check($rest !== [] && $rest[3] === 255 && $rest !== $square, 'the background beside it: ' . json_encode($rest));
    check($r['frameAround'], 'its frame around the content');
    same([true, true], $r['visible'], 'shown');
    same('Renamed', $r['title'], 'its title');
    same([50, 60, 500, 400], $r['bounds'], 'its frame set and read back, in points');
    same([320, 200], $r['contentSet'], 'its content sized');
    check($r['minimized'], 'minimized');
    check($r['maximized'], 'maximized');
    same([true, true], $r['fullScreen'], 'full screen');
    check(!$r['afterFullScreen'], 'and back');
    check($r['closed'], 'closed');
    foreach (['resize', 'move', 'minimize', 'restore', 'maximize', 'enterFullScreen', 'leaveFullScreen'] as $e) {
        check(in_array($e, $r['events'], true), "the core heard $e: " . implode(' ', $r['events']));
    }
})();
