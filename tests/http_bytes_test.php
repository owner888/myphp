<?php
// A film's bytes over HTTP (Media\HttpBytes), against a local server
// (testdata/range_server.php under php -S): its size and any part of it
// as the file has them, read as they come (a Range request at a time),
// what is not here yet said and asked for without waiting, a server that
// sends no ranges, one that fails; and a Matroska film read over it frame
// for frame as from its file.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Media\{HttpBytes, Matroska};
use MyPHP\Native\CurlLoop;

try {
    \MyPHP\Native\Curl::ffi();
} catch (\Throwable $e) {
    echo "  skipped: http bytes: {$e->getMessage()}\n";
    return;
}

(function () {
    $dir = sys_get_temp_dir() . '/myphp-http-' . getmypid();
    @mkdir($dir);
    $big = random_bytes(3 * HttpBytes::BLOCK + 1234); // three blocks and some
    file_put_contents("$dir/big.bin", $big);
    copy(__DIR__ . '/testdata/clip.mkv', "$dir/clip.mkv");
    copy(__DIR__ . '/testdata/clip-cues-front.mkv', "$dir/front.mkv");
    [$base, $stop] = rangeServer($dir);
    try {
        $b = new HttpBytes("$base/big.bin");
        same(strlen($big), $b->size(), 'its size, from the first range\'s total');
        same([substr($big, 0, 10), substr($big, HttpBytes::BLOCK - 5, 10), substr($big, -20)], [$b->read(0, 10), $b->read(HttpBytes::BLOCK - 5, 10), $b->read(strlen($big) - 20, 100)], 'parts of it as the file has them, across a block, short at the end');
        $all = $b->read(0, strlen($big));
        check($all === $big, 'all of it');
        check($b->cached() === strlen($big), 'the cache holds it: ' . $b->cached());

        // Slowly sent: what is far ahead is not here, and asked for; it comes.
        $slow = new HttpBytes("$base/big.bin?rate=200000");
        $far = 3 * HttpBytes::BLOCK;
        check(!$slow->has($far, 100), 'what is not here yet: has() says so, waiting for nothing');
        $t0 = microtime(true);
        CurlLoop::waitUntil(fn() => $slow->has($far, 100));
        same(substr($big, $far, 100), $slow->read($far, 100), 'asked for, it comes: ' . round(microtime(true) - $t0, 2) . ' s');
        check($slow->speed() > 0, 'how fast it came: ' . round($slow->speed()) . ' B/s');

        // What came of a block, read as it comes: a film's first bytes long before its first block is whole.
        $t0 = microtime(true);
        $front = new HttpBytes("$base/front.mkv?rate=6000"); // 19 kB in some three seconds
        CurlLoop::waitUntil(fn() => $front->has(0, 1000));
        $took = microtime(true) - $t0;
        check($took < 1.5 && $front->read(0, 4) === "\x1A\x45\xDF\xA3", 'the first bytes as they come, before the block is whole: ' . round($took, 2) . ' s');
        $m = new Matroska($front);
        $first = null;
        while ($first === null) { CurlLoop::waitUntil(fn() => $m->ready()); $first = $m->next(); } // a block at a time, as it comes
        $took = microtime(true) - $t0;
        check($took < 2.0, 'its first frame before its cluster is whole, a block at a time: ' . round($took, 2) . ' s of some three');

        // A connection that breaks: the transfer goes on from where it was, as ffmpeg's reconnect does.
        $cut = new HttpBytes("$base/big.bin?cut=300000"); // each response broken after 300 kB
        check($cut->read(0, strlen($big)) === $big, 'connections that break: on from where each left, all of it');

        // A server of no ranges: all of it from the start, read on.
        $whole = new HttpBytes("$base/big.bin?norange");
        same(substr($big, 2 * HttpBytes::BLOCK, 50), $whole->read(2 * HttpBytes::BLOCK, 50), 'a server of no ranges: read from its start');

        $err = null;
        try { new HttpBytes("$base/big.bin?fail"); } catch (\RuntimeException $e) { $err = $e->getMessage(); }
        check($err !== null && str_contains($err, '404'), 'a server that fails: said, ' . $err);

        // Matroska over HTTP: every frame as from its file.
        $frames = function (Matroska $m): array { $out = []; while (($p = $m->next()) !== null) { $out[] = [$p->track->number, round($p->time, 3), md5($p->data)]; } return $out; };
        $local = $frames(new Matroska(__DIR__ . '/testdata/clip.mkv'));
        $http = new Matroska(new HttpBytes("$base/clip.mkv"));
        CurlLoop::waitUntil(fn() => $http->ready());
        same($local, $frames($http), 'a Matroska film over HTTP: frame for frame as from its file');
    } finally {
        $stop();
        foreach (['big.bin', 'clip.mkv', 'front.mkv'] as $f) { @unlink("$dir/$f"); }
        @rmdir($dir);
    }
})();
