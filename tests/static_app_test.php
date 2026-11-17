<?php
// What a static app (phpmicro, its code in a phar) lacks (v1.28): PHP_BINARY
// is empty, so the executable is Native\Bundle::executable(), and glob finds
// nothing in a phar, so directories are listed with scandir. The sources
// that run in an app keep to both; the build's tools run from disk.
declare(strict_types=1);

namespace MyPHP\Tests;

$uses = ['PHP_BINARY' => [], 'glob(' => []];
$allowed = ['PHP_BINARY' => ['Native/Bundle.php'], 'glob(' => ['Native/I18nGen.php']];
$src = realpath(__DIR__ . '/../src');
$it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $rel = strtr(substr($f->getPathname(), strlen($src) + 1), '\\', '/'); // Windows's separators too
    if (!str_ends_with($rel, '.php') || str_contains($rel, '/Build/')) { continue; }
    $code = (string)file_get_contents($f->getPathname());
    foreach ($uses as $what => $_) {
        if (preg_match('/\b' . preg_quote($what, '/') . '/', $code) === 1 && !in_array($rel, $allowed[$what], true)) { $uses[$what][] = $rel; }
    }
}
same(['PHP_BINARY' => [], 'glob(' => []], $uses, "what a static app lacks, used only where it is handled");

// The extensions the sources call on are those the static PHP is built with (MICRO_EXTENSIONS of bin/myphp): a view's
// closures are read with PhpToken, and an app whose static PHP had no tokenizer stopped at its first frame.
preg_match("/^const MICRO_EXTENSIONS = '([^']+)';/m", (string)file_get_contents(__DIR__ . '/../bin/myphp'), $m);
$built = explode(',', $m[1] ?? '');
$calls = ['tokenizer' => '/\bPhpToken\b|\btoken_get_all\(/'];
foreach ($calls as $ext => $pattern) {
    $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS));
    $used = false;
    foreach ($it as $f) {
        $used = $used || (str_ends_with($f->getPathname(), '.php') && preg_match($pattern, (string)file_get_contents($f->getPathname())) === 1);
    }
    check(!$used || in_array($ext, $built, true), "$ext, which the sources use, is in the static PHP");
}
