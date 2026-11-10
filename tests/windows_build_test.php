<?php
// The myphp command on Windows: `myphp devices` lists the Windows desktop; `myphp build -platform
// windows/<arch>` makes <out>/windows-<arch> (the launcher, php/, app/, info.json, the resources
// and a zip), whose launcher runs the app on its PHP with the extensions the building one had, in
// app/, its directory named in MYPHP_BUNDLE, and gives back the app's exit code.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Native\Build\Windows;

(function (): void {
    $png = "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . pack('NN', 48, 48) . "\x08\x06\x00\x00\x00"; // a PNG's header is all Windows::ico reads
    $ico = Windows::ico($png);
    same([0, 1, 1, 48, 48, strlen($png), 22, $png], [...array_values(unpack('v3', $ico)), ord($ico[6]), ord($ico[7]), unpack('V', substr($ico, 14, 4))[1], unpack('V', substr($ico, 18, 4))[1], substr($ico, 22)], 'an .ico of one PNG, its size, its bytes after the directory');
    if (PHP_OS_FAMILY !== 'Windows') {
        echo "  skipped the rest: Windows's\n";
        return;
    }
    $myphp = function (string ...$args): array {
        $p = proc_open([PHP_BINARY, '-d', 'ffi.enable=1', dirname(__DIR__) . '/bin/myphp', ...$args], [0 => ['file', DEVNULL, 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        return [proc_close($p), $out];
    };
    [$code, $out] = $myphp('devices');
    check($code === 0 && str_contains($out, 'Windows Desktop') && str_contains($out, 'windows'), 'myphp devices lists the Windows desktop: ' . $out);
    try {
        Windows::compiler();
    } catch (\RuntimeException $e) {
        echo "  skipped the build: {$e->getMessage()}\n";
        return;
    }
    $root = sys_get_temp_dir() . '\\myphp-winbuild-' . bin2hex(random_bytes(4));
    [$code, $out] = $myphp('init', '-name', 'Win Demo', "$root\\w");
    same(0, $code, 'myphp init: ' . $out);
    @mkdir("$root\\w\\resources\\windows", 0755, true);
    file_put_contents("$root\\w\\resources\\windows\\notes.txt", 'notes');
    // The app: what the launcher ran it with, written next to it, and an exit code of its own.
    $entry = json_decode((string)file_get_contents("$root\\w\\myphp.json"), true)['entry'] ?? 'app.php';
    file_put_contents("$root\\w\\$entry", "<?php\nfile_put_contents(getenv('MYPHP_BUNDLE') . '/ran.json', json_encode([basename(getcwd()), extension_loaded('ffi'), ini_get('ffi.enable'), extension_loaded('mbstring'), \$argv[1] ?? null]));\nexit(7);\n");
    $arch = in_array(strtolower((string)getenv('PROCESSOR_ARCHITECTURE')), ['arm64', 'aarch64'], true) ? 'arm64' : 'amd64';
    [$code, $out] = $myphp('build', '-platform', "windows/$arch", "$root\\w");
    same(0, $code, 'myphp build windows: ' . $out);
    $dir = "$root\\w\\build\\windows-$arch";
    $files = array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
    sort($files);
    same(['Win Demo.exe', 'app', 'icon.png', 'info.json', 'notes.txt', 'php', 'win-demo-0.1.0-windows-' . $arch . '.zip', 'win-demo.png'], $files, 'the launcher, the PHP, the app, info.json, the resources, the icon and the zip');
    check(is_file("$dir\\php\\php.exe") && is_file("$dir\\app\\$entry") && is_file("$dir\\app\\myphp\\src\\App.php"), 'php.exe, the app and the framework');
    same(['Win Demo', 'Win Demo.exe'], [json_decode((string)file_get_contents("$dir\\info.json"), true)['CFBundleName'] ?? null, json_decode((string)file_get_contents("$dir\\info.json"), true)['CFBundleExecutable'] ?? null], 'info.json');
    $p = proc_open(["$dir\\Win Demo.exe", 'an argument'], [0 => ['file', DEVNULL, 'r'], 1 => ['file', DEVNULL, 'w'], 2 => ['file', DEVNULL, 'w']], $pipes);
    $start = microtime(true);
    while (($st = proc_get_status($p))['running'] && microtime(true) - $start < 60) { usleep(50000); }
    $code = $st['running'] ? -1 : $st['exitcode'];
    if ($st['running']) { proc_terminate($p); }
    proc_close($p);
    same(7, $code, "the launcher gives back the app's exit code");
    same(['app', true, '1', true, 'an argument'], json_decode((string)@file_get_contents("$dir\\ran.json"), true), 'the app run in app/, ffi on, its extensions loaded, its arguments passed on');
    [$code, $out] = $myphp('build', '-platform', 'windows/' . ($arch === 'amd64' ? 'arm64' : 'amd64'), "$root\\w");
    check($code !== 0 && str_contains($out, 'is built on Windows of that architecture'), 'another architecture refused: ' . $out);
})();
