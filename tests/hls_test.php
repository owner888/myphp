<?php
// HLS read in PHP (Media\Hls), on any system, over a local server: a media
// playlist of MPEG-TS segments, of fragmented MP4 (an init segment), of
// AES-128 (IVs of the sequence numbers), of byte ranges of one file, each
// frame for frame as the clear one's, at ffprobe's times; a master
// playlist's variant of the highest bandwidth and its audio renditions,
// only the one chosen read; a seek to the segment of a time; a live
// playlist read again as it grows, past a discontinuity; what fails told.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Media\{Hls, Packet, Track};
use MyPHP\Native\{Curl, CurlLoop};
use RuntimeException;

try {
    Curl::ffi();
} catch (\Throwable $e) {
    echo "  skipped: hls: {$e->getMessage()}\n";
    return;
}

(function () {
    $dir = sys_get_temp_dir() . '/myphp-hls-' . getmypid();
    @mkdir($dir);
    foreach (glob(__DIR__ . '/testdata/hls/*') ?: [] as $f) { copy($f, "$dir/" . basename($f)); }
    [$base, $stop] = rangeServer($dir);
    /** All its frames, read as they come. @return list<Packet> */
    $drain = function (Hls $h, float $within = 10.0): array {
        $out = [];
        for ($until = microtime(true) + $within; microtime(true) < $until;) {
            $p = $h->next();
            if ($p !== null) { $out[] = $p; continue; }
            if ($h->ready()) { break; }
            CurlLoop::pump(0.01);
        }
        return $out;
    };
    $of = fn(array $ps, string $type) => array_values(array_filter($ps, fn(Packet $p) => $p->track->type === $type));
    try {
        $h = new Hls("$base/ts.m3u8");
        same([['V_MPEG4/ISO/AVC', 160, 90], ['A_AAC', 44100.0, 2]], [
            [$h->tracks()[0]->codec, $h->tracks()[0]->width, $h->tracks()[0]->height],
            [$h->tracks()[1]->codec, $h->tracks()[1]->sampleRate, $h->tracks()[1]->channels],
        ], 'a media playlist of MPEG-TS segments: its tracks');
        check(abs($h->duration() - 2.042) < 1e-3 && !$h->live(), "its duration, its segments': {$h->duration()}");
        $h->choose($h->tracks()[0], $h->tracks()[1]);
        $ts = $drain($h);
        $video = $of($ts, Track::VIDEO); $audio = $of($ts, Track::AUDIO);
        same([49, 89], [count($video), count($audio)], 'its frames, as ffprobe lists them');
        same([0.023, 0.0, 0.042], [round($video[0]->time, 3), round($audio[0]->time, 3), round($video[48]->duration, 3)],
            'at ffprobe\'s times (the earliest at 0), the last segment\'s one frame as long as those before');
        same([0.023, 0.523, 1.023, 1.523, 2.023], array_values(array_map(fn(Packet $p) => round($p->time, 3), array_filter($video, fn(Packet $p) => $p->keyframe))), 'a keyframe each segment');
        check($h->speed() > 0, 'how fast its segments came: ' . $h->speed());
        $h->seek(1.2);
        $first = null;
        foreach ($drain($h) as $p) { if ($p->track->type === Track::VIDEO) { $first = $p; break; } }
        same([1.023, true], [round($first?->time ?? -1, 3), $first?->keyframe], 'a seek: from the segment of the time');

        $data = fn(array $ps) => array_map(fn(Packet $p) => [$p->track->type, round($p->time, 3), md5($p->data)], $ps);
        $fm = new Hls("$base/fm.m3u8");
        same(['V_MPEG4/ISO/AVC', 'A_AAC'], array_map(fn(Track $t) => $t->codec, $fm->tracks()), 'fragmented MP4: the init segment\'s tracks');
        $fm->choose($fm->tracks()[0], $fm->tracks()[1]);
        $frames = $drain($fm);
        same([49, 89], [count($of($frames, Track::VIDEO)), count($of($frames, Track::AUDIO))], 'its fragments\' samples');
        $off = array_map(fn(Packet $a, Packet $b) => abs($a->time - $b->time), $audio, $of($frames, Track::AUDIO));
        check(max($off) < 1e-3, 'at the times of the MPEG-TS one\'s (those of 90 kHz): ' . max($off));

        $seq = new Hls("$base/seq.m3u8"); // AES-128, its IVs the segments' sequence numbers (from 5)
        $seq->choose($seq->tracks()[0], $seq->tracks()[1]);
        same($data($ts), $data($drain($seq)), 'AES-128: decrypted, frame for frame the clear segments\'');
        $one = new Hls("$base/one.m3u8");
        $one->choose($one->tracks()[0], $one->tracks()[1]);
        same($data($ts), $data($drain($one)), 'byte ranges of one file: frame for frame');

        $m = new Hls("$base/master.m3u8"); // a variant of 50 kb/s whose playlist is missing, one of 150 kb/s, two renditions
        same([[Track::VIDEO, '', '', true], [Track::AUDIO, 'en', 'English', true], [Track::AUDIO, 'fr', 'Français', false]],
            array_map(fn(Track $t) => [$t->type, $t->type === Track::AUDIO ? $t->language : '', $t->name, $t->isDefault], $m->tracks()), 'a master playlist: the highest variant, its renditions\' tracks');
        $m->choose($m->tracks()[0], $m->tracks()[2]);
        $m->seek(0);
        $frames = $drain($m);
        same([49, 0, 97], [count($of($frames, Track::VIDEO)), count(array_filter($frames, fn(Packet $p) => $p->track === $m->tracks()[1])), count(array_filter($frames, fn(Packet $p) => $p->track === $m->tracks()[2]))],
            'the rendition chosen read, the other not');
        $mixed = array_map(fn(Packet $p) => $p->track->type, array_slice($frames, 0, 40));
        check(count(array_unique($mixed)) === 2, 'the streams\' frames given in the order of their times, together');

        // Opened as soon as a frame of each track came, not the first segment whole (a 1080p one's megabytes).
        copy(__DIR__ . '/testdata/clip-hevc.ts', "$dir/slow.ts");
        file_put_contents("$dir/slow.m3u8", "#EXTM3U\n#EXT-X-TARGETDURATION:3\n#EXTINF:2.0,\nslow.ts?rate=8000\n#EXT-X-ENDLIST\n"); // 64 kB in 8 s
        $at = microtime(true);
        $slow = new Hls("$base/slow.m3u8");
        check(microtime(true) - $at < 2.0 && count($slow->tracks()) === 2, sprintf('a segment sent slowly: opened in %.2f s, its first frames read', microtime(true) - $at));

        // Packed audio (Apple's AC-3 renditions, .ac3): an ID3 tag's timestamp, the frames after it.
        $eng = new Hls("$base/eng.m3u8");
        $eng->choose(null, $eng->tracks()[0]);
        $pk = new Hls("$base/pk-aac.m3u8");
        $pk->choose(null, $pk->tracks()[0]);
        [$a, $b] = [$drain($eng), $drain($pk)];
        same(array_map(fn(Packet $p) => md5($p->data), $a), array_map(fn(Packet $p) => md5($p->data), $b), 'packed ADTS: frame for frame as the TS segments\' sound');
        check(max(array_map(fn(Packet $x, Packet $y) => abs($x->time - $y->time), $a, $b)) < 1e-3, 'at their times (those of 90 kHz)');
        file_put_contents("$dir/packed.m3u8", str_replace('fra.m3u8', 'pk-ac3.m3u8', (string)file_get_contents("$dir/master.m3u8")));
        $ac3 = new Hls("$base/packed.m3u8");
        same(['A_AAC', 'A_AC3'], [$ac3->tracks()[1]->codec, $ac3->tracks()[2]->codec], 'a rendition of packed AC-3 beside one of TS');
        $ac3->choose($ac3->tracks()[0], $ac3->tracks()[2]);
        $frames = $drain($ac3);
        same([49, 61], [count($of($frames, Track::VIDEO)), count(array_filter($frames, fn(Packet $p) => $p->track === $ac3->tracks()[2]))], 'its frames, chosen');

        // A variant in a folder, its renditions beside the master playlist (as Apple's examples): their URIs are the master's.
        @mkdir("$dir/v1");
        file_put_contents("$dir/v1/vid.m3u8", str_replace('vid', '../vid', (string)file_get_contents("$dir/vid.m3u8")));
        file_put_contents("$dir/folders.m3u8", str_replace('vid.m3u8', 'v1/vid.m3u8', (string)file_get_contents("$dir/master.m3u8")));
        $f = new Hls("$base/folders.m3u8");
        $f->choose($f->tracks()[0], $f->tracks()[1]);
        $frames = $drain($f);
        check(count($of($frames, Track::VIDEO)) === 49 && count($of($frames, Track::AUDIO)) > 80, 'its renditions found: ' . count($of($frames, Track::AUDIO)) . ' frames of sound');

        // Live: a playlist with no end, read again every target duration (1 s); it starts 3 segments from its end.
        $live = "#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-TARGETDURATION:1\n#EXT-X-MEDIA-SEQUENCE:0\n";
        foreach ([0, 1, 2, 3, 4] as $i) { $live .= "#EXTINF:" . ($i === 4 ? '0.041667' : '0.5') . ",\nts$i.ts\n"; }
        file_put_contents("$dir/live.m3u8", $live);
        $l = new Hls("$base/live.m3u8");
        check($l->live() && $l->duration() === 0.0, 'a live playlist: of no duration');
        $l->choose($l->tracks()[0], $l->tracks()[1]);
        $before = $drain($l, 0.5);
        same(0.0, round(min(array_map(fn(Packet $p) => $p->time, $before)), 3), 'it starts at 0 three segments from its end');
        // The playlist grows: its first two go, a discontinuity (the same segments again, their times again from theirs), its end.
        file_put_contents("$dir/live.m3u8", "#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-TARGETDURATION:1\n#EXT-X-MEDIA-SEQUENCE:2\n#EXTINF:0.5,\nts2.ts\n#EXTINF:0.5,\nts3.ts\n#EXTINF:0.041667,\nts4.ts\n#EXT-X-DISCONTINUITY\n#EXTINF:0.5,\nts0.ts\n#EXTINF:0.5,\nts1.ts\n#EXT-X-ENDLIST\n");
        $after = $drain($l, 5.0);
        $v = array_map(fn(Packet $p) => $p->time, $of([...$before, ...$after], Track::VIDEO));
        $sorted = $v; sort($sorted);
        same([25 + 24, false], [count($v), $l->live()], 'read again: what it added, its end');
        $gaps = array_map(fn($a, $b) => $b - $a, array_slice($sorted, 0, -1), array_slice($sorted, 1));
        check(min($gaps) > 0.04 && max($gaps) < 0.044, 'its frames on, 1/24 s apart, past the discontinuity (where those before end): ' . round(min($gaps), 3) . ' to ' . round(max($gaps), 3));

        $err = null;
        try { new Hls("$base/nothing.m3u8"); } catch (RuntimeException $e) { $err = $e->getMessage(); }
        check($err !== null && str_contains($err, 'HTTP 404'), "a playlist missing: $err");
        file_put_contents("$dir/broken.m3u8", "#EXTM3U\n#EXT-X-TARGETDURATION:1\n#EXTINF:0.5,\nts0.ts\n#EXTINF:0.5,\ngone.ts\n#EXT-X-ENDLIST\n");
        $b = new Hls("$base/broken.m3u8");
        $b->choose($b->tracks()[0], $b->tracks()[1]);
        $err = null;
        try { $drain($b); } catch (RuntimeException $e) { $err = $e->getMessage(); }
        check($err !== null && str_contains($err, 'gone.ts') && str_contains($err, '404'), "a segment missing, told as it is read: $err");
    } finally {
        $stop();
        foreach ([...glob("$dir/v1/*") ?: [], ...glob("$dir/*") ?: []] as $f) { is_dir($f) ? @rmdir($f) : @unlink($f); }
        @rmdir($dir);
    }
})();
