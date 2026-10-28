<?php
// Reading Matroska (Media\Matroska): the tracks, the duration and cues of
// files ffmpeg wrote (H.264 with AAC, HEVC with AC-3, VP9 and AV1 with
// AAC: AV1's av1C its CodecPrivate, VP9's vpcC made from its first
// keyframe's header), their frames in
// order with their times and keyframes, seeking by the cues; and, in a
// file built here, the three kinds of lacing, block groups, header
// stripping and a segment of unknown size.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Media\{Matroska, Packet, Track};
use RuntimeException;

$m = new Matroska(__DIR__ . '/testdata/clip.mkv');
same([[1, Track::VIDEO, 'V_MPEG4/ISO/AVC', 160, 90], [2, Track::AUDIO, 'A_AAC', 0, 0]], array_map(fn(Track $t) => [$t->number, $t->type, $t->codec, $t->width, $t->height], $m->tracks()), 'the tracks of an H.264 and AAC file');
[$v, $a] = $m->tracks();
same([44100.0, 2, 'chi', 'AAC'], [$a->sampleRate, $a->channels, $a->language, $a->name], 'the audio track: its rate, channels, language and name');
same("\x01\x4d\x40\x0c", substr($v->private, 0, 4), 'the video track keeps its avcC record');
check(abs($v->frameDuration - 1 / 24) < 1e-6, "a frame lasts 1/24 s: {$v->frameDuration}");
check(abs($m->duration() - 2.023) < 0.001, "the duration: {$m->duration()}");
same([0.0, 1.0], array_map(fn(array $c) => $c[0], $m->cues()), 'a cue a second, at the keyframes');
$counts = []; $keys = []; $times = [];
while (($p = $m->next()) !== null) {
    $counts[$p->track->number] = ($counts[$p->track->number] ?? 0) + 1;
    if ($p->track === $v) { $times[] = $p->time; if ($p->keyframe) { $keys[] = $p->time; } }
}
same([1 => 48, 2 => 88], $counts, 'every frame of both tracks, in the order stored');
same([0.0, 1.0], $keys, 'the keyframes of the video');
sort($times);
check(abs($times[47] - 47 / 24) < 0.002, 'the frames show 1/24 s apart, B-frames stored before the frames they come between');
$m->seek(1.4);
$p = $m->next();
check($p->time <= 1.0 + 1e-9 && $p->time > 0.9, "a seek goes on from the cluster of the cue at or before the time: {$p->time}");
$m->seek(0);
same(0.0, $m->next()->time, 'a seek to the start');
$m = new Matroska(__DIR__ . '/testdata/clip-subs.mkv'); // its first frame, and cue, at 0.023 s
$m->seek(0);
same(0.023, round($m->next()->time, 3), 'a seek before the first cue goes to it');
// A keyframe read alone (a thumbnail's): the same frame a seek and next() give, the cluster's other blocks skipped.
foreach (['clip.mkv' => [[1.5, 1.0], [0.0, 0.0], [0.99, 0.0]], 'clip-subs.mkv' => [[0.0, 0.023], [1.2, 1.023]]] as $file => $cases) {
    $m = new Matroska(__DIR__ . "/testdata/$file");
    foreach ($cases as [$at, $want]) {
        $k = $m->keyframe($at);
        $m->seek($at);
        while (($q = $m->next()) !== null && !($q->track->type === Track::VIDEO && $q->keyframe)) {}
        same([$want, true, Track::VIDEO, true], [round($k?->time ?? -1, 3), $k?->keyframe, $k?->track->type, $k !== null && $q !== null && $k->data === $q->data], "$file: the keyframe for {$at} s, alone, as a seek reads it");
    }
}

$m = new Matroska(__DIR__ . '/testdata/clip-hevc.mkv');
same(['V_MPEGH/ISO/HEVC', 'A_AC3'], array_map(fn(Track $t) => $t->codec, $m->tracks()), 'HEVC and AC-3');
same(48000.0, $m->tracks()[1]->sampleRate, 'at 48 kHz');

