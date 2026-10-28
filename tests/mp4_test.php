<?php
// MP4 and QuickTime read in PHP (Media\Mp4), on any system, as FFmpeg's
// mov demuxer reads them: the tracks and their codecs' configurations as
// Matroska's, the samples in the order stored at their presentation times
// (the edit list's shift, B-frames' composition offsets), sync samples,
// chapters (a chapter track's), a seek to the keyframe before; the movie box
// before or after the samples; and over HTTP, sample for sample as from
// the file.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Media\{HttpBytes, Mp4, Track};
use MyPHP\Native\CurlLoop;

$m = new Mp4(__DIR__ . '/testdata/clip.mp4'); // its movie box first
same([['V_MPEG4/ISO/AVC', 160, 90, 'und'], ['A_AAC', 44100.0, 2, 'chi']], [
    [$m->tracks()[0]->codec, $m->tracks()[0]->width, $m->tracks()[0]->height, $m->tracks()[0]->language],
    [$m->tracks()[1]->codec, $m->tracks()[1]->sampleRate, $m->tracks()[1]->channels, $m->tracks()[1]->language],
], 'its tracks, named as Matroska names them');
check(str_starts_with($m->tracks()[0]->private, "\x01") && strlen($m->tracks()[1]->private) === 5, 'their configurations: an avcC record, an AudioSpecificConfig');
check(abs($m->tracks()[0]->frameDuration - 1 / 24) < 1e-4 && abs($m->duration() - 2.043) < 0.01, 'a frame 1/24 s (the deltas\' mean), its duration');
$all = [];
while (($p = $m->next()) !== null) { $all[] = $p; }
$video = array_values(array_filter($all, fn($p) => $p->track->type === Track::VIDEO));
same([136, 48, 0.023, true, 1.023], [count($all), count($video), round($video[0]->time, 3), $video[0]->keyframe, round(array_values(array_filter($video, fn($p) => $p->keyframe))[1]->time, 3)],
    'its samples, as ffprobe lists them: the first frame at 0.023 s (the edit list\'s), keyframes at it and 1.023');
$shown = array_map(fn($p) => $p->time, $video);
$sorted = $shown; sort($sorted);
check($shown !== $sorted && abs($sorted[1] - $sorted[0] - 1 / 24) < 0.002, 'frames stored out of the order shown (B-frames), shown 1/24 s apart');
$m->seek(1.4);
$first = $m->next();
while ($first !== null && $first->track->type !== Track::VIDEO) { $first = $m->next(); }
same([1.023, true], [round($first?->time ?? -1, 3), $first?->keyframe], 'a seek: from the keyframe before');
same([1.023], [round($m->keyframe(1.7)?->time ?? -1, 3)], 'the keyframe for a time alone (a thumbnail\'s)');

$c = new Mp4(__DIR__ . '/testdata/clip-chapters.mp4'); // its movie box last
same([['Opening', 0.0], ['', 1.0], ['第三章', 1.5]], array_map(fn($ch) => [$ch->title, round($ch->start, 3)], $c->chapters()), 'its chapter track\'s chapters, the track itself none of the film\'s');
same(['V_MPEG4/ISO/AVC', 'A_AAC'], array_map(fn(Track $t) => $t->codec, $c->tracks()), 'the movie box after the samples');
$s = new Mp4(__DIR__ . '/testdata/clip-subs.mp4');
same('S_TEXT/TX3G', $s->tracks()[2]->codec ?? null, 'a tx3g track, MP4\'s subtitles');
$f = new Mp4(__DIR__ . '/testdata/clip-flac.mp4');
check(str_starts_with($f->tracks()[1]->private, 'fLaC') && $f->tracks()[1]->codec === 'A_FLAC', 'FLAC: its metadata blocks, as Matroska\'s CodecPrivate');
same(['V_AV1', 16], [(new Mp4(__DIR__ . '/testdata/clip-av1.mp4'))->tracks()[0]->codec, strlen((new Mp4(__DIR__ . '/testdata/clip-av1.mp4'))->tracks()[0]->private)], 'AV1: its av1C');

// Over HTTP: sample for sample as from the file, its movie box read where it is.
try { \MyPHP\Native\Curl::ffi(); } catch (\Throwable $e) { echo "  skipped: over HTTP: {$e->getMessage()}\n"; return; }
[$base, $stop] = rangeServer(__DIR__ . '/testdata');
try {
    $read = function (Mp4 $m): array {
        $out = [];
        while (true) {
            $p = $m->next();
            if ($p === null) { if ($m->ready()) { break; } CurlLoop::waitUntil(fn() => $m->ready()); continue; }
            $out[] = [$p->track->number, round($p->time, 4), md5($p->data)];
        }
        return $out;
    };
    foreach (['clip.mp4', 'clip-chapters.mp4'] as $file) {
        same($read(new Mp4(__DIR__ . "/testdata/$file")), $read(new Mp4(new HttpBytes("$base/$file"))), "$file over HTTP: sample for sample as from its file");
    }
} finally {
    $stop();
}
