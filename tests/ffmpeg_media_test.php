<?php
// The player's system over FFmpeg, on Linux and Windows (Platform\Linux\
// Media, Platform\Windows\Media): what libavformat reads (an MP4, an Ogg),
// its tracks named as Matroska names them; a picture and a film not named
// as one refused, as AVFoundation refuses them; a keyframe decoded into a
// thumbnail of its size; the renderers (Native\AvRenderers), paused at a
// seek's time, decoding its frames from the keyframe, the ones before the
// time dropped, the first after it shown as the image the window draws;
// the thumbnails' JPEGs (GdkPixbuf's on Linux, WIC's on Windows); WASAPI's outputs.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Media\{Matroska, Track};
use MyPHP\Native\{Av, AvReader, AvRenderers, AvThumbnails};

if (PHP_OS_FAMILY !== 'Linux' && PHP_OS_FAMILY !== 'Windows') {
    echo "  skipped: Linux's and Windows's\n";
    return;
}
try {
    Av::ffi();
} catch (\RuntimeException $e) {
    echo "  skipped: {$e->getMessage()}\n";
    return;
}

$r = new AvReader(__DIR__ . '/testdata/clip.mp4');
same([[Track::VIDEO, 'V_MPEG4/ISO/AVC', 160, 90], [Track::AUDIO, 'A_AAC', 0, 0]], array_map(fn(Track $t) => [$t->type, $t->codec, $t->width, $t->height], $r->tracks()),
    'an MP4 read by libavformat: its tracks, their codecs named as Matroska names them');
check(abs($r->duration() - 2.04) < 0.05 && str_starts_with($r->tracks()[0]->private, "\x01"), 'its duration, and its video\'s avcC as CodecPrivate: ' . $r->duration());
$key = $r->keyframe(1.0);
check($key !== null && $key->keyframe && $key->track->type === Track::VIDEO && $key->time <= 1.0, 'the keyframe at or before a time: ' . ($key?->time ?? 'none'));
same(['A_VORBIS', 'audio'], [(new AvReader(__DIR__ . '/testdata/tone.ogg'))->tracks()[0]->codec, (new AvReader(__DIR__ . '/testdata/tone.ogg'))->tracks()[0]->type], 'an Ogg\'s Vorbis');
$refused = function (string $path): string {
    try {
        new AvReader($path);
        return '';
    } catch (\RuntimeException $e) {
        return $e->getMessage();
    }
};
$notes = sys_get_temp_dir() . '/myphp-ffmpeg-media-' . getmypid() . '.txt';
copy(__DIR__ . '/testdata/clip.mkv', $notes);
check(str_contains($refused(__DIR__ . '/testdata/upright.jpg'), 'is a picture') && str_contains($refused($notes), 'is not named as'), 'a picture is no film, nor a film named notes.txt');
unlink($notes);

$video = $r->tracks()[0];
$thumbs = new AvThumbnails($video, 80);
$rgba = $thumbs->frame($key);
same([80, 45, 80 * 45 * 4], [$thumbs->width, $thumbs->height, strlen($rgba)], 'a thumbnail 80 wide, of the track\'s shape');
check(count(array_unique(str_split($rgba, 4))) > 8, 'of the frame\'s colors');
check(strlen($thumbs->frame($r->keyframe(0.0))) === 80 * 45 * 4, 'and another, the decoder flushed after each');
// The system's decoders asked (VA-API, D3D11VA): a frame either way, from software where they cannot (no GPU on CI).
$d = new \MyPHP\Native\AvDecoder($video, 1, true);
$d->send($key);
$d->send(null);
$frame = $d->receive();
check($frame !== null && $frame->width === 160, 'the system\'s decoders asked: a frame, ' . ($d->accelerated ? 'with ' . \MyPHP\Native\AvDecoder::hardwareName() : "in software ({$d->software})"));
if ($frame !== null) { \MyPHP\Native\AvDecoder::release($frame); }
while (($rest = $d->receive()) !== null) { \MyPHP\Native\AvDecoder::release($rest); }

