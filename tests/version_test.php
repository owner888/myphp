<?php
// scripts/version.php, the version MyPHP is released with, run on a
// copy of bin/myphp and checked as the release workflow checks a tag.
declare(strict_types=1);

namespace MyPHP\Tests;

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/myphp-version-' . getmypid();
$paths = ['scripts/version.php', 'bin/myphp'];
foreach ($paths as $p) {
    @mkdir(dirname("$tmp/$p"), 0777, true);
    copy("$root/$p", "$tmp/$p");
}
$run = function (string $args) use ($tmp): array {
    exec(escapeshellarg(PHP_BINARY) . " $tmp/scripts/version.php $args 2>&1", $out, $code);
    return [$code, implode("\n", $out)];
};

[$code] = $run('1.29.0');
same(0, $code, 'set');
check(str_contains((string)file_get_contents("$tmp/bin/myphp"), "const VERSION = '1.29.0';"), 'the CLI has it');
same(0, $run('--check v1.29.0')[0], 'the tag checks');
[$code, $out] = $run('--check v1.29.1');
check($code === 1 && str_contains($out, 'bin/myphp: 1.29.0'), "another tag does not: $out");
same(2, $run('1.29')[0], 'no version');

exec('rm -rf ' . escapeshellarg($tmp));