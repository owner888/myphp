<?php
// MPEG transport streams read in PHP (Media\Ts), on any system, as FFmpeg's
// mpegts demuxer reads them: the program's streams as Matroska's tracks
// (H.264's and HEVC's parameter sets an avcC or hvcC record, the size from
// the SPS; AAC's AudioSpecificConfig from ADTS; MP3), a frame a packet at
// the times ffprobe lists (from the file's start), keyframes, a seek to the
// keyframe before; a Blu-ray's .m2ts (192-byte packets); over HTTP, frame
// for frame as from the file.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Media\{HttpBytes, Matroska, Packet, Track, Ts};
use MyPHP\Native\{Curl, CurlLoop};

$read = function (Ts $t): array {
    $out = ['video' => [], 'audio' => []];
    while (($p = $t->next()) !== null) { $out[$p->track->type][] = $p; }
    return $out;
};
$ts = new Ts(__DIR__ . '/testdata/clip.ts'); // clip.mkv's, its times from 1.4 s
same([['V_MPEG4/ISO/AVC', 160, 90], ['A_AAC', 44100.0, 2]], [
    [$ts->tracks()[0]->codec, $ts->tracks()[0]->width, $ts->tracks()[0]->height],
    [$ts->tracks()[1]->codec, $ts->tracks()[1]->sampleRate, $ts->tracks()[1]->channels],
], 'its streams, named as Matroska names them, the size from the SPS');
$mkv = new Matroska(__DIR__ . '/testdata/clip.mkv');
same(bin2hex($mkv->tracks()[0]->private), bin2hex($ts->tracks()[0]->private), 'the avcC record of its parameter sets, as the Matroska file has it');
same(substr(bin2hex($mkv->tracks()[1]->private), 0, 4), bin2hex($ts->tracks()[1]->private), 'the AudioSpecificConfig of its ADTS headers (the Matroska file\'s says no SBR after it)');
check(abs($ts->duration() - 2.023) < 0.01, "its duration, from its last time: {$ts->duration()}");
$all = $read($ts);
same([48, 88], [count($all['video']), count($all['audio'])], 'a frame a packet, as ffprobe lists them');
same([0.023, true, [0.023, 1.023]], [round($all['video'][0]->time, 3), $all['video'][0]->keyframe, array_values(array_map(fn($p) => round($p->time, 3), array_filter($all['video'], fn($p) => $p->keyframe)))],
    'the times from the file\'s start (ffprobe\'s), keyframes at its IDRs');
same([0.042, 0.023], [round($all['video'][5]->duration, 3), round($all['audio'][5]->duration, 3)], 'a frame\'s duration: the next one\'s decode time less its own; 1024 samples');
$mkvFrames = [];
while (($p = $mkv->next()) !== null) { if ($p->track->type === Track::VIDEO) { $mkvFrames[] = $p->data; } }
same(array_map('strlen', array_slice($mkvFrames, 1, 5)), array_map(fn($p) => strlen($p->data), array_slice($all['video'], 1, 5)), 'frames as lengths and NAL units, as Matroska\'s (no access unit delimiters)');
$ts->seek(1.4);
$first = $ts->next();
while ($first !== null && $first->track->type !== Track::VIDEO) { $first = $ts->next(); }
same([1.023, true], [round($first?->time ?? -1, 3), $first?->keyframe], 'a seek: from the keyframe before');
$ts->seek(0.5);
same(136, array_sum(array_map('count', $read($ts))), 'a seek to the first keyframe: all of it again');
same(1.023, round($ts->keyframe(1.7)?->time ?? -1, 3), 'the keyframe for a time alone (a thumbnail\'s)');

$hevc = new Ts(__DIR__ . '/testdata/clip-hevc.ts');
$h = new Matroska(__DIR__ . '/testdata/clip-hevc.mkv');
same(['V_MPEGH/ISO/HEVC', 160, 90], [$hevc->tracks()[0]->codec, $hevc->tracks()[0]->width, $hevc->tracks()[0]->height], 'HEVC: its size from its SPS');
same(substr(bin2hex($h->tracks()[0]->private), 0, 44), substr(bin2hex($hevc->tracks()[0]->private), 0, 44), 'its hvcC record\'s head as x265 writes it (profile, level, chroma, depths)');
same([48, 96], array_map('count', array_values($read($hevc))), 'its frames');

$mp3 = new Ts(__DIR__ . '/testdata/clip-mp3.ts');
same(['A_MPEG/L3', 44100.0, 2], [$mp3->tracks()[1]->codec, $mp3->tracks()[1]->sampleRate, $mp3->tracks()[1]->channels], 'MP3');
same(80, count($read($mp3)['audio']), 'a frame of 1152 samples a packet');

