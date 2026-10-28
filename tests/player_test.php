<?php
// Playing media, on macOS (Media\Player): a Matroska file (H.264 with AAC)
// and an MP4 of the same (AVFoundation's reader), their size, duration,
// video track and audio tracks; the clock running while it plays and holding while paused;
// seeking, paused and playing; playing to the end, which is told; VP9 and
// AV1 where the Mac decodes them in hardware.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Media\{Player, Track};
use MyPHP\Native\ObjC;

if (PHP_OS_FAMILY === 'Darwin') {
    /** Runs the main run loop for $seconds, as the app would: the player's timer tops up its renderers. */
    $run = function (float $seconds): void {
        $until = ObjC::msg('p:d', ObjC::cls('NSDate'), 'dateWithTimeIntervalSinceNow:', $seconds);
        ObjC::msg('v:p', ObjC::msg('p:', ObjC::cls('NSRunLoop'), 'currentRunLoop'), 'runUntilDate:', $until);
    };
    foreach (['clip.mkv', 'clip.mp4'] as $file) {
        $p = new Player(__DIR__ . "/testdata/$file");
        same([160, 90], $p->size(), "$file: the video's size");
        same(['V_MPEG4/ISO/AVC', 160, 90], [$p->videoTrack()?->codec, $p->videoTrack()?->width, $p->videoTrack()?->height], "$file: its video track");
        check(abs($p->duration() - 2.02) < 0.05, "$file: its duration, {$p->duration()}");
        same(['A_AAC'], array_map(fn(Track $t) => $t->codec, $p->audioTracks()), "$file: its audio track");
        check(Player::playable($p->audioTrack()), "$file: which plays");
        same(0.0, $p->time(), "$file: it opens at the start, paused");
        $ticks = 0;
        $p->on('time', function () use (&$ticks) { $ticks++; });
        $p->play();
        for ($waited = 0; $p->time() === 0.0 && $waited < 40; $waited++) { $run(0.05); } // the first sound of the process opens the audio device, which took a moment in a run of every test
        $from = $p->time();
        $run(0.6);
        $t = $p->time() - $from;
        check($p->playing() && $t > 0.3 && $t < 1.0, "$file: the clock runs while it plays: $t s in 0.6, after {$waited} waits of 50 ms to start");
        check($ticks >= 2, "$file: time is told as it plays, four times a second: $ticks times in 0.6 s");
        $held = (string)shell_exec('pmset -g assertions 2>/dev/null');
        check($p->awake() && str_contains($held, 'Playing ' . $file), "$file: the display kept awake while it plays");
        $p->pause();
        check(!$p->awake() && !str_contains((string)shell_exec('pmset -g assertions 2>/dev/null'), 'Playing ' . $file), "$file: and let sleep once paused");
        $at = $p->time();
        $run(0.3);
        check(abs($p->time() - $at) < 0.01, "$file: and holds while paused: {$p->time()} after $at");
        $p->seek(1.4);
        check(abs($p->time() - 1.4) < 0.01, "$file: a seek goes to the time itself, not the keyframe before: {$p->time()}");
        $first = $file === 'clip.mp4' ? 0.083 : 0.0; // the keyframes: at 0 and 1 s from the first frame's time (AVFoundation's, of the MP4's composition offsets)
        $p->seek(0.6, false);
        check(abs($p->time() - $first) < 0.03, "$file: a keyframe seek back goes to the keyframe at or before: {$p->time()}");
        $p->seek(0.3, false);
        check(abs($p->time() - ($first + 1.0)) < 0.03, "$file: and one ahead to the first at or after: {$p->time()}");
        $p->seek(1.4);
        $ended = 0;
        $p->on('ended', function () use (&$ended) { $ended++; });
        $p->play();
        $run(1.2);
        check($ended === 1 && $p->ended() && !$p->playing(), "$file: it plays to the end, and says so once ($ended)");
        $p->play();
        $run(0.2);
        check($p->playing() && $p->time() < 0.5, "$file: play() after the end starts again: {$p->time()}");
        $p->seek(0.5);
        $run(0.3);
        check($p->playing() && $p->time() > 0.65, "$file: a seek while it plays plays on: {$p->time()}");
        $p->pause();
        $err = null;
        try { $p->setRate(0); } catch (\RuntimeException $e) { $err = $e->getMessage(); }
        check($err !== null, 'a rate of 0 throws: pause() stops');
    }

    // What it tells as it changes: play and pause, the rate, where a seek went, a track; each once, when it changes.
    $p = new Player(__DIR__ . '/testdata/clip-subs.mkv');
    $told = [];
    foreach (['play', 'pause', 'rate', 'seek', 'track'] as $e) {
        $p->on($e, function (mixed ...$a) use (&$told, $e) { $told[] = implode(' ', [$e, ...array_map(fn($v) => is_float($v) ? sprintf('%.1f', $v) : $v, $a)]); });
    }
    $p->play(); $p->play();
    $p->setRate(1.5); $p->setRate(1.5);
    $p->pause(); $p->pause();
    $p->seek(0.5);
    $p->seek(0.4, false); // back to the keyframe at 0
    $p->setSubtitleTrack($p->subtitleTracks()[0]); $p->setSubtitleTrack($p->subtitleTracks()[0]);
    $p->setSubtitleTrack(null);
    same(['play', 'rate 1.5', 'pause', 'seek 0.5', 'seek 0.0', 'track subtitle', 'track subtitle'], $told, 'the player tells each change once');
    $told = [];
    $p->play();
    $run(2.5);
    same(['play', 'pause'], $told, 'it tells the pause at the end too');

    // Frame steps (24 a second, the frames' times Matroska's milliseconds), pausing; the last at 1.958 s. A snapshot of the frame showing, as a PNG.
    $p = new Player(__DIR__ . '/testdata/clip.mkv');
    $p->seek(1.0);
    $steps = [];
    foreach ([1, -2, -1000, 1000, -1] as $n) { $p->step($n); $steps[] = round($p->time(), 3); }
    same([1.042, 0.958, 0.0, 1.958, 1.917], $steps, 'steps: on, back two, to the first, to the last, back one, each at its frame\'s time');
    $p->play(); $run(0.1); $p->step(1);
    check(!$p->playing(), 'a step pauses');
    check($p->snapshot() === null, 'no snapshot of a layer nothing shows: the system gives the frame of one on screen');
    // On screen: its layer in a window of its own, as the app's window has it.
    $win = ObjC::msg('p:rLLb', ObjC::alloc('NSWindow'), 'initWithContentRect:styleMask:backing:defer:', ObjC::rect(0, 0, 160, 90), 0, 2, false); // borderless, buffered
    $content = ObjC::msg('p:', $win, 'contentView');
    ObjC::msg('v:b', $content, 'setWantsLayer:', true);
    ObjC::msg('v:r', $p->out->layer, 'setFrame:', ObjC::rect(0, 0, 160, 90));
    ObjC::msg('v:p', ObjC::msg('p:', $content, 'layer'), 'addSublayer:', $p->out->layer);
    ObjC::msg('v:', $win, 'orderFrontRegardless');
    $p->seek(0.5); $run(0.3);
    $png = $p->snapshot();
    ObjC::msg('v:', $p->out->layer, 'removeFromSuperlayer');
    ObjC::msg('v:', $win, 'close');
    check($png !== null && str_starts_with($png, "\x89PNG"), 'a snapshot of the frame on screen: a PNG');
    same([160, 90], $png === null ? null : array_values(unpack('N2', $png, 16)), 'of the film\'s size');

    // Boundaries (at()): told as it plays past a time, within a few milliseconds; not by a seek landing past it; removed, no more.
    $p = new Player(__DIR__ . '/testdata/clip.mkv');
    $told = [];
    $off = $p->at(1.0, function () use (&$told, &$p) { $told[] = round($p->time(), 3); });
    $p->seek(1.5); $p->seek(0.5);
    same([], $told, 'a seek past it tells nothing');
    $p->play();
    for ($waited = 0; $p->time() < 1.2 && $waited < 60; $waited++) { $run(0.05); }
    $p->pause();
    check(count($told) === 1 && $told[0] >= 1.0 && $told[0] < 1.03, 'played past it: told once, at it: ' . json_encode($told));
    $off();
    $p->seek(0.8); $p->play(); $run(0.5); $p->pause();
    same(1, count($told), 'removed: told no more');

    // Thumbnails: the keyframe at or before a time, decoded by a reader and a decoder of their own: what plays stays where it is.
    foreach (['clip.mkv', 'clip.mp4', 'clip-hevc.mkv'] as $file) {
        $p = new Player(__DIR__ . "/testdata/$file");
        $p->seek(0.4);
        $t = $p->thumbnail(1.5, 80);
        check($t !== null && $t->time > 0.9 && $t->time < 1.1 && [$t->width, $t->height] === [80, 45] && strlen($t->rgba()) === 80 * 45 * 4, "$file: a thumbnail of the keyframe at 1 s, 80 by 45: " . ($t === null ? 'none' : "{$t->time} s, {$t->width} by {$t->height}"));
        check($t !== null && trim($t->rgba(), "\0") !== '' && count(array_unique(str_split(substr($t->rgba(), 0, 4000), 4))) > 4, "$file: of the picture's pixels");
        check(abs($p->time() - 0.4) < 0.01, "$file: the player where it was: {$p->time()}");
    }

    // VP9 and AV1, in Matroska and MP4, where this Mac decodes them in hardware (VP9 on Apple silicon, AV1 from M3): the picture plays with the sound.
    foreach (['clip-vp9.mkv', 'clip-vp9.mp4', 'clip-vp9.webm', 'clip-av1.mkv', 'clip-av1.mp4'] as $file) {
        $p = new Player(__DIR__ . "/testdata/$file");
        if ($p->videoTrack() === null) {
            echo "  note: $file: this Mac decodes its video in no hardware, its sound plays alone\n";
            continue;
        }
        same([160, 90], $p->size(), "$file: the video's size");
        $p->play();
        for ($waited = 0; $p->time() === 0.0 && $waited < 40; $waited++) { $run(0.05); }
        $from = $p->time();
        $run(0.5);
        $failed = $p->out->failure();
        check($failed === null && $p->time() - $from > 0.3, "$file: it plays, decoded by the system: " . ($failed ?? round($p->time() - $from, 2) . ' s in 0.5'));
        $p->pause();
    }

    // The picture's aspect, crop and rotation (the window lays it out by Media\Picture), and the sound's delay, which plays on.
    $p = new Player(__DIR__ . '/testdata/clip.mkv');
    check($p->plainPicture(), 'the picture as the file has it');
    $p->setAspectRatio(4 / 3); $p->setCrop(21 / 9); $p->setRotation(90);
    same([4 / 3, 21 / 9, 90, false], [$p->aspectRatio(), $p->crop(), $p->rotation(), $p->plainPicture()], 'an aspect, a crop and a rotation asked');
    $errs = [];
    foreach ([fn() => $p->setRotation(45), fn() => $p->setCrop(0.0), fn() => $p->setAspectRatio(-1.0)] as $bad) {
        try { $bad(); } catch (\RuntimeException $e) { $errs[] = $e->getMessage(); }
    }
    same(['myphp: a rotation is 0, 90, 180 or 270 degrees, not 45', 'myphp: a crop is positive, not 0', 'myphp: an aspect ratio is positive, not -1'], $errs, 'what is no rotation or ratio throws');
    $p->setAspectRatio(null); $p->setCrop(null); $p->setRotation(0);
    $p->setAudioDelay(0.3);
    $p->play();
    for ($waited = 0; $p->time() === 0.0 && $waited < 40; $waited++) { $run(0.05); }
    $from = $p->time();
    $run(0.5);
    $failed = $p->out->failure();
    check($p->plainPicture() && $p->audioDelay() === 0.3 && $failed === null && $p->time() - $from > 0.3, 'the sound 0.3 s late, it plays on: ' . ($failed ?? round($p->time() - $from, 2) . ' s in 0.5'));
    $p->pause();

    // Opus, as WebM files have it: the system decodes it, and the clock runs on its sound.
    $p = new Player(__DIR__ . '/testdata/clip-opus.mkv');
    check($p->audioTrack()?->codec === 'A_OPUS', 'an Opus track plays');
    $p->play();
    for ($waited = 0; $p->time() === 0.0 && $waited < 40; $waited++) { $run(0.05); }
    $from = $p->time();
    $run(0.5);
    $failed = $p->out->failure();
    check($failed === null && $p->time() - $from > 0.3, 'Opus: it plays, decoded by the system: ' . ($failed ?? round($p->time() - $from, 2) . ' s in 0.5'));
    $p->pause();
    // FLAC, in Matroska (its dfLa cookie made of the STREAMINFO) and in MP4 (AVFoundation's sample buffers): decoded by the system.
    foreach (['clip-flac.mkv', 'clip-flac.mp4'] as $file) {
        $p = new Player(__DIR__ . "/testdata/$file");
        check($p->audioTrack()?->codec === 'A_FLAC', "$file: a FLAC track plays");
        $p->play();
        for ($waited = 0; $p->time() === 0.0 && $waited < 40; $waited++) { $run(0.05); }
        $from = $p->time();
        $run(0.5);
        $failed = $p->out->failure();
        check($failed === null && $p->time() - $from > 0.3, "$file: FLAC plays, decoded by the system: " . ($failed ?? round($p->time() - $from, 2) . ' s in 0.5'));
        $p->pause();
    }

    // Subtitles: the file's text tracks, the files beside it named after it (shown first), the lines showing where it is, a delay.
    $p = new Player(__DIR__ . '/testdata/clip-subs.mkv');
    $subs = $p->subtitleTracks();
    same([['S_TEXT/UTF8', 'chi'], ['S_TEXT/ASS', 'eng'], ['S_TEXT/UTF8', 'zh']], array_map(fn(Track $t) => [$t->codec, $t->language], $subs), 'subtitles: two tracks of the file, a file beside it');
    same('clip-subs.zh.srt', $p->subtitleTrack()?->name, 'the file beside it shows');
    $at = function (float $t) use (&$p): array { $p->seek($t); return $p->subtitles(); }; // the player of the moment
    same([['第一句 字幕'], ["Second line\ntwo rows"], []], [$at(0.5), $at(1.1), $at(1.9)], 'its lines where the player is');
    $p->setSubtitleTrack($subs[1]);
    same([["ASS 一行, 带逗号\n第二行", 'overlapping'], []], [$at(1.1), $at(1.7)], 'an ASS track of the file, two lines at once');
    $p->setSubtitleTrack($subs[0]);
    same(['第一句 字幕'], $at(0.6), 'a SubRip track of the file');
    $p->setSubtitleDelay(0.5);
    same(['第一句 字幕'], $at(1.1), 'half a second late');
    $p->setSubtitleDelay(0);
    $shownAt = null;
    $p->on('time', function () use (&$p, &$shownAt) { if ($shownAt === null && $p->subtitles() === ["Second line\ntwo rows"]) { $shownAt = $p->time(); } });
    $p->seek(0.85); $p->play(); $run(0.5); $p->pause();
    check($shownAt !== null && $shownAt < 1.08, "playing, a cue is told as it starts, not a tick late: at $shownAt for 1.0");
    $p->setSubtitleTrack(null);
    same([], $at(1.1), 'none');
    $p = new Player(__DIR__ . '/testdata/clip-subs.mp4');
    $tx3g = array_values(array_filter($p->subtitleTracks(), fn(Track $t) => $t->codec === 'S_TEXT/TX3G'));
    check(count($tx3g) === 1, 'an MP4\'s text track');
    $p->setSubtitleTrack($tx3g[0]);
    same(['第一句 字幕'], $at(0.5), 'its lines');

    // Image subtitles: a PGS track's pictures where the player is, cleared between; a VobSub's; the plane over the picture, cut with a crop.
    $p = new Player(__DIR__ . '/testdata/clip-pgs.mkv');
    $pgs = array_values(array_filter($p->subtitleTracks(), fn(Track $t) => $t->codec === 'S_HDMV/PGS'));
    check(count($pgs) === 1 && $p->subtitleTrack() === $pgs[0], 'a PGS track, shown as the file marks it default');
    $pics = function (float $t) use (&$p): array { $p->seek($t); return array_map(fn($q) => [$q->x, $q->y], $p->subtitlePictures()); };
    same([[[713, 900]], [], [[770, 120], [755, 900]], []], [$pics(0.5), $pics(1.1), $pics(1.5), $pics(1.95)], 'its pictures where the player is');
    same([], $p->subtitles(), 'no lines of text');
    same([0.0, 0.0, 960.0, 540.0], $p->subtitlePlane(960, 540, 1920, 1080), 'the plane over the picture of a 16:9 box');
    $p->setCrop(4 / 3);
    same([-120.0, 0.0, 960.0, 540.0], $p->subtitlePlane(720, 540, 1920, 1080), 'cut with it by a crop, as the picture is: wider than the box of 4:3');
    $p->setCrop(null);
    $p = new Player(__DIR__ . '/testdata/clip-vobsub.mkv');
    $p->setSubtitleTrack($p->subtitleTracks()[0]);
    $pics = function (float $t) use (&$p): array { $p->seek($t); return array_map(fn($q) => [$q->x, $q->y], $p->subtitlePictures()); };
    same([[[713, 900]], [], [[755, 120]], []], [$pics(0.5), $pics(1.1), $pics(1.5), $pics(1.95)], 'a VobSub track\'s, until each stops');

    // ASS: a track's lines in their styles where the player is (their text as subtitles() gives it); an .ass file loaded, its lines so too.
    $p = new Player(__DIR__ . '/testdata/clip-ass.mkv');
    check($p->subtitleTrack()?->codec === 'S_TEXT/ASS', 'an ASS track, shown as the file marks it default');
    $p->seek(1.0);
    same([2, 8, 5, 7], array_map(fn($l) => $l->alignment, $p->subtitleLines()), 'its lines in their styles');
    same('Top: yellow, bold, dark blue outline', $p->subtitles()[1], 'and their text');
    $p->setSubtitleTrack($p->loadSubtitles(__DIR__ . '/testdata/styled.ass'));
    $p->seek(0.3);
    same([2, 8, 5], array_map(fn($l) => $l->alignment, $p->subtitleLines()), "an .ass file's, before its box shows");

    // Chapters: an MP4's chapter track as AVFoundation reads it, the one playing the last started.
    $p = new Player(__DIR__ . '/testdata/clip-chapters.mp4');
    same([['Opening', 0.0], ['', 1.0], ['第三章', 1.5]], array_map(fn(\MyPHP\Media\Chapter $c) => [$c->title, round($c->start, 3)], $p->chapters()), "an MP4's chapters");
    same(0, $p->chapter(), 'the first plays at the start');
    $p = new Player(__DIR__ . '/testdata/clip-chapters.mkv');
    same(3, count($p->chapters()), "a Matroska file's, through the player");

    // A film over HTTP (testdata/range_server.php): read as it comes, played; sent slowly, the clock waits for it (the buffering event, how much came), then goes on.
    $dir = sys_get_temp_dir() . '/myphp-player-http-' . getmypid();
    @mkdir($dir);
    copy(__DIR__ . '/testdata/clip.mkv', "$dir/clip.mkv");
    copy(__DIR__ . '/testdata/clip-cues-front.mkv', "$dir/front.mkv"); // its cues before its clusters: it plays as its first bytes come
    [$base, $stop] = rangeServer($dir);
    try {
        $p = new Player("$base/clip.mkv");
        check($p->remote() && abs($p->duration() - 2.02) < 0.05 && $p->size() === [160, 90], 'over HTTP: its duration and size, from its header');
        same(null, $p->thumbnail(1.0, 80), 'no thumbnail of a film over the network, as IINA makes none');
        $p->play(); $run(0.6); $p->pause();
        check($p->time() > 0.3, "it plays: {$p->time()}");
        $p = new Player("$base/front.mkv?rate=6000"); // 19 kB in some three seconds: slower than it plays
        $told = []; $percents = [];
        $p->on('buffering', function (bool $on) use (&$told, &$p) { $told[] = $on; });
        $p->play();
        for ($waited = 0; !$p->ended() && $waited < 160; $waited++) { $run(0.05); if (($pc = $p->bufferingPercent()) !== null) { $percents[] = $pc; } }
        check($p->ended(), 'sent slowly, it plays to the end: ' . $p->time());
        check(count($told) >= 2 && $told[0] === true && end($told) === false, 'the clock waited for the network, and went on: ' . json_encode($told));
        check($percents !== [] && max($percents) <= 100 && $p->cacheBytes() > 0, 'how much came, as it waited: up to ' . ($percents === [] ? '-' : max($percents)) . ' %, ' . $p->cacheBytes() . ' bytes cached');
        same(null, $p->bufferingPercent(), 'none once it played on');
        $err = null;
        try { new Player("$base/clip.mkv?fail"); } catch (\RuntimeException $e) { $err = $e->getMessage(); }
        check($err !== null && str_contains($err, '404'), 'a film the server fails: said, ' . $err);
    } finally {
        $stop();
        @unlink("$dir/clip.mkv"); @unlink("$dir/front.mkv"); @rmdir($dir);
    }
}
