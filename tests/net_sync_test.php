<?php
// src/Net/Async and src/Net/Http are pig's (github.com/owner888/pig), copied by scripts/sync-net.php with the
// namespaces made MyPHP's, never edited here: where a pig checkout is beside this one, the copy is what pig has.
declare(strict_types=1);

namespace MyPHP\Tests;

$root = dirname(__DIR__);
check(is_file("$root/src/Net/Async/Loop.php") && is_file("$root/src/Net/Http/HttpClient.php") && is_file("$root/src/Net/Http/Http2/Http2Connection.php"), 'the stack is carried in src/Net');
$named = [];
foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$root/src/Net", \FilesystemIterator::SKIP_DOTS)) as $f) {
    if (preg_match('/^namespace (\S+);/m', (string)file_get_contents($f->getPathname()), $m) === 1 && !str_starts_with($m[1], 'MyPHP\\Net')) { $named[] = $f->getFilename() . ': ' . $m[1]; }
}
same([], $named, 'every file of it in MyPHP\'s namespace, which src/autoload.php loads');
$pig = getenv('PIG_HOME') ?: "$root/../pig";
if (!is_dir("$pig/packages/async/src")) {
    echo "  skipped: no pig checkout beside this one to compare src/Net with\n";
    return;
}
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$root/scripts/sync-net.php") . ' --check 2>&1', $lines, $code);
same(0, $code, "src/Net is what pig has (php scripts/sync-net.php copies it again):\n" . implode("\n", $lines));