$err = null;
try { new Matroska(__DIR__ . '/testdata/upright.jpg'); } catch (RuntimeException $e) { $err = $e->getMessage(); }
check(str_contains((string)$err, 'no Matroska file'), "what is no Matroska file throws: $err");

// A file built element by element: EBML ids keep their length marker, sizes are the shortest vints.
$size = function (int $n): string {
    for ($len = 1; $n >= (1 << (7 * $len)) - 1; $len++);
    $v = $n | (1 << (7 * $len));
    return substr(pack('J', $v), 8 - $len);
};
$id = fn(int $id): string => ltrim(pack('N', $id), "\0");
$el = fn(int $i, string $data): string => $id($i) . $size(strlen($data)) . $data;
$uint = fn(int $n): string => ltrim(pack('J', $n), "\0") ?: "\0";
$block = fn(int $rel, int $flags, string $rest): string => "\x81" . pack('n', $rel & 0xffff) . chr($flags) . $rest; // track 1
$xiph = function (array $frames): string {
    $s = chr(count($frames) - 1);
    foreach (array_slice($frames, 0, -1) as $f) { $n = strlen($f); $s .= str_repeat("\xff", intdiv($n, 255)) . chr($n % 255); }
    return $s . implode('', $frames);
};
$ebml = function (array $frames) use ($size): string { // sizes as differences, signed: the vint less half its range
    $s = chr(count($frames) - 1) . $size(strlen($frames[0]));
    for ($k = 1; $k < count($frames) - 1; $k++) {
        $d = strlen($frames[$k]) - strlen($frames[$k - 1]);
        $len = 1;
        while (abs($d) >= (1 << (7 * $len - 1)) - 1) { $len++; }
        $v = ($d + (1 << (7 * $len - 1)) - 1) | (1 << (7 * $len));
        $s .= substr(pack('J', $v), 8 - $len);
    }
    return $s . implode('', $frames);
};
$big = str_repeat('b', 300);
$cluster = $el(0x1F43B675,
    $el(0xE7, $uint(100))
    . $el(0xA3, $block(0, 0x80, 'one'))
    . $el(0xA3, $block(10, 0x80 | 0x02, $xiph(['x1', $big, 'x3'])))
    . $el(0xA3, $block(40, 0x80 | 0x06, $ebml(['e1234', $big, 'e3'])))
    . $el(0xA3, $block(70, 0x04, chr(1) . 'f1f2'))
    . $el(0xA0, $el(0xA1, $block(-5, 0, 'grouped')) . $el(0x9B, $uint(30)) . $el(0xFB, "\xfe")));
$head = $el(0x1A45DFA3, $el(0x4282, 'matroska'));
$info = $el(0x1549A966, $el(0x2AD7B1, $uint(1000000)) . $el(0x4489, pack('G', 1000.0)));
$tracks = $el(0x1654AE6B, $el(0xAE, $el(0xD7, $uint(1)) . $el(0x83, $uint(2)) . $el(0x86, 'A_TEST') . $el(0x23E383, $uint(10000000))
    . $el(0x6D80, $el(0x6240, $el(0x5034, $el(0x4254, $uint(3)) . $el(0x4255, '>'))))));
