<?php
// A phone's app does not download a native library and load it (iOS's code signing refuses one that is not the app's
// own, App Store guideline 2.5.2 and Google Play's agreement, 4.9, bar it): a plugin whose library is not in the app says
// so, and nothing is fetched. A desktop program that is not a packaged app still downloads it.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Plugins\NativeLibrary;

$target = (function (): string {
    $m = new \ReflectionMethod(NativeLibrary::class, 'target');
    return $m->invoke(null);
})();
$dir = sys_get_temp_dir() . '/myphp-nativelib-' . bin2hex(random_bytes(4));
mkdir($dir);
$manifest = "$dir/myphp-plugin.json";
file_put_contents($manifest, json_encode(['libraries' => [[
    'name' => 'libexample', 'files' => [$target => ['name' => 'libexample.so', 'url' => 'https://127.0.0.1:9/never-fetched', 'sha256' => str_repeat('a', 64)]],
]]]));
putenv('XDG_CACHE_HOME=' . "$dir/cache"); // where a download would go: empty means nothing was fetched
$home = getenv('HOME');
putenv('HOME=' . $dir);

$saved = getenv('MYPHP_PLATFORM');
foreach (['ios', 'android'] as $platform) {
    putenv("MYPHP_PLATFORM=$platform");
    $error = '';
    try {
        NativeLibrary::find($manifest, 'libexample', 'MYPHP_EXAMPLE_LIBRARY', 'example');
    } catch (\RuntimeException $e) {
        $error = $e->getMessage();
    }
    check(str_contains($error, 'does not download a native library'), "$platform: it is not downloaded, and says why: $error");
    check(str_contains($error, 'MYPHP_EXAMPLE_LIBRARY'), "$platform: and names the variable that points at one");
    check(!is_dir("$dir/cache") && !is_dir("$dir/Library"), "$platform: nothing was fetched");
}

// the variable that names a library still works on a phone
putenv('MYPHP_PLATFORM=ios');
putenv('MYPHP_EXAMPLE_LIBRARY=/some/where/libexample.dylib');
same('/some/where/libexample.dylib', NativeLibrary::find($manifest, 'libexample', 'MYPHP_EXAMPLE_LIBRARY', 'example'), 'a library an app names is used');
putenv('MYPHP_EXAMPLE_LIBRARY');

$saved === false ? putenv('MYPHP_PLATFORM') : putenv("MYPHP_PLATFORM=$saved");
$home === false ? putenv('HOME') : putenv("HOME=$home");
foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
    $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
}
rmdir($dir);
