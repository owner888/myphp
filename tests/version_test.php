<?php
// scripts/version.php, the version MyPHP is released with (v1.29), run on a
// copy of the files it changes: set everywhere, the plugins' peer range
// with it, and checked as the release workflow checks a tag.
declare(strict_types=1);

namespace MyPHP\Tests;

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/myphp-version-' . getmypid();
$paths = ['scripts/version.php', 'bin/myphp', 'packages/runtime/package.json', 'packages/cli/package.json', 'src/Plugins/Fetch/client/package.json', 'src/Plugins/Sqlite/client/package.json', 'src/Plugins/Watch/client/package.json', 'src/Plugins/WebSocket/client/package.json'];
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
$fetch = json_decode((string)file_get_contents("$tmp/src/Plugins/Fetch/client/package.json"), true);
same(['1.29.0', '^1.29.0'], [$fetch['version'], $fetch['peerDependencies']['myphp-runtime']], 'a plugin, and its peer range of the runtime');
same(0, $run('--check v1.29.0')[0], 'the tag checks');
[$code, $out] = $run('--check v1.29.1');
check($code === 1 && str_contains($out, 'packages/cli/package.json: 1.29.0') && str_contains($out, 'myphp-runtime ^1.29.0'), "another tag does not: $out");
same(2, $run('1.29')[0], 'no version');

exec('rm -rf ' . escapeshellarg($tmp));