$seekHead = fn(int $cuesAt): string => $el(0x114D9B74, $el(0x4DBB, $el(0x53AB, $id(0x1C53BB6B)) . $el(0x53AC, pack('J', $cuesAt)))); // positions from the segment's data
$front = $seekHead(0) . $info . $tracks; // what comes before the cluster, the seek head as long whatever it points to
$cues = $el(0x1C53BB6B, $el(0xBB, $el(0xB3, $uint(100)) . $el(0xB7, $el(0xF7, $uint(1)) . $el(0xF1, $uint(strlen($front))))));
$front = $seekHead(strlen($front) + strlen($cluster)) . $info . $tracks;
$file = tempnam(sys_get_temp_dir(), 'mkv');
file_put_contents($file, $head . $id(0x18538067) . "\x01\xff\xff\xff\xff\xff\xff\xff" . $front . $cluster . $cues); // a segment of unknown size
$m = new Matroska($file);
same([1.0], [$m->duration()], 'a duration in timestamp units, as a 4-byte float');
same([[0.1, strlen($head) + 12 + strlen($front)]], $m->cues(), 'the cues after the clusters, found by the seek head');
$got = [];
while (($p = $m->next()) !== null) { $got[] = [round($p->time, 3), round($p->duration, 3), $p->keyframe, $p->data]; }
same([
    [0.1, 0.01, true, '>one'],
    [0.11, 0.01, true, '>x1'], [0.12, 0.01, true, '>' . $big], [0.13, 0.01, true, '>x3'],
    [0.14, 0.01, true, '>e1234'], [0.15, 0.01, true, '>' . $big], [0.16, 0.01, true, '>e3'],
    [0.17, 0.01, false, '>f1'], [0.18, 0.01, false, '>f2'],
    [0.095, 0.03, false, '>grouped'],
], $got, 'Xiph, EBML and fixed lacing, a block group with its duration and reference, the stripped header put back');
unlink($file);

// VP9 and AV1: AV1's CodecPrivate is its av1C record (marker and version 1, Main profile); VP9 has none, its vpcC made from the keyframe's header.
$m = new Matroska(__DIR__ . '/testdata/clip-av1.mkv');
same(['V_AV1', "\x81\x00"], [$m->tracks()[0]->codec, substr($m->tracks()[0]->private, 0, 2)], 'an AV1 track keeps its av1C');
$m = new Matroska(__DIR__ . '/testdata/clip-vp9.mkv');
[$v] = $m->tracks();
same(['V_VP9', ''], [$v->codec, $v->private], 'a VP9 track, of no CodecPrivate');
while (($p = $m->next()) !== null && $p->track !== $v) {}
$vpcC = new \ReflectionMethod(\MyPHP\Native\CoreMedia::class, 'vpcC');
same(bin2hex(pack('CC3CCCCCCn', 1, 0, 0, 0, 0, 10, 8 << 4 | 1 << 1 | 0, 2, 2, 2, 0)), bin2hex($vpcC->invoke(null, $v, $p->data)), 'its vpcC: version 1, profile 0, level 1 (160 by 90), 8 bits, 4:2:0, limited range, colors unknown');
$err = null;
try { $vpcC->invoke(null, $v, "\x00\x00"); } catch (RuntimeException $e) { $err = $e->getMessage(); }
same('myphp: track 1 starts with no VP9 frame', $err, 'what is no VP9 frame throws');

// The colors the container says (Matroska's Colour, an MP4's colr nclx), none where it says none; a VP9's vpcC takes them where its frame has none (an HDR film's PQ).
$colors = fn(\MyPHP\Media\Track $t) => $t->colors;
same([['primaries' => 1, 'transfer' => 1, 'matrix' => 1, 'full' => false], ['primaries' => 9, 'transfer' => 16, 'matrix' => 9, 'full' => false], [], []],
    [$colors((new Matroska(__DIR__ . '/testdata/clip-av1-10.mkv'))->tracks()[0]), $colors((new \MyPHP\Media\Mp4(__DIR__ . '/testdata/clip-av1-pq.mp4'))->tracks()[0]),
     $colors((new Matroska(__DIR__ . '/testdata/clip-av1.mkv'))->tracks()[0]), $colors((new \MyPHP\Media\Mp4(__DIR__ . '/testdata/clip.mp4'))->tracks()[0])],
    'BT.709 of a Matroska Colour, BT.2020 and PQ of an MP4\'s colr, none of files that say none');
