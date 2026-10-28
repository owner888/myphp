<?php
// AV1 decoded in software by libdav1d (Native\Dav1d), on any system that
// has it: each frame of clip-av1.mkv bit for bit as FFmpeg's libdav1d
// decodes it (testdata/clip-av1.yuvmd5: ffmpeg -c:v libdav1d -f framemd5),
// carrying the time it was sent with; a packet sent while dav1d is full
// kept and sent after a picture is taken, as the player sends them; a
// flush for a seek.
declare(strict_types=1);

namespace MyPHP\Tests;

use FFI\CData;
use MyPHP\Media\{Matroska, Track};
use MyPHP\Native\{Dav1d, Mem};

if (($why = Dav1d::unavailable()) !== null) {
    echo "  skipped: dav1d: $why\n";
    return;
}
/** The planes of picture $pic, their rows without their padding: what FFmpeg's yuv420p is. */
$planes = function (CData $pic): string {
    $w = $pic->p->w; $h = $pic->p->h; $out = '';
    foreach ([[0, $w, $h, 0], [1, $w >> 1, $h >> 1, 1], [2, $w >> 1, $h >> 1, 1]] as [$k, $pw, $ph, $s]) {
        $at = Mem::addr($pic->data[$k]);
        for ($y = 0; $y < $ph; $y++) { $out .= Mem::read($at + $y * $pic->stride[$s], $pw); }
    }
    return $out;
};
$m = new Matroska(__DIR__ . '/testdata/clip-av1.mkv');
$d = new Dav1d(2, 0);
$got = []; $times = [];
$take = function (bool $drain) use ($d, $planes, &$got, &$times): void {
    do {
        $pic = $d->picture();
        if ($pic === null) { return; }
        $got[] = md5($planes($pic));
        $times[] = $pic->m->timestamp;
        Dav1d::release($pic);
    } while ($drain);
};
while (($p = $m->next()) !== null) {
    if ($p->track->type !== Track::VIDEO) { continue; }
    while ($d->full()) { $take(false); } // as Renderers pumps a full decoder
    $d->send($p->data, (int)round($p->time * 1e6));
    $take(false); // a picture a packet sent, as SoftVideo takes them
}
$take(true);
same(array_map('trim', file(__DIR__ . '/testdata/clip-av1.yuvmd5')), $got, 'every frame bit for bit as FFmpeg\'s libdav1d decodes it');
same([42000, 83000, 125000], array_slice($times, 0, 3), 'each with the time it was sent with');

$d->flush(); // a seek: from a keyframe again
$m->seek(0);
while (($p = $m->next()) !== null && $p->track->type !== Track::VIDEO) {}
$d->send($p->data, 7);
$pic = $d->picture() ?? $d->picture();
same([160, 90, 7], [$pic?->p->w, $pic?->p->h, $pic?->m->timestamp], 'after a flush, the keyframe decodes alone');
if ($pic !== null) { Dav1d::release($pic); }