$media = PHP_OS_FAMILY === 'Windows' ? new \MyPHP\Platform\Windows\Media(\MyPHP\App::backend()) : new \MyPHP\Platform\Linux\Media(\MyPHP\App::backend());
$m = new Matroska(__DIR__ . '/testdata/clip.mkv');
$out = new AvRenderers($media);
$out->setRate(0.0, 1.3);
$m->seek(1.3); // from the keyframe at 1 s
$given = 0; $hidden = 0;
while ($out->frame() === null && ($p = $m->next()) !== null) {
    if ($p->track->type !== Track::VIDEO) { continue; }
    $show = $p->time + $p->duration > 1.3;
    $hidden += $show ? 0 : 1;
    $out->enqueue($p, $show);
    $given++;
}
check($out->frame() !== null && $out->frame()->size() === [160, 90], "paused at a seek's time, the first frame after it shows, the image of the picture: $given packets given, $hidden of them hidden");
check($hidden > 0, 'the frames before the time decoded, not shown');
same([1.3, null], [$out->time(), $out->failure()], 'the clock stays where the seek went, nothing failed');
$png = $out->snapshot();
check($png !== null && str_starts_with($png, "\x89PNG") && unpack('N2', $png, 16) === [1 => 160, 2 => 90], 'a snapshot of it, a PNG of its size');

$jpeg = $media->jpeg($rgba, 80, 45, 0.85);
check(str_starts_with($jpeg, "\xFF\xD8"), 'a thumbnail as a JPEG, as the cache keeps it');
$back = $media->jpegPixels($jpeg, 80);
same(80 * 45 * 4, strlen($back), 'and its pixels again');
$near = 0;
for ($i = 0; $i < strlen($rgba); $i += 4) { $near += abs(ord($rgba[$i]) - ord($back[$i])) < 48 ? 1 : 0; }
check($near > 80 * 45 * 0.9, "of the colors it was made of: $near pixels of " . 80 * 45 . ' near in red');
same(40 * 23 * 4, strlen($media->jpegPixels($jpeg, 40)), 'scaled down to 40 on a side');
if (PHP_OS_FAMILY === 'Windows') {
    // WASAPI's outputs, by mpv's names of them (a runner may have none); the default one opened where there is one.
    $outs = $media->audioOutputs();
    check(array_filter(array_keys($outs), fn(string $k) => !str_starts_with($k, 'wasapi/')) === [], 'WASAPI\'s outputs: ' . json_encode($outs));
    try {
        $s = \MyPHP\Native\Wasapi::open(48000, 2, 0.2);
        $room = $s->room();
        $s->write(str_repeat("\0", 480 * 8));
        check($room > 0 && $s->room() === $room - 480 && $s->delay() === 480, "WASAPI's default output: $room frames of room, 480 written held");
        $s->flush();
        same(0, $s->delay(), 'flushed, nothing held');
        $s->close();
    } catch (\RuntimeException $e) {
        check($outs === [] && str_contains($e->getMessage(), 'no output device'), 'no output: ' . $e->getMessage());
    }
}
check($media->playable($video) && !$media->playable(new Track(9, Track::AUDIO, 'A_NONE', '', 'und', '', false, 0, 0, 0, 0, 0)), 'what FFmpeg decodes plays, not what it knows not');

// libavfilter (Native\AvFilter): a second of sound through atempo at 1.5 is two thirds of one, its pitch kept by atempo.
$g = new \MyPHP\Native\AvFilter('abuffer@in=time_base=1/48000:sample_rate=48000:sample_fmt=flt:channel_layout=stereo,atempo=1.5,aformat=sample_fmts=flt:sample_rates=48000:channel_layouts=stereo,abuffersink@out');
$ffi = Av::ffi();
$out = 0;
for ($i = 0; $i < 48; $i++) {
    $fr = $ffi->av_frame_alloc();
    [$fr->nb_samples, $fr->format, $fr->sample_rate, $fr->pts] = [1000, Av::FLT, 48000, $i * 1000];
    $ffi->av_channel_layout_default(\FFI::addr($fr->ch_layout), 2);
    $ffi->av_frame_get_buffer($fr, 0);
    $g->push($fr);
    \MyPHP\Native\AvDecoder::release($fr);
    while (($o = $g->pull()) !== null) { $out += $o->nb_samples; \MyPHP\Native\AvDecoder::release($o); }
}
$g->push(null);
while (($o = $g->pull()) !== null) { $out += $o->nb_samples; \MyPHP\Native\AvDecoder::release($o); }
check(abs($out - 32000) < 600, "atempo 1.5: 48000 frames in, $out out");
check(in_array(Av::$major, [60, 61], true), 'FFmpeg 6 or 7 loaded: libavcodec ' . Av::$major);
