<?php
// Playing media, on macOS (Media\Player): a Matroska file (H.264 with AAC)
// and an MP4 of the same (AVFoundation's reader), their size, duration,
// video track and audio tracks; the clock running while it plays and holding while paused;
// seeking, paused and playing; playing to the end, which is told.
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
        $p->pause();
        $at = $p->time();
        $run(0.3);
        check(abs($p->time() - $at) < 0.01, "$file: and holds while paused: {$p->time()} after $at");
        $p->seek(1.4);
        check(abs($p->time() - 1.4) < 0.01, "$file: a seek goes to the time itself, not the keyframe before: {$p->time()}");
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
}
