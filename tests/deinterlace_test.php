<?php
// IINA's Deinterlace (mpv's deinterlace option): the video decoded here
// and its fields blended down its lines (Native\Deinterlacer) before the
// layer shows it, in the order the frames show; the player's, the menu's
// and the key bindings' switch of it, kept for the next film.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Media\{Matroska, Player, PlayerSettings, Track};
use MyPHP\MenuItem;
use MyPHP\Native\{CoreMedia, Deinterlacer, ObjC};
use MyPHP\UI\{MediaPlayerMenus, MediaPlayerState};

$settings = sys_get_temp_dir() . '/myphp-deinterlace-' . getmypid() . '.json';
@unlink($settings);
$s = new MediaPlayerState(); $s->playerSettings = new PlayerSettings($settings);
$find = function (array $items, string $id) use (&$find): ?MenuItem {
    foreach ($items as $it) {
        if ($it->id === $id) { return $it; }
        if (($f = $find($it->submenu, $id)) !== null) { return $f; }
    }
    return null;
};
same(['Deinterlace', false], [($m = $find(MediaPlayerMenus::menus($s), 'iina.deinterlace'))?->label, $m?->checked], 'Video > Deinterlace, off at first');
$s->setDeinterlace(true);
same([true, 'Deinterlace: On'], [$find(MediaPlayerMenus::menus($s), 'iina.deinterlace')?->checked, $s->message[0]], 'on: checked, told as IINA tells it');

if (PHP_OS_FAMILY === 'Darwin') {
    // A picture of lines white and black, one by one (testdata/clip-stripes.mkv): each frame given gray, the lines blended, in the order they show.
    $cv = \FFI::cdef('
        void* CMSampleBufferGetImageBuffer(void*);
        int CVPixelBufferLockBaseAddress(void*, unsigned long);
        int CVPixelBufferUnlockBaseAddress(void*, unsigned long);
        void* CVPixelBufferGetBaseAddressOfPlane(void*, size_t);
        size_t CVPixelBufferGetBytesPerRowOfPlane(void*, size_t);
        typedef struct { long long value; int timescale; unsigned int flags; long long epoch; } CMTime;
        CMTime CMSampleBufferGetPresentationTimeStamp(void*);
    ', '/System/Library/Frameworks/VideoToolbox.framework/VideoToolbox');
    $given = [];
    ObjC::boot(['Foundation']);
    ObjC::defineClass('MyPHPTestDeinterlaceLayer', 'NSObject', [
        'enqueueSampleBuffer:' => ['v@:@', 'v(ppp)', function ($self, $cmd, $sb) use ($cv, &$given): void {
            $pb = $cv->CMSampleBufferGetImageBuffer($sb);
            $cv->CVPixelBufferLockBaseAddress($pb, 1);
            $row = $cv->CVPixelBufferGetBytesPerRowOfPlane($pb, 0);
            $luma = \FFI::string($cv->CVPixelBufferGetBaseAddressOfPlane($pb, 0), 8 * $row);
            $cv->CVPixelBufferUnlockBaseAddress($pb, 1);
            $t = $cv->CMSampleBufferGetPresentationTimeStamp($sb);
            $given[] = [$t->value / $t->timescale, array_map(fn(int $y) => ord($luma[$y * $row + 8]), range(2, 6))];
        }],
    ]);
    $layer = ObjC::msg('p:', ObjC::msg('p:', ObjC::cls('MyPHPTestDeinterlaceLayer'), 'alloc'), 'init');
    $m = new Matroska(__DIR__ . '/testdata/clip-stripes.mkv');
    $d = new Deinterlacer();
    $format = null;
    while (($p = $m->next()) !== null) {
        if ($p->track->type !== Track::VIDEO) { continue; }
        $format ??= CoreMedia::format($p->track, $p->data);
        $sb = CoreMedia::videoSample($p, $format, true);
        $d->send($sb, $p->time, $p->duration, true, $layer);
        CoreMedia::c()->CFRelease($sb);
    }
    same(1, count($given), 'held till more come: the first given');
    $d->drain($layer);
    same(5, count($given), 'all of them at the end');
    same([0.0, 0.04, 0.08, 0.12, 0.16], array_map(fn($g) => round($g[0], 3), $given), 'in the order they show');
    $gray = array_merge(...array_map(fn($g) => $g[1], $given));
    check(max($gray) - min($gray) <= 12 && abs(array_sum($gray) / count($gray) - 125) < 10, 'the lines blended: gray, not white and black: ' . json_encode($given[0][1]));

    // The player's: on for this film and the next; the picture given again.
    Player::$autoLoad = [0, '', '', false];
    $s->setPlayer($p1 = new Player(__DIR__ . '/testdata/clip.mkv'));
    check($p1->deinterlaced(), 'the player deinterlaces');
    $p1->seek(0.5);
    check(abs($p1->time() - 0.5) < 0.05, 'a seek, its frames decoded here');
    $s->setPlayer($p2 = new Player(__DIR__ . '/testdata/clip.mp4'));
    check($p2->deinterlaced(), 'kept for the next film, as mpv\'s option');
    $s->setDeinterlace(false);
    check(!$p2->deinterlaced(), 'off');
}
@unlink($settings);