$pq = new \MyPHP\Media\Track($v->number, $v->type, $v->codec, $v->private, $v->language, $v->name, $v->isDefault, $v->frameDuration, $v->width, $v->height, 0.0, 0, colors: ['primaries' => 9, 'transfer' => 16, 'matrix' => 9]);
$m = new Matroska(__DIR__ . '/testdata/clip-vp9.mkv');
while (($p = $m->next()) !== null && $p->track->type !== \MyPHP\Media\Track::VIDEO) {}
same(bin2hex(pack('CC3CCCCCCn', 1, 0, 0, 0, 0, 10, 8 << 4 | 1 << 1 | 0, 9, 16, 9, 0)), bin2hex($vpcC->invoke(null, $pq, $p->data)), 'a VP9 of the container\'s BT.2020 and PQ: its vpcC says them');

// Opus: its CodecPrivate the OpusHead (2 channels, a pre-skip of 312), its packets of as many frames as their TOC byte says (20 ms: 960 at 48 kHz).
$m = new Matroska(__DIR__ . '/testdata/clip-opus.mkv');
$a = $m->tracks()[1];
same(['A_OPUS', 48000.0, 2, 'OpusHead', 312], [$a->codec, $a->sampleRate, $a->channels, substr($a->private, 0, 8), unpack('v', $a->private, 10)[1]], 'an Opus track and its OpusHead');
while (($p = $m->next()) !== null && $p->track !== $a) {}
same(960, \MyPHP\Native\CoreMedia::frames($p), 'a 20 ms packet, 960 frames');
$toc = fn(int $toc, string $more = '') => \MyPHP\Native\CoreMedia::frames(new Packet($a, 0.0, 0.0, true, chr($toc) . $more));
same([480, 2880, 1920, 120, 1920, 2880], [$toc(0 << 3), $toc(3 << 3), $toc(13 << 3 | 1), $toc(16 << 3), $toc(31 << 3 | 2), $toc(19 << 3 | 3, "\x03")], 'SILK, hybrid and CELT sizes, two frames, three by the count byte');

// Where the picture shows (Media\Picture, the player's aspect ratio, crop and rotation): a 16:9 picture in a 4:3 box, at 4:3, cut to 21:9, turned.
use MyPHP\Media\Picture;
same([[0.0, 3.75, 40.0, 22.5], [40.0, 22.5]], Picture::fit(40, 30, 16 / 9, null, 0), 'its own aspect: as wide as the box, centered');
same([[0.0, 0.0, 40.0, 30.0], [40.0, 30.0]], Picture::fit(40, 30, 4 / 3, null, 0), 'at 4:3: the box');
[$shown, $whole] = Picture::fit(40, 30, 16 / 9, 4 / 3, 0);
same([[0.0, 0.0, 40.0, 30.0], 30.0], [$shown, $whole[1]], 'cut to 4:3: the part fills the box, the picture as tall');
check(abs($whole[0] - 30 * 16 / 9) < 1e-9, 'and wider, at its own aspect');
[$shown, $whole] = Picture::fit(90, 40, 16 / 9, 21 / 9, 0);
check(abs($shown[2] - 90) < 1e-9 && abs($shown[3] - 90 / (21 / 9)) < 1e-9 && abs($whole[1] - 90 / (16 / 9)) < 1e-9, 'cut to 21:9: as wide as the box, the picture as wide and taller');
[$shown, $whole] = Picture::fit(40, 30, 16 / 9, null, 90);
check(abs($shown[3] - 30) < 1e-9 && abs($shown[2] - 30 * 9 / 16) < 1e-9 && abs($whole[0] - 30) < 1e-9 && abs($whole[1] - 30 * 9 / 16) < 1e-9, 'turned a quarter: upright in the box, as tall as it, the picture its width that height');


// Chapters: those of the default edition by their start, titled by their first display ('' for one of no title); none in a file of none.
$m = new Matroska(__DIR__ . '/testdata/clip-chapters.mkv');
same([['Opening', 0.0], ['', 1.0], ['第三章', 1.5]], array_map(fn(\MyPHP\Media\Chapter $c) => [$c->title, $c->start], $m->chapters()), "a Matroska file's chapters");
same([], (new Matroska(__DIR__ . '/testdata/clip.mkv'))->chapters(), 'a file of no chapters');

