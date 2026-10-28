<?php
// The seek bar's thumbnails kept on disk (Media\ThumbnailCache), on any
// system (its JPEGs made and read on macOS alone, here bytes of their
// own): by the film, while it is as it was and of the width; a file cut
// short gone; past its size, the least recently used half gone.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Media\{Thumbnail, ThumbnailCache};

$dir = sys_get_temp_dir() . '/myphp-thumbcache-' . getmypid();
$film = sys_get_temp_dir() . '/myphp-thumbcache-film-' . getmypid() . '.mkv';
file_put_contents($film, str_repeat('x', 1000));
$cache = new ThumbnailCache($dir);
$thumbs = [new Thumbnail(0.0, 240, 135, fn() => ''), new Thumbnail(4.5, 240, 135, fn() => '')];
same(null, $cache->get($film, 240), 'none before they are put');
$cache->put($film, 240, $thumbs, ['jpeg one', 'jpeg two']);
$got = $cache->get($film, 240);
same([[0.0, 240, 135], [4.5, 240, 135]], array_map(fn(Thumbnail $t) => [$t->time, $t->width, $t->height], $got ?? []), 'they are got again, their times and sizes');
same(null, $cache->get($film, 120), 'not of another width');
touch($film, time() + 10);
same(null, $cache->get($film, 240), 'nor once the film changed');
$cache->put($film, 240, $thumbs, ['jpeg one', 'jpeg two']);
$file = $dir . '/' . md5($film);
file_put_contents($file, substr((string)file_get_contents($file), 0, -3));
same([null, false], [$cache->get($film, 240), is_file($file)], 'a file cut short: none, and it is gone');
// Past its size: the least recently used go, half of it freed.
$small = new ThumbnailCache($dir, 300);
$films = [];
foreach ([1, 2, 3] as $n) {
    $films[$n] = "$film.$n";
    file_put_contents($films[$n], "film $n");
    $small->put($films[$n], 240, [$thumbs[0]], [str_repeat('j', 80)]);
    touch($dir . '/' . md5($films[$n]), time(), time() - 100 + $n); // used in that order
}
$small->put($films[1], 240, [$thumbs[0]], [str_repeat('j', 80)]); // again, the oldest gone first
same([true, false, true], array_map(fn($f) => is_file($dir . '/' . md5($f)), [$films[1], $films[2], $films[3]]), 'the least recently used gone for room');
foreach ([$film, ...$films] as $f) { @unlink($f); }
foreach (scandir($dir) ?: [] as $f) { if ($f[0] !== '.') { unlink("$dir/$f"); } }
rmdir($dir);
