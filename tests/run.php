<?php
// Runs every test in this folder (the *_test.php files) in one process and
// reports; exits 1 when one fails. php tests/run.php [name...]
declare(strict_types=1);

require __DIR__ . '/Headless.php';

$checks = 0; $failures = [];
// Deprecations, warnings and notices fail the run, as PHP's defaults hide deprecations (the static FFI::new() did, docs/architecture.md); @ still silences what it marks.
error_reporting(E_ALL);
set_error_handler(function (int $no, string $msg, string $file, int $line) use (&$failures): bool {
    if ((error_reporting() & $no) === 0) { return false; }
    $failures[] = $what = "PHP: $msg at " . basename($file) . ":$line";
    echo "  FAIL: $what\n";
    return true;
});
$only = array_slice($argv, 1);
$files = glob(__DIR__ . '/*_test.php');
foreach ($files as $file) {
    $name = basename($file, '_test.php');
    if ($only !== [] && !in_array($name, $only, true)) { continue; }
    $before = $checks; $failed = count($failures);
    echo "$name\n";
    require $file;
    printf("  %d checks, %d failed\n", $checks - $before, count($failures) - $failed);
}
printf("%d checks, %d failed\n", $checks, count($failures));
exit($failures === [] ? 0 : 1);
