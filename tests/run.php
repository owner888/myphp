<?php
// Runs every test in this folder (the *_test.php files) in one process and
// reports; exits 1 when one fails. php tests/run.php [name...]
declare(strict_types=1);

// The temporary folder as the system resolves it, before anything asks PHP for it (PHP keeps the first answer): macOS's is under /var, a link to /private/var, which getcwd() and the system's paths give.
// php tests/run.php --each: each test in a process of its own, the failures gathered, one that crashes its process
// among them; on GitHub Actions all of them in one annotation, as a step shows ten at most (CI's windows job).
if (($argv[1] ?? '') === '--each') {
    $timeout = 120; // seconds a test may take
    $byTest = []; $checks = 0; $failed = 0;
    foreach (glob(__DIR__ . '/*_test.php') as $file) {
        $name = basename($file, '_test.php');
        echo "$name\n";
        // Its output to files, which a process that hangs leaves readable (Windows selects no pipes): ended after TIMEOUT seconds.
        $logs = [tempnam(sys_get_temp_dir(), 'myphp-out'), tempnam(sys_get_temp_dir(), 'myphp-err')];
        $p = proc_open([PHP_BINARY, '-d', 'ffi.enable=1', __FILE__, $name], [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => ['file', $logs[0], 'w'], 2 => ['file', $logs[1], 'w']], $pipes, null, getenv() + ['MYPHP_TEST_CHILD' => '1']);
        $start = microtime(true);
        while (($st = proc_get_status($p))['running'] && microtime(true) - $start < $timeout) { usleep(50000); }
        $hung = $st['running'];
        if ($hung) { proc_terminate($p); }
        $code = $hung ? -1 : $st['exitcode'];
        proc_close($p);
        $out = (string)file_get_contents($logs[0]); $err = (string)file_get_contents($logs[1]);
        array_map('unlink', $logs);
        if ($hung) {
            $err .= sprintf("timed out after %d s", $timeout);
            if (getenv('GITHUB_ACTIONS') === 'true') { echo "::warning title=Timed out::$name\n"; }
        }
        $lines = explode("\n", trim($out));
        echo implode("\n", array_slice($lines, 1)), "\n", $err;
        $fails = array_values(array_map(fn(string $l) => substr(ltrim($l), 6), array_filter($lines, fn(string $l) => str_starts_with(ltrim($l), 'FAIL: '))));
        $m = [];
        foreach (array_reverse($lines) as $l) { if (preg_match('/^(\d+) checks, (\d+) failed$/', $l, $m) === 1) { break; } }
        $checks += (int)($m[1] ?? 0);
        if ($m === [] || $code !== 0 && $fails === []) {
            $fails[] = ($code === -1 ? 'hung' : "crashed (exit $code)") . ': ' . substr(trim($err !== '' ? $err : $out), -400);
        }
        if ($fails !== []) { $byTest[$name] = $fails; $failed += count($fails); }
    }
    printf("%d checks, %d failed, in %d tests\n", $checks, $failed, count($byTest));
    if ($byTest !== [] && getenv('GITHUB_ACTIONS') === 'true') {
        $lines = [];
        foreach ($byTest as $t => $f) {
            $lines[] = sprintf('%s: %d failed', $t, count($f));
            foreach (array_slice($f, 0, 3) as $one) { $lines[] = '  - ' . mb_strcut(str_replace(["\r", "\n"], ' ', $one), 0, 240); }
        }
        echo '::error title=Failures by test::' . strtr(implode("\n", $lines), ['%' => '%25', "\r" => '', "\n" => '%0A']) . "\n";
    }
    exit($byTest === [] ? 0 : 1);
}

$tmp = getenv('TMPDIR');
if ($tmp !== false && $tmp !== '' && ($real = realpath($tmp)) !== false) {
    putenv("TMPDIR=$real");
}
const MYPHP_TEST_SUITE = true; // the suite reports its own counts, test by test
require __DIR__ . '/Headless.php';
\MyPHP\Logger::console(false); // the app's log kept (the tests read it), not printed over the checks: what the suite fails on purpose logs
// The suite's user data in a folder of its own, not the user's: the player's settings, playback history and positions (Media\PlayerSettings::default() and the others) start from none.
$userData = sys_get_temp_dir() . '/myphp-tests-' . getmypid();
@mkdir($userData);
\MyPHP\App::setPath(\MyPHP\PathName::UserData, $userData);

$checks = 0; $failures = []; $byTest = [];
ini_set('memory_limit', '-1'); // the whole suite in one process, as CI runs it: an icon's 1024² sums alone take more than PHP's default 128M
// Deprecations, warnings and notices fail the run, as PHP's defaults hide deprecations (the static FFI::new() did, docs/architecture.md); @ still silences what it marks.
error_reporting(E_ALL);
set_error_handler(function (int $no, string $msg, string $file, int $line) use (&$failures): bool {
    if ((error_reporting() & $no) === 0) { return false; }
    $failures[] = $what = "PHP: $msg at " . basename($file) . ":$line";
    echo "  FAIL: $what\n";
    \MyPHP\Tests\annotate($what);
    return true;
});
set_exception_handler(function (\Throwable $e): never { // what ends the run, annotated as the failures
    \MyPHP\Tests\annotate('uncaught ' . $e);
    fwrite(STDERR, "PHP Fatal error:  Uncaught $e\n");
    exit(255);
});
$only = array_slice($argv, 1);
$files = glob(__DIR__ . '/*_test.php');
foreach ($files as $file) {
    $name = basename($file, '_test.php');
    if ($only !== [] && !in_array($name, $only, true)) { continue; }
    $before = $checks; $failed = count($failures);
    echo "$name\n";
    try {
        require $file;
    } catch (\Throwable $e) { // the test ends there, the run goes on to the next
        $failures[] = $what = 'uncaught ' . get_class($e) . ': ' . $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine();
        echo "  FAIL: $what\n";
        \MyPHP\Tests\annotate($what);
    }
    printf("  %d checks, %d failed\n", $checks - $before, count($failures) - $failed);
    if (count($failures) > $failed) { $byTest[$name] = array_slice($failures, $failed); }
}
printf("%d checks, %d failed\n", $checks, count($failures));
// On GitHub Actions, every failing test in one notice, as a step shows ten errors at most.
if ($byTest !== [] && getenv('GITHUB_ACTIONS') === 'true' && getenv('MYPHP_TEST_CHILD') === false) {
    $lines = array_map(fn(string $t, array $f) => sprintf('%s: %d failed; first: %s', $t, count($f), mb_strcut(str_replace(["\r", "\n"], ' ', $f[0]), 0, 300)), array_keys($byTest), $byTest);
    echo '::notice title=Failures by test::' . strtr(implode("\n", $lines), ['%' => '%25', "\n" => '%0A']) . "\n";
}
exit($failures === [] ? 0 : 1);
