<?php
// The myphp command on Windows: `myphp devices` lists the Windows desktop; `myphp build -platform
// windows/<arch>` makes <out>/windows-<arch> (the launcher, php/, app/, info.json, the resources
// and a zip), whose launcher runs the app on its PHP with the extensions the building one had, in
// app/, its directory named in MYPHP_BUNDLE, and gives back the app's exit code; its installer
// installs it and its uninstaller removes it. @timeout 900: the build copies PHP, downloads NSIS
// the first time and compresses the installer.
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
    $mark = function (string $what): void { if (getenv('MYPHP_TEST_CHILD') !== false) { echo "  at: $what\n"; } }; // run.php --each: where a hang came
    $myphp = function (string ...$args): array {
        // Into a file, not pipes: adb's server, which myphp devices starts, inherits the handles and would hold a pipe open.
        $log = (string)tempnam(sys_get_temp_dir(), 'myphp-out');
        $p = proc_open([PHP_BINARY, '-d', 'ffi.enable=1', dirname(__DIR__) . '/bin/myphp', ...$args], [0 => ['file', DEVNULL, 'r'], 1 => ['file', $log, 'w'], 2 => ['redirect', 1]], $pipes);
        $code = proc_close($p);
        $out = (string)file_get_contents($log);
        @unlink($log);
        return [$code, $out];
    };
    $mark('devices');
    [$code, $out] = $myphp('devices');
    check($code === 0 && str_contains($out, 'Windows Desktop') && str_contains($out, 'windows'), 'myphp devices lists the Windows desktop: ' . $out);
    try {
        Windows::compiler();
    } catch (\RuntimeException $e) {
        echo "  skipped the build: {$e->getMessage()}\n";
        return;
    }
    $root = sys_get_temp_dir() . '\\myphp-winbuild-' . bin2hex(random_bytes(4));
    $mark('init');
    [$code, $out] = $myphp('init', '-name', 'Win Demo', "$root\\w");
    same(0, $code, 'myphp init: ' . $out);
    @mkdir("$root\\w\\resources\\windows", 0755, true);
    file_put_contents("$root\\w\\resources\\windows\\notes.txt", 'notes');
    // The app: what the launcher ran it with, written next to it, and an exit code of its own.
    $entry = json_decode((string)file_get_contents("$root\\w\\myphp.json"), true)['entry'] ?? 'app.php';
    file_put_contents("$root\\w\\$entry", "<?php\nfile_put_contents(getenv('MYPHP_BUNDLE') . '/ran.json', json_encode([basename(getcwd()), extension_loaded('ffi'), ini_get('ffi.enable'), extension_loaded('mbstring'), \$argv[1] ?? null]));\nexit(7);\n");
    $arch = in_array(strtolower((string)getenv('PROCESSOR_ARCHITECTURE')), ['arm64', 'aarch64'], true) ? 'arm64' : 'amd64';
    $mark('build');
    $t = microtime(true);
    [$code, $out] = $myphp('build', '-platform', "windows/$arch", "$root\\w");
    same(0, $code, 'myphp build windows: ' . $out);
    $mark(sprintf('built in %.0f s', microtime(true) - $t));
    $build = "$root\\w\\build\\windows-$arch";
    $setup = "win-demo-setup-0.1.0-$arch.exe";
    $listed = array_values(array_diff(scandir($build) ?: [], ['.', '..']));
    sort($listed);
    same(['SHA256SUMS', 'Win Demo', "win-demo-0.1.0-windows-$arch.zip", $setup], $listed, "the app's folder, the zip, the installer and their sums");
    $dir = "$build\\Win Demo";
    $files = array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
    sort($files);
    same(['Win Demo.exe', 'app', 'icon.png', 'info.json', 'notes.txt', 'php', 'win-demo.png'], $files, 'the launcher, the PHP, the app, info.json, the resources and the icon');
    $sums = \MyPHP\Native\Build\Checksums::parse((string)file_get_contents("$build\\SHA256SUMS"));
    same([hash_file('sha256', "$build\\$setup"), hash_file('sha256', "$build\\win-demo-0.1.0-windows-$arch.zip")], [$sums[$setup] ?? null, $sums["win-demo-0.1.0-windows-$arch.zip"] ?? null], 'SHA256SUMS');
    check(is_file("$dir\\php\\php.exe") && is_file("$dir\\app\\$entry") && is_file("$dir\\app\\myphp\\src\\App.php"), 'php.exe, the app and the framework');
    same(['Win Demo', 'Win Demo.exe'], [json_decode((string)file_get_contents("$dir\\info.json"), true)['CFBundleName'] ?? null, json_decode((string)file_get_contents("$dir\\info.json"), true)['CFBundleExecutable'] ?? null], 'info.json');
    $mark('the launcher');
    $p = proc_open(["$dir\\Win Demo.exe", 'an argument'], [0 => ['file', DEVNULL, 'r'], 1 => ['file', DEVNULL, 'w'], 2 => ['file', DEVNULL, 'w']], $pipes);
    $start = microtime(true);
    while (($st = proc_get_status($p))['running'] && microtime(true) - $start < 60) { usleep(50000); }
    $code = $st['running'] ? -1 : $st['exitcode'];
    if ($st['running']) { proc_terminate($p); }
    proc_close($p);
    same(7, $code, "the launcher gives back the app's exit code");
    same(['app', true, '1', true, 'an argument'], json_decode((string)@file_get_contents("$dir\\ran.json"), true), 'the app run in app/, ffi on, its extensions loaded, its arguments passed on');
    // The installer, silent, into a folder of the test's: the app's files, the uninstaller, the entry of Settings > Apps; the uninstaller, silent, removes them.
    $into = "$root\\installed";
    // A program waited for 120 s at most; one that does not end says, in $stuck, what it and its children were running then.
    $stuck = '';
    $run = function (array $cmd) use (&$stuck): int {
        $p = proc_open($cmd, [0 => ['file', DEVNULL, 'r'], 1 => ['file', DEVNULL, 'w'], 2 => ['file', DEVNULL, 'w']], $pipes);
        $start = microtime(true);
        while (($st = proc_get_status($p))['running'] && microtime(true) - $start < 120) { usleep(100000); }
        if ($st['running']) {
            [, $tree] = \MyPHP\Native\Build\Cmd::output(['powershell', '-NoProfile', '-NonInteractive', '-InputFormat', 'None', '-Command',
                "Get-CimInstance Win32_Process | Where-Object { \$_.ProcessId -eq {$st['pid']} -or \$_.ParentProcessId -eq {$st['pid']} } | ForEach-Object { \$_.ProcessId.ToString() + ' ' + \$_.CommandLine }"]);
            $stuck = 'still running after 120 s: ' . str_replace(["\r", "\n"], ' / ', trim($tree));
            proc_terminate($p);
        }
        proc_close($p);
        return $st['running'] ? -1 : $st['exitcode'];
    };
    $mark('the installer');
    same(0, $run(["$build\\$setup", '/S', "/D=$into"]), "the installer, silent $stuck");
    check(is_file("$into\\Win Demo.exe") && is_file("$into\\php\\php.exe") && is_file("$into\\Uninstall.exe"), 'it installs the app and its uninstaller');
    $key = 'HKCU\\Software\\Microsoft\\Windows\\CurrentVersion\\Uninstall\\' . (json_decode((string)file_get_contents("$dir\\info.json"), true)['CFBundleIdentifier'] ?? '');
    [, $reg] = \MyPHP\Native\Build\Cmd::output(['reg', 'query', $key, '/v', 'DisplayName']);
    check(str_contains($reg, 'Win Demo'), 'its entry in Settings > Apps: ' . $reg);
    $mark('the uninstaller');
    $run(["$into\\Uninstall.exe", '/S', "_?=$into"]); // _?= runs it in place, waited for
    check(!is_file("$into\\Win Demo.exe"), 'the uninstaller removes it');
    [$code, $out] = $myphp('build', '-platform', 'windows/' . ($arch === 'amd64' ? 'arm64' : 'amd64'), "$root\\w");
    check($code !== 0 && str_contains($out, 'is built on Windows of that architecture'), 'another architecture refused: ' . $out);
})();
