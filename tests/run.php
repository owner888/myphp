<?php
// Runs every test in this folder (the *_test.php files) in one process and
// reports; exits 1 when one fails. php tests/run.php [name...]
declare(strict_types=1);

// The temporary folder as the system resolves it, before anything asks PHP for it (PHP keeps the first answer): macOS's is under /var, a link to /private/var, which getcwd() and the system's paths give.
$tmp = getenv('TMPDIR');
if ($tmp !== false && $tmp !== '' && ($real = realpath($tmp)) !== false) {
    putenv("TMPDIR=$real");
}
require __DIR__ . '/Headless.php';
// The suite's user data in a folder of its own, not the user's: the player's settings, playback history and positions (Media\PlayerSettings::default() and the others) start from none.
$userData = sys_get_temp_dir() . '/myphp-tests-' . getmypid();
@mkdir($userData);
\MyPHP\App::setPath(\MyPHP\PathName::UserData, $userData);

$checks = 0; $failures = [];
ini_set('memory_limit', '-1'); // the whole suite in one process, as CI runs it: an icon's 1024² sums alone take more than PHP's default 128M
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
