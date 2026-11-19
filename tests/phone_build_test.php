<?php
// Where the phone runtimes and the Android debug keystore are kept (scripts/phone-build-dir.sh): build/ of MyPHP's own
// folder, which a git checkout and an archive keep through `myphp upgrade`; ~/.myphp/phone for a Composer package,
// whose folder every update replaces whole. And what the iOS archive's list holds: the headers the launcher includes.
declare(strict_types=1);

namespace MyPHP\Tests;

$root = dirname(__DIR__);
$where = function (string $rootDir, array $env = []) use ($root): string {
    $cmd = 'ROOT_DIR=' . escapeshellarg($rootDir) . '; source ' . escapeshellarg("$root/scripts/phone-build-dir.sh") . '; printf %s "$PHONE_BUILD"';
    $prefix = '';
    foreach ($env + ['HOME' => '/home/someone', 'MYPHP_PHONE_BUILD' => ''] as $k => $v) {
        $prefix .= "$k=" . escapeshellarg($v) . ' ';
    }
    return (string)shell_exec("env $prefix bash -c " . escapeshellarg($cmd));
};

$tmp = sys_get_temp_dir() . '/myphp-phone-build-' . bin2hex(random_bytes(4));
$dirs = ["$tmp/checkout", "$tmp/global/vendor/owner888/myphp", "$tmp/project/vendor/owner888/myphp", "$tmp/project/vendor/owner888/other"];
foreach ($dirs as $d) { mkdir($d, 0755, true); }
$real = fn(string $d): string => (string)realpath($d);

same($real($dirs[0]) . '/build', $where($dirs[0]), 'a checkout keeps build/ in its own folder');
same('/home/someone/.myphp/phone', $where($dirs[1]), 'a Composer package keeps it outside the package');
same('/home/someone/.myphp/phone', $where($dirs[2]), 'a project\'s dependency too (shared by its projects)');
same($real($dirs[3]) . '/build', $where($dirs[3]), 'only MyPHP\'s package is moved');
same('/opt/pb', $where($dirs[0], ['MYPHP_PHONE_BUILD' => '/opt/pb']), 'MYPHP_PHONE_BUILD names it');
foreach (array_reverse($dirs) as $d) { rmdir($d); }
foreach (["$tmp/global/vendor/owner888", "$tmp/global/vendor", "$tmp/global", "$tmp/project/vendor/owner888", "$tmp/project/vendor", "$tmp/project", $tmp] as $d) { @rmdir($d); }

// Every script that keeps something there takes the place from this one file, not from build/ beside the script.
foreach (['build-android-php', 'build-android-launcher', 'build-ios-php', 'package-android-apk', 'package-ios-app'] as $name) {
    $script = (string)file_get_contents("$root/scripts/$name.sh");
    check(str_contains($script, 'phone-build-dir.sh'), "$name.sh sources phone-build-dir.sh");
    check(!str_contains($script, '${ROOT_DIR}/build'), "$name.sh does not name build/ of its own folder");
}

// The iOS archive carries the PHP source's headers the launcher includes (main/php.h, sapi/embed/php_embed.h need these
// directories); scripts/check-ios-runtime-archive.sh compiles the launcher from an archive alone, in CI.
$package = (string)file_get_contents("$root/scripts/package-phone-runtimes.sh");
$ios = substr($package, (int)strpos($package, 'for TARGET in ios ios-simulator'));
foreach (['main/streams', 'Zend/Optimizer', 'sapi/embed', 'ext/standard'] as $dir) {
    check(str_contains($ios, "\${ANDROID_PHP}/$dir\"/*.h"), "the iOS archive holds $dir/*.h");
}
