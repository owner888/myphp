<?php
// A phone runtime (libphp) is made again when the script that made it changed in content, not when its file is newer: an
// upgrade of MyPHP writes every file anew, which made every runtime stale and compiled PHP again (10-20 minutes) after each
// `myphp upgrade`. The script's SHA-256 is kept beside the runtime (scripts/phone-build-dir.sh).
declare(strict_types=1);

namespace MyPHP\Tests;

$root = dirname(__DIR__);
$dir = sys_get_temp_dir() . '/myphp-stale-' . bin2hex(random_bytes(4));
mkdir("$dir/libs", 0755, true);
$lib = "$dir/libs/libphp.a";
$script = "$dir/build.sh";
$stamp = "$dir/libs/.build-script-sha256";
file_put_contents($script, "# the script\n");

$stale = function () use ($root, $lib, $script): bool {
    $cmd = 'ROOT_DIR=' . escapeshellarg($root) . '; source ' . escapeshellarg("$root/scripts/phone-build-dir.sh") . '; runtime_is_stale ' . escapeshellarg($lib) . ' ' . escapeshellarg($script);
    exec('bash -c ' . escapeshellarg($cmd), $out, $code);
    return $code === 0;
};
$stampOf = function (string $name) use ($root, $lib, $script): void {
    exec('bash -c ' . escapeshellarg('ROOT_DIR=' . escapeshellarg($root) . '; source ' . escapeshellarg("$root/scripts/phone-build-dir.sh") . '; ' . $name . ' ' . escapeshellarg($lib) . ' ' . escapeshellarg($script)));
};

same(false, $stale(), 'no runtime: nothing to make again');
file_put_contents($lib, 'x');
same(false, $stale(), 'a runtime with no stamp is taken as made by this script');
same(hash_file('sha256', $script), trim((string)file_get_contents($stamp)), 'and the script\'s SHA-256 is kept for it');
same(false, $stale(), 'the same script: not stale');
touch($script, time() + 3600);
same(false, $stale(), 'a script file that is only newer: not stale (an upgrade writes every file anew)');
file_put_contents($script, "# the script, changed\n");
same(true, $stale(), 'a script whose content changed: stale');
$stampOf('runtime_stamp');
same(false, $stale(), 'made again, it is stamped with the new script');
file_put_contents($stamp, '');
same(false, $stale(), 'an empty stamp (a script ended while it wrote it) is no verdict: rewritten');
same(hash_file('sha256', $script), trim((string)file_get_contents($stamp)), 'with the script\'s SHA-256');

// the scripts ask this and no longer the files' times
foreach (['build-ios-php.sh', 'build-android-php.sh'] as $name) {
    $text = (string)file_get_contents("$root/scripts/$name");
    check(str_contains($text, 'runtime_is_stale') && str_contains($text, 'runtime_stamp'), "$name asks whether the runtime is stale and stamps it");
    check(!str_contains($text, '-nt "$0"'), "$name does not compare the script's file time with the runtime's");
}

foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
    $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
}
rmdir($dir);
