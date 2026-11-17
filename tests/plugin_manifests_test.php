<?php
// The native libraries the plugins download (myphp-plugin.json): each is on MyPHP's own releases, so that a build
// depends on no one else's repository and what it prints carries no other project's name, and each names the
// SHA-256 the download is held to.
declare(strict_types=1);

namespace MyPHP\Tests;

$src = realpath(__DIR__ . '/../src');
$it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS));
$seen = 0;
foreach ($it as $f) {
    if ($f->getFilename() !== 'myphp-plugin.json') { continue; }
    $rel = strtr(substr($f->getPathname(), strlen($src) + 1), '\\', '/');
    $manifest = json_decode((string)file_get_contents($f->getPathname()), true);
    check(is_array($manifest), "$rel is JSON");
    foreach ($manifest['libraries'] ?? [] as $lib) {
        foreach ($lib['files'] ?? [] as $platform => $file) {
            $seen++;
            check(str_starts_with($file['url'] ?? '', 'https://github.com/owner888/myphp/releases/download/'), "$rel: the library of $platform is on MyPHP's releases: " . ($file['url'] ?? ''));
            check(preg_match('/^[0-9a-f]{64}$/', $file['sha256'] ?? '') === 1, "$rel: and names its SHA-256");
        }
    }
}
check($seen > 0, 'there are libraries to check');
