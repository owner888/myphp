<?php
// An iPhone's sandbox has no fork or exec: proc_open and exec fail there with a warning and false, which an app takes for a
// program that is missing. What starts a program says it cannot (Platform\Unsupported), on iOS, tvOS and visionOS; on the
// other platforms it runs the program as before.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\{App, Process};
use MyPHP\Platform\Unsupported;

$saved = getenv('MYPHP_PLATFORM');
$thrown = function (string $platform, \Closure $f): ?string {
    putenv("MYPHP_PLATFORM=$platform");
    try {
        $f();
    } catch (Unsupported $e) {
        return $e->getMessage();
    }
    return null;
};

foreach (['ios', 'tvos', 'visionos'] as $platform) {
    $m = $thrown($platform, fn() => Process::run(['true'], '', fn() => null));
    check($m !== null && str_contains($m, 'Process::run') && str_contains($m, 'no fork or exec'), "$platform: Process::run says it cannot, and why: " . ($m ?? 'no exception'));
    $m = $thrown($platform, fn() => App::relaunch());
    check($m !== null && str_contains($m, 'App::relaunch'), "$platform: App::relaunch says so before it quits");
    $m = $thrown($platform, fn() => \MyPHP\Media\YtDlp::info('https://example.com/video'));
    check($m !== null && str_contains($m, 'YtDlp'), "$platform: Media\\YtDlp says so");
    $m = $thrown($platform, fn() => Unsupported::requireProcesses('Anything'));
    check($m !== null, "$platform: and so does the check the others use");
}

// elsewhere it is no business of the check
putenv('MYPHP_PLATFORM=macos');
Unsupported::requireProcesses('Anything');
check(true, 'macos: the check lets a program run');
$ran = null;
$p = Process::run([PHP_BINARY, '-r', 'echo "ok";'], '', function (string $out) use (&$ran) { $ran = $out; });
check($p instanceof Process, 'macos: Process::run starts the program');
$p->kill();

// every place that starts a program asks first (a static check: the Pty and the plugins need what a test cannot give them)
$root = dirname(__DIR__);
foreach (['src/Process.php', 'src/Plugins/Terminal/Pty/Pty.php', 'src/PlayerPlugins/Plugins.php', 'src/PlayerPlugins/UtilsApi.php', 'src/Media/YtDlp.php', 'src/App.php'] as $file) {
    check(str_contains((string)file_get_contents("$root/$file"), 'requireProcesses('), "$file asks before it starts a program");
}

$saved === false ? putenv('MYPHP_PLATFORM') : putenv("MYPHP_PLATFORM=$saved");