// FLAC: its STREAMINFO made the dfLa box AVFoundation makes of an MP4's, the frames a packet from its frame header (4608 here, the last fewer).
$m = new Matroska(__DIR__ . '/testdata/clip-flac.mkv');
$a = $m->tracks()[1];
same(['A_FLAC', 44100.0, 2], [$a->codec, $a->sampleRate, $a->channels], 'a FLAC track');
$dfLa = (new \ReflectionMethod(\MyPHP\Native\CoreMedia::class, 'dfLa'))->invoke(null, $a);
same(['00000032', 'dfLa', '00000000', '80000022', substr($a->private, 8, 34)], [bin2hex(substr($dfLa, 0, 4)), substr($dfLa, 4, 4), bin2hex(substr($dfLa, 8, 4)), bin2hex(substr($dfLa, 12, 4)), substr($dfLa, 16)], 'its magic cookie, a dfLa box of its STREAMINFO');
$frames = [];
while (($p = $m->next()) !== null) { if ($p->track === $a) { $frames[] = \MyPHP\Native\CoreMedia::frames($p); } }
check(count($frames) > 2 && count(array_unique(array_slice($frames, 0, -1))) === 1 && $frames[0] === 4608 && end($frames) <= 4608, 'its packets of 4608 frames, the last no more: ' . implode(',', array_unique($frames)));
same([90112, 2560], [array_sum($frames), end($frames)], 'all of them as ffprobe counts: 19 of 4608 and the last of 2560');

// On macOS, the format the layer and VideoToolbox decode by says the container's colors, as AVFoundation's of an MP4's colr: PQ and
// BT.2020 of a Matroska AV1 (hardware decoding, which read none from the film), none of a film that says none.
if (PHP_OS_FAMILY !== 'Darwin') {
    echo "  skipped: the formats' colors (macOS)\n";
    return;
}
$cm = \FFI::cdef('void* CMFormatDescriptionGetExtension(void*, void*); extern void* kCMFormatDescriptionExtension_TransferFunction; extern void* kCMFormatDescriptionExtension_ColorPrimaries; extern void* kCMFormatDescriptionExtension_YCbCrMatrix; extern void* kCMFormatDescriptionExtension_FullRangeVideo; void CFRelease(void*);',
    '/System/Library/Frameworks/CoreMedia.framework/CoreMedia');
$ext = function (\MyPHP\Media\Track $t) use ($cm): array {
    $f = \MyPHP\Native\CoreMedia::format($t);
    $out = [];
    foreach (['ColorPrimaries', 'TransferFunction', 'YCbCrMatrix', 'FullRangeVideo'] as $k) {
        $v = $cm->CMFormatDescriptionGetExtension($f, $cm->{"kCMFormatDescriptionExtension_$k"});
        $out[$k] = $v === null ? null : ($k === 'FullRangeVideo' ? (bool)\MyPHP\Native\ObjC::msg('b:', \MyPHP\Native\ObjC::import($v), 'boolValue') : \MyPHP\Native\ObjC::phpStr(\MyPHP\Native\ObjC::import($v)));
    }
    $cm->CFRelease($f);
    return $out;
};
$hdr = (new \MyPHP\Media\Mp4(__DIR__ . '/testdata/clip-av1-pq.mp4'))->tracks()[0];
same([['ColorPrimaries' => 'ITU_R_2020', 'TransferFunction' => 'SMPTE_ST_2084_PQ', 'YCbCrMatrix' => 'ITU_R_2020', 'FullRangeVideo' => false],
      ['ColorPrimaries' => null, 'TransferFunction' => null, 'YCbCrMatrix' => null, 'FullRangeVideo' => null]],
    [$ext($hdr), $ext((new Matroska(__DIR__ . '/testdata/clip-av1.mkv'))->tracks()[0])], 'the format of a PQ film says BT.2020 and PQ, of one that says none nothing');
