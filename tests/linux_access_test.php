<?php
// Orca's view of a window of native UI on Linux (Platform\Linux\Access,
// mygo's surface_access.go): the area's accessible has the content's
// nodes below it, with ATK's roles, names and states, a field's text, and
// a button pressed through ATK's action runs it, the tree after it telling
// the new count. Run in an app of its own, on the display or an Xvfb.
declare(strict_types=1);

namespace MyPHP\Tests;

(function (): void {
    if (PHP_OS_FAMILY !== 'Linux') {
        echo "  skipped: Linux's\n";
        return;
    }
    try { \FFI::cdef('', 'libgtk-3.so.0'); } catch (\FFI\Exception) { echo "  skipped: no GTK 3\n"; return; }
    $cmd = [PHP_BINARY, '-d', 'ffi.enable=1', __DIR__ . '/testdata/linux_access_app.php'];
    if ((getenv('DISPLAY') ?: getenv('WAYLAND_DISPLAY') ?: '') === '') {
        if (\MyPHP\Native\Build\Cmd::look('xvfb-run') === '') { echo "  skipped: no display, and no xvfb-run\n"; return; }
        $cmd = ['xvfb-run', '-a', ...$cmd];
    }
    $p = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    proc_close($p);
    $line = array_values(array_filter(explode("\n", (string)$out), fn(string $l) => str_starts_with($l, '{')))[0] ?? '';
    $r = json_decode($line, true);
    if (!is_array($r)) { check(false, "the app's report: $out $err"); return; }
    $short = fn(array $list) => array_map(fn(array $e) => "{$e['role']} {$e['name']}", $list);
    same(['label Hello', 'push button Add', 'check box Wi-Fi', 'entry Name', 'label Count 0'], $short($r['before']), 'the nodes, with their roles and names');
    same([0, 1, 2, 3, 4], array_column($r['before'], 'index'), 'each its place among its siblings');
    $by = array_column($r['before'], null, 'name');
    same(['click', true, true, 'Ada'], [$by['Add']['action'] ?? null, $by['Wi-Fi']['checked'], $by['Name']['editable'], $by['Name']['text'] ?? null], 'a button clicks, a switch on, a field editable with its text');
    same([1, 'label Count 1'], [$r['n'], $short($r['after'])[4] ?? null], 'pressed through ATK, it runs, and the tree tells the new count');
})();
