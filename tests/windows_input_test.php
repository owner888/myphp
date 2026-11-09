<?php
// The mouse and the wheel on Windows (Platform\Windows\Window), in an app of its own
// (testdata/windows_input_app.php): Win32's messages into the core window's Input as Linux's
// window gives them, in points: moves, a drag, presses and their clicks, the right button, the
// wheel both ways by the system's lines, a touchpad's scroll and pinch, the side buttons as
// keys, the pointer leaving; a click tapping a view.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\Key;

(function (): void {
    if (PHP_OS_FAMILY !== 'Windows') {
        echo "  skipped: Windows's\n";
        return;
    }
    $logs = [(string)tempnam(sys_get_temp_dir(), 'myphp-out'), (string)tempnam(sys_get_temp_dir(), 'myphp-err')];
    $p = proc_open([PHP_BINARY, '-d', 'ffi.enable=1', __DIR__ . '/testdata/windows_input_app.php'], [0 => ['file', DEVNULL, 'r'], 1 => ['file', $logs[0], 'w'], 2 => ['file', $logs[1], 'w']], $pipes);
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
    same(1, $r['tapped'], 'a click on the view tapped it (the Input after the press ' . json_encode($r['afterDown']) . ', the release ' . json_encode($r['afterUp']) . ', the window ' . json_encode($r['window']) . ')');
    same([true, 60, 70], $r['inside'], 'the pointer inside, where it is in points');
    same(false, $r['left'], 'and gone as it leaves');
    $notch = 33 * $r['lines'];
    // Numbers as floats to three places, whole or not, on both sides: JSON keeps no difference.
    $num = function (array $a) use (&$num): array {
        return array_map(fn($v) => is_array($v) ? $num($v) : (is_int($v) || is_float($v) ? round((float)$v, 3) : $v), $a);
    };
    $want = [
        'move' => [['move', 60, 70]],
        'press' => [['down', 60, 70, 0, 1, 0]],
        'drag' => [['drag', 61, 70]],
        'release' => [['up', 61, 70, 0, 1, 0]],
        'press again' => [['down', 61, 70, 0, 2, 0]],
        'release again' => [['up', 61, 70, 0, 2, 0]],
        'right press' => [['down', 61, 70, 1, 1, 0]],
        'right release' => [['up', 61, 70, 1, 1, 0]],
        'wheel' => [['scroll', 80, 90, 0, $notch, false]],
        'wheel across' => [['scroll', 80, 90, -$notch, 0, false]],
        'touchpad' => [['scroll', 80, 90, 0, round($notch / 3, 3), true]],
        'pinch' => [['magnify', 80, 90, round(exp(0.5 * M_LN2 / 3) - 1, 4)]],
        'back' => [['key', Key::BACK, 0]],
        'forward' => [['key', Key::FORWARD, 0]],
        'leave' => [],
    ];
    // One check a message, each failure short enough to read in full.
    foreach ($r['steps'] as [$name, $heard]) {
        same($num($want[$name] ?? []), $num($heard), "$name heard");
    }
    same(array_keys($want), array_column($r['steps'], 0), 'each message sent');
})();
