<?php
// AV1 decoded in software by libdav1d (Native\Dav1d), on any system that
// has it: each frame of clip-av1.mkv bit for bit as FFmpeg's libdav1d
// decodes it (testdata/clip-av1.yuvmd5: ffmpeg -c:v libdav1d -f framemd5),
// carrying the time it was sent with; a packet sent while dav1d is full
// kept and sent after a picture is taken, as the player sends them; a
// flush for a seek. A 10-bit film's too (clip-av1-10.mkv), and on macOS
// the pixel buffers SoftVideo makes of them: NV12 of 8 bits, x420 of 10
// (each sample in the high bits of 16), bit for bit.
declare(strict_types=1);

namespace MyPHP\Tests;

use FFI\CData;
use MyPHP\Media\{Matroska, Track};
use MyPHP\Native\{Dav1d, Mem};

if (($why = Dav1d::unavailable()) !== null) {
    echo "  skipped: dav1d: $why\n";
    return;
}
/** The planes of picture $pic, their rows without their padding: what FFmpeg's yuv420p is, or yuv420p10le of 10 bits (two bytes a sample). */
$planes = function (CData $pic): string {
    $w = $pic->p->w; $h = $pic->p->h; $out = ''; $b = $pic->p->bpc > 8 ? 2 : 1;
    foreach ([[0, $w, $h, 0], [1, $w >> 1, $h >> 1, 1], [2, $w >> 1, $h >> 1, 1]] as [$k, $pw, $ph, $s]) {
        $at = Mem::addr($pic->data[$k]);
        for ($y = 0; $y < $ph; $y++) { $out .= Mem::read($at + $y * $pic->stride[$s], $pw * $b); }
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

// 10 bits (clip-av1-10.mkv, BT.709, ffmpeg -pix_fmt yuv420p10le -c:v libaom-av1): each frame bit for bit as FFmpeg's libdav1d decodes it, the sequence header's.
$m = new Matroska(__DIR__ . '/testdata/clip-av1-10.mkv');
$d10 = new Dav1d(2, 0);
$got = []; $times = [];
$take = function (bool $drain) use ($d10, $planes, &$got): void { // its own: the one above holds the first decoder
    do {
        $pic = $d10->picture();
        if ($pic === null) { return; }
        $got[] = md5($planes($pic));
        Dav1d::release($pic);
    } while ($drain);
};
$d = $d10;
while (($p = $m->next()) !== null) {
    if ($p->track->type !== Track::VIDEO) { continue; }
    while ($d->full()) { $take(false); }
    $d->send($p->data, (int)round($p->time * 1e6));
    $take(false);
}
$take(true);
same(array_map('trim', file(__DIR__ . '/testdata/clip-av1-10.yuvmd5')), $got, 'a 10-bit film: every frame bit for bit as FFmpeg\'s libdav1d decodes it');
$m->seek(0);
while (($p = $m->next()) !== null && $p->track->type !== Track::VIDEO) {}
$d->flush();
$d->send($p->data, 0);
$pic = $d->picture() ?? $d->picture();
same([10, 1, 1, 1, 1, 0], [$pic?->p->bpc, $pic?->p->layout, $pic?->seq_hdr->mtrx, $pic?->seq_hdr->pri, $pic?->seq_hdr->trc, $pic?->seq_hdr->color_range], 'its samples of 10 bits, 4:2:0, BT.709 in the video range');
if ($pic !== null) { Dav1d::release($pic); }

if (PHP_OS_FAMILY !== 'Darwin') {
    echo "  skipped: SoftVideo's pixel buffers (macOS)\n";
    return;
}
\MyPHP\Native\ObjC::boot(['Foundation', 'AppKit']);
$cv = \FFI::cdef('
    unsigned int CVPixelBufferGetPixelFormatType(void*);
    int CVPixelBufferLockBaseAddress(void*, unsigned long);
    int CVPixelBufferUnlockBaseAddress(void*, unsigned long);
    void* CVPixelBufferGetBaseAddressOfPlane(void*, size_t);
    size_t CVPixelBufferGetBytesPerRowOfPlane(void*, size_t);
    void CFRelease(void*);
', '/System/Library/Frameworks/CoreVideo.framework/CoreVideo');
foreach (['clip-av1.mkv' => ['420v', 1], 'clip-av1-10.mkv' => ['x420', 2]] as $file => [$fourcc, $b]) {
    $m = new Matroska(__DIR__ . "/testdata/$file");
    $track = null; foreach ($m->tracks() as $t) { if ($t->type === Track::VIDEO) { $track = $t; } }
    while (($p = $m->next()) !== null && $p->track->type !== Track::VIDEO) {}
    $d = new Dav1d(1, 1);
    $d->send($p->data, 0);
    $pic = $d->picture() ?? $d->picture();
    $sv = new \MyPHP\Native\SoftVideo($track);
    $pb = $sv->pixels($pic);
    // What they should be: the luma, then U and V interleaved, a row at a time; of 10 bits, each sample 64 times as large (in the high bits).
    $w = $pic->p->w; $h = $pic->p->h; $cw = ($w + 1) >> 1; $ch = ($h + 1) >> 1;
    $row = function (int $k, int $y, int $n) use ($pic, $b): array {
        $bytes = Mem::read(Mem::addr($pic->data[$k]) + $y * $pic->stride[$k === 0 ? 0 : 1], $n * $b);
        return array_values(unpack($b === 1 ? 'C*' : 'v*', $bytes));
    };
    $want = $got = '';
    $cv->CVPixelBufferLockBaseAddress($pb, 1);
    for ($y = 0; $y < $h; $y++) {
        $want .= $b === 1 ? pack('C*', ...$row(0, $y, $w)) : pack('v*', ...array_map(fn(int $v) => $v << 6, $row(0, $y, $w)));
        $got .= Mem::read(Mem::addr($cv->CVPixelBufferGetBaseAddressOfPlane($pb, 0)) + $y * $cv->CVPixelBufferGetBytesPerRowOfPlane($pb, 0), $w * $b);
    }
    for ($y = 0; $y < $ch; $y++) {
        $uv = array_merge(...array_map(null, $row(1, $y, $cw), $row(2, $y, $cw)));
        $want .= $b === 1 ? pack('C*', ...$uv) : pack('v*', ...array_map(fn(int $v) => $v << 6, $uv));
        $got .= Mem::read(Mem::addr($cv->CVPixelBufferGetBaseAddressOfPlane($pb, 1)) + $y * $cv->CVPixelBufferGetBytesPerRowOfPlane($pb, 1), 2 * $cw * $b);
    }
    $cv->CVPixelBufferUnlockBaseAddress($pb, 1);
    same([$fourcc, true], [pack('N', $cv->CVPixelBufferGetPixelFormatType($pb)), $want === $got], "$file: a pixel buffer of $fourcc, its samples bit for bit");
    $cv->CFRelease($pb);
    Dav1d::release($pic);
}
