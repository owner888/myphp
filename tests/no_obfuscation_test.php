<?php
// myphp build never obfuscates (AGENTS.md): PHP plugins call the framework's and the app's classes by name at runtime.
// What a release build does to the PHP keeps every name (AppImage::compactPhp drops comments and spaces alone), and
// what it leaves out goes by platform (other platforms' backends, build tools), never by a guess of what is unused.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Native\Build\AppImage;

$src = dirname(__DIR__) . '/src';
$code = fn(string $php): array => array_values(array_map(fn(\PhpToken $t) => $t->text,
    array_filter(\PhpToken::tokenize($php), fn(\PhpToken $t) => !$t->is([T_COMMENT, T_DOC_COMMENT, T_WHITESPACE]))));
$changed = [];
foreach ([...glob("$src/UI/Declarative/*.php"), "$src/App.php", "$src/URLSession.php"] as $f) {
    $php = (string)file_get_contents($f);
    if ($code(AppImage::compactPhp($php)) !== $code($php)) { $changed[] = basename($f); }
}
same([], $changed, 'compactPhp keeps every token but comments and spaces: no name renamed');

$dropped = [];
foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS)) as $f) {
    $rel = strtr(substr($f->getPathname(), strlen($src) + 1), ['\\' => '/']);
    if (!str_ends_with($rel, '.php') || str_starts_with($rel, 'Platform/') || str_starts_with($rel, 'Native/Build/')) { continue; }
    foreach (['darwin', 'linux', 'windows', 'android', 'ios'] as $os) {
        if (!AppImage::keepFrameworkFile($rel, $os)) { $dropped[] = "$os: $rel"; }
    }
}
same([], $dropped, 'every framework class outside the platforms\' backends and the build tools is in every build');
