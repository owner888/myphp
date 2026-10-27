<?php
// Reading Matroska (Media\Matroska): the tracks, the duration and cues of
// files ffmpeg wrote (H.264 with AAC, HEVC with AC-3), their frames in
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
