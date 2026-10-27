<?php
// Playing media, on macOS (Media\Player): a Matroska file (H.264 with AAC)
// and an MP4 of the same (AVFoundation's reader), their size, duration and
// audio tracks; the clock running while it plays and holding while paused;
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
        check($ticks >= 5, "$file: time is told as it plays: $ticks times");
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
}