// A Blu-ray's: each packet after a timecode of 4 bytes.
$m2ts = sys_get_temp_dir() . '/myphp-' . getmypid() . '.m2ts';
file_put_contents($m2ts, implode('', array_map(fn($p) => "\0\0\0\0" . $p, str_split((string)file_get_contents(__DIR__ . '/testdata/clip.ts'), 188))));
try {
    $b = new Ts($m2ts);
    same([48, 88], array_map('count', array_values($read($b))), 'an .m2ts, of packets of 192 bytes');
} finally {
    @unlink($m2ts);
}

try {
    Curl::ffi();
} catch (\Throwable $e) {
    echo "  skipped: ts over http: {$e->getMessage()}\n";
    return;
}
$dir = sys_get_temp_dir() . '/myphp-ts-' . getmypid();
@mkdir($dir);
copy(__DIR__ . '/testdata/clip.ts', "$dir/clip.ts");
[$base, $stop] = rangeServer($dir);
try {
    $net = new Ts(new HttpBytes("$base/clip.ts?rate=40000")); // slower than it plays: read as it comes
    $got = [];
    for ($until = microtime(true) + 10; microtime(true) < $until;) {
        $p = $net->next();
        if ($p !== null) { $got[] = $p; continue; }
        if ($net->ready()) { break; }
        CurlLoop::pump(0.01);
    }
    $file = new Ts(__DIR__ . '/testdata/clip.ts');
    $want = [];
    while (($p = $file->next()) !== null) { $want[] = $p; }
    $key = fn(Packet $p) => [$p->track->type, round($p->time, 3), md5($p->data)];
    same(array_map($key, $want), array_map($key, $got), 'over HTTP, frame for frame as from the file');
} finally {
    $stop();
    @unlink("$dir/clip.ts");
    @rmdir($dir);
}

// A frame of several PES (Apple's segmenter splits those past 64 KiB, the rest of no PTS), and PES of two frames:
// the stream's bytes made access units as FFmpeg's parser makes them, frame for frame as the file's.
$packets = str_split((string)file_get_contents(__DIR__ . '/testdata/clip.ts'), 188);
$pes = []; // the video's PES, whole: their header's times and data
foreach ($packets as $p) {
    if (((ord($p[1]) & 0x1f) << 8 | ord($p[2])) !== 256) { continue; }
    $q = 4 + ((ord($p[3]) & 0x20) ? 1 + ord($p[4]) : 0);
    if (ord($p[1]) & 0x40) { $pes[] = ['head' => substr($p, $q, 9 + ord($p[$q + 8])), 'data' => substr($p, $q + 9 + ord($p[$q + 8]))]; }
    else { $pes[count($pes) - 1]['data'] .= substr($p, $q); }
}
$packetize = function (string $payload, int &$cc): string { // TS packets of PID 256, the first starting it, stuffed to 188
    $out = '';
    foreach (str_split($payload, 184) as $i => $chunk) {
        $head = "\x47" . chr(($i === 0 ? 0x40 : 0) | 0x01) . "\x00";
        $out .= strlen($chunk) === 184 ? $head . chr(0x10 | ($cc++ & 15)) . $chunk
            : $head . chr(0x30 | ($cc++ & 15)) . chr(183 - strlen($chunk)) . (strlen($chunk) < 183 ? "\x00" . str_repeat("\xFF", 182 - strlen($chunk)) : '') . $chunk;
    }
    return $out;
};
$split = ''; $cc = 0; $done = false;
foreach ($packets as $p) {
    $pid = (ord($p[1]) & 0x1f) << 8 | ord($p[2]);
    if ($pid !== 256) { $split .= $p; continue; }
    if ($done) { continue; }
    $done = true; // the video's PES all at the place of its first
    foreach (array_keys($pes) as $k) {
        $e = $pes[$k];
        if ($k % 3 === 1 && isset($pes[$k + 1])) { // two frames in one PES
            $split .= $packetize($e['head'] . $e['data'] . $pes[$k + 1]['data'], $cc);
            $pes[$k + 1]['data'] = '';
            continue;
        }
        if ($e['data'] === '') { continue; }
        $half = intdiv(strlen($e['data']), 2);
        $split .= $packetize($e['head'] . substr($e['data'], 0, $half), $cc) . $packetize("\0\0\1\xE0\0\0\x80\0\0" . substr($e['data'], $half), $cc); // the rest: a PES of no PTS
    }
}
$file = sys_get_temp_dir() . '/myphp-split-' . getmypid() . '.ts';
file_put_contents($file, $split);
try {
    $whole = array_map(fn($p) => md5($p->data), $read(new Ts(__DIR__ . '/testdata/clip.ts'))['video']);
    $parts = $read(new Ts($file))['video'];
    same($whole, array_map(fn($p) => md5($p->data), $parts), 'frames of two PES and PES of two frames: frame for frame as the file\'s');
} finally {
    @unlink($file);
}
