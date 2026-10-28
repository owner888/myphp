<?php
// yt-dlp read as mpv's ytdl_hook reads it (Media\YtDlp), and two sources
// played as one (Media\Merged), on any system: a fake yt-dlp's JSON
// (testdata/fake-yt-dlp.php) of a video of a video's format and an
// audio's, read with the headers it says, its chapters, its subtitles (not
// its automatic captions), a playlist's entries from the one the URL
// names, yt-dlp's error, the sites read by it first, the wait for an
// ad's available_at; and, where yt-dlp is installed, the real one
// reading a page of an HTML5 video of the local server.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Media\{HttpBytes, Merged, Mp4, Packet, Track, YtDlp};
use MyPHP\Native\{Curl, CurlLoop};
use RuntimeException;

try {
    Curl::ffi();
} catch (\Throwable $e) {
    echo "  skipped: yt-dlp: {$e->getMessage()}\n";
    return;
}

(function () {
    $dir = sys_get_temp_dir() . '/myphp-ytdlp-' . getmypid();
    @mkdir($dir);
    foreach (['clip-dash.mp4', 'clip-dash.m4a', 'clip-subs.zh.srt', 'clip.mp4'] as $f) { copy(__DIR__ . "/testdata/$f", "$dir/$f"); }
    file_put_contents("$dir/page.html", '<html><head><title>A page</title></head><body><video controls><source src="clip.mp4" type="video/mp4"></video></body></html>');
    [$base, $stop] = rangeServer($dir);
    $fake = "$dir/yt-dlp";
    file_put_contents($fake, "#!/bin/sh\nMYPHP_YTDLP_BASE='$base' exec '" . PHP_BINARY . "' '" . __DIR__ . "/testdata/fake-yt-dlp.php' \"\$@\"\n");
    chmod($fake, 0755);
    $was = YtDlp::$path;
    $drain = function ($s): array {
        $out = [];
        for ($until = microtime(true) + 10; microtime(true) < $until;) {
            $p = $s->next();
            if ($p !== null) { $out[] = $p; continue; }
            if ($s->ready()) { break; }
            CurlLoop::pump(0.01);
        }
        return $out;
    };
    try {
        YtDlp::$path = $fake;
        same($fake, YtDlp::find(), 'the yt-dlp set, found');
        $info = YtDlp::info("$base/watch?v=x");
        same('A clip of x', $info['title'], 'its JSON: the title');
        $formats = YtDlp::formats($info);
        same(["$base/clip-dash.mp4?need", "$base/clip-dash.m4a?need"], array_column($formats, 'url'), 'a video\'s format and an audio\'s (requested_formats)');
        same([['One', 0.0], ['Two', 1.0]], array_map(fn($c) => [$c->title, $c->start], YtDlp::chapters($info)), 'its chapters');
        same([['zh', 'Chinese', 'srt']], array_map(fn($s) => [$s['language'], $s['name'], $s['ext']], YtDlp::subtitles($info)), 'its subtitles of a kind the player reads, not its automatic captions');

        $err = null;
        try { new HttpBytes($formats[0]['url']); } catch (RuntimeException $e) { $err = $e->getMessage(); }
        check($err !== null && str_contains($err, '403'), "without its headers, the site refuses: $err");
        $video = new HttpBytes($formats[0]['url'], $formats[0]['headers']);
        $audio = new HttpBytes($formats[1]['url'], $formats[1]['headers']);
        $m = new Merged([new Mp4($video), new Mp4($audio)], [$video, $audio]);
        same([[Track::VIDEO, 1], [Track::AUDIO, 1001]], array_map(fn(Track $t) => [$t->type, $t->number], $m->tracks()), 'two sources as one: their tracks, the second\'s numbered from 1000');
        $frames = $drain($m);
        $times = array_map(fn(Packet $p) => $p->time, $frames);
        same([48, 88], [count(array_filter($frames, fn(Packet $p) => $p->track->type === Track::VIDEO)), count(array_filter($frames, fn(Packet $p) => $p->track->type === Track::AUDIO))], 'all their frames, read with the headers');
        $gaps = array_map(fn($a, $b) => $b - $a, array_slice($times, 0, -1), array_slice($times, 1));
        check(min($gaps) > -0.2 && in_array(Track::AUDIO, array_map(fn(Packet $p) => $p->track->type, array_slice($frames, 0, 10)), true), 'in the order of their times, together (the video\'s B-frames aside)');
        check($m->cached() > 0 && abs($m->duration() - 2.043) < 0.01, 'their caches together, the longer duration');
        $m->seek(1.2);
        $first = null;
        foreach ($drain($m) as $p) { if ($p->track->type === Track::VIDEO) { $first = $p; break; } }
        same([1.083, true], [round($first?->time ?? -1, 3), $first?->keyframe], 'a seek of both: the video from its keyframe before');

        $list = YtDlp::info("$base/list?id=L");
        same([[["$base/watch?v=a", 'First'], ["$base/watch?v=b", 'Second']], 0], YtDlp::entries($list, "$base/list?id=L"), 'a playlist: its entries, from the first');
        same(1, YtDlp::entries($list, "$base/list?v=b&list=L")[1], 'from the one the URL names besides its list (watch?v=…&list=…)');

        check(YtDlp::first('https://www.youtube.com/watch?v=x') && YtDlp::first('https://youtu.be/x') && YtDlp::first('https://m.youtube.com/x') && YtDlp::first('https://www.twitch.tv/x')
            && !YtDlp::first('https://example.com/youtube.com/x') && !YtDlp::first("$base/watch?v=x"), 'YouTube\'s and Twitch\'s pages read by yt-dlp first, as mpv\'s include; others once they fail to open');
        $now = time();
        check(abs(YtDlp::wait(['requested_formats' => [['available_at' => $now + 15], ['available_at' => $now + 5]]]) - 15) < 1.5 && YtDlp::wait(['available_at' => $now - 3]) === 0.0
            && YtDlp::wait(['url' => 'x']) === 0.0, 'the seconds till its formats can be read: the latest available_at (an ad\'s), none past it');

        $err = null;
        try { YtDlp::info("$base/nothing"); } catch (RuntimeException $e) { $err = $e->getMessage(); }
        same("myphp: yt-dlp: Unsupported URL: $base/nothing", $err, 'what yt-dlp says when it fails');

        YtDlp::$path = "$dir/none";
        same(null, YtDlp::find(), 'one set that is not there: none');
        YtDlp::$path = null;
        $real = YtDlp::find();
        if ($real === null) {
            echo "  skipped: the real yt-dlp: none installed\n";
            return;
        }
        $page = YtDlp::info("$base/page.html"); // its generic extractor: the page's HTML5 video
        same(["$base/clip.mp4"], array_column(YtDlp::formats($page), 'url'), "the real yt-dlp ($real) on a page of an HTML5 video: its URL");
    } finally {
        YtDlp::$path = $was;
        $stop();
        foreach (glob("$dir/*") ?: [] as $f) { @unlink($f); }
        @rmdir($dir);
    }
})();
