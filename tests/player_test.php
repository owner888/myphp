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
    \MyPHP\Media\Player::$autoLoad = [0, '', '', false]; // testdata's films: no subtitle files of theirs matched (IINA's matching gives clip.mkv, first, every "clip…" one)
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

    // A folder of the film with subtitles and the file beside it alone, its subtitle files found as IINA's settings find them by default.
    $subsDir = sys_get_temp_dir() . '/myphp-player-subs-' . getmypid();
    @mkdir($subsDir);
    foreach (['clip-subs.mkv', 'clip-subs.zh.srt'] as $f) { copy(__DIR__ . "/testdata/$f", "$subsDir/$f"); }
    $withFiles = function () use ($subsDir): Player {
        \MyPHP\Media\Player::$autoLoad = [2, '', './*', true];
        try { return new Player("$subsDir/clip-subs.mkv"); } finally { \MyPHP\Media\Player::$autoLoad = [0, '', '', false]; }
    };
    // What it tells as it changes: play and pause, the rate, where a seek went, a track; each once, when it changes.
    $p = $withFiles();
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

    // AV1 in software (libdav1d), hardware decoding off (IINA's preference), as on a Mac without AV1's decoder: it plays, its
    // frames on screen (a snapshot of them, as the hardware's), its thumbnails.
    if (($why = \MyPHP\Native\Dav1d::unavailable()) !== null) {
        echo "  skipped: AV1 in software: $why\n";
    } else {
        $frame = function (Player $p, float $at) use ($run): ?string { // on screen, at $at: the frame's PNG
            $win = ObjC::msg('p:rLLb', ObjC::alloc('NSWindow'), 'initWithContentRect:styleMask:backing:defer:', ObjC::rect(0, 0, 160, 90), 0, 2, false);
            $content = ObjC::msg('p:', $win, 'contentView');
            ObjC::msg('v:b', $content, 'setWantsLayer:', true);
            ObjC::msg('v:r', $p->out->layer, 'setFrame:', ObjC::rect(0, 0, 160, 90));
            ObjC::msg('v:p', ObjC::msg('p:', $content, 'layer'), 'addSublayer:', $p->out->layer);
            ObjC::msg('v:', $win, 'orderFrontRegardless');
            $p->seek($at); $run(0.4);
            $png = $p->snapshot();
            for ($i = 0, $then = $png; $i < 10; $i++, $then = $png) { // until it shows one frame a while: how long that took, told
                $run(0.1);
                $png = $p->snapshot();
                if ($png === $then) { break; }
            }
            if ($i > 0) { echo "  note: the frame on screen changed for " . (0.1 * $i) . " s more after the seek's 0.4\n"; }
            ObjC::msg('v:', $p->out->layer, 'removeFromSuperlayer');
            ObjC::msg('v:', $win, 'close');
            return $png;
        };
        $pixels = function (?string $png): ?string { // the PNG's RGBA, of the film's size
            if ($png === null) { return null; }
            $img = \MyPHP\Native\ImageIO::decode($png, 160);
            $rgba = \FFI::string($img['pix'], $img['w'] * $img['h'] * 4);
            \MyPHP\Native\Mem::free($img['pix']);
            return $rgba;
        };
        $mean = fn(string $a, string $b): float => array_sum(array_map(fn($x, $y) => abs($x - $y), unpack('C*', $a), unpack('C*', $b))) / strlen($a);
        Player::$hardwareDecoding = false;
        try {
            $p = new Player(__DIR__ . '/testdata/clip-av1.mkv');
            check($p->videoTrack()?->codec === 'V_AV1', 'AV1 in software: its video track');
            $p->play();
            for ($waited = 0; $p->time() === 0.0 && $waited < 40; $waited++) { $run(0.05); }
            $from = $p->time();
            $run(0.5);
            $failed = $p->out->failure();
            check($failed === null && $p->time() - $from > 0.3, 'it plays, decoded by libdav1d: ' . ($failed ?? round($p->time() - $from, 2) . ' s in 0.5'));
            $p->pause();
            // The frame of the time sought, from wherever it was: at 1 s from a pause at about 0.5, and from 1.5 s, the same picture,
            // another than 1.5 s's (a frame shown of before the seek, or of the hidden ones decoded for it, would differ).
            $soft = $pixels($frame($p, 1.0));
            $other = $pixels($frame($p, 1.5));
            $again = $pixels($frame($p, 1.0));
            check($soft !== null && strlen($soft) === 160 * 90 * 4, 'its frame on screen: a snapshot of the film\'s size');
            $same = $soft === null || $again === null ? INF : $mean($soft, $again);
            $apart = $soft === null || $other === null ? 0.0 : $mean($soft, $other);
            check($same < 1 && $apart > 10, 'the frame of the time sought, from wherever it was: ' . round($same, 2) . ' apart, against ' . round($apart, 2) . ' to 1.5 s\'s');
            $thumb = $p->thumbnail(1.5, 80);
            check($thumb !== null && [$thumb->width, $thumb->height] === [80, 45] && abs($thumb->time - 1.0) < 0.1, 'a thumbnail, decoded by libdav1d: ' . ($thumb === null ? 'none' : "{$thumb->time} s"));
            // 10 bits (x420 pixel buffers): its frame on screen and its thumbnail as those of its 8-bit twin (the same picture, ffmpeg's
            // libdav1d frames 2.7 apart on average), and another frame far from them.
            $p10 = new Player(__DIR__ . '/testdata/clip-av1-10.mkv');
            $p8 = new Player(__DIR__ . '/testdata/clip-av1-10-twin8.mkv');
            $p10->play(); $run(0.5); $p10->pause();
            $failed = $p10->out->failure();
            check($failed === null && $p10->time() > 0.2, 'a 10-bit film plays, decoded by libdav1d: ' . ($failed ?? round($p10->time(), 2) . ' s in 0.5'));
            [$s10, $s8, $o10] = [$pixels($frame($p10, 1.0)), $pixels($frame($p8, 1.0)), $pixels($frame($p10, 1.5))];
            $twin = $s10 === null || $s8 === null ? INF : $mean($s10, $s8);
            $far = $s10 === null || $o10 === null ? 0.0 : $mean($s10, $o10);
            check($twin < 6 && $far > 10, 'its frame on screen as its 8-bit twin\'s: ' . round($twin, 2) . ' apart, against ' . round($far, 2) . ' to 1.5 s\'s');
            [$t10, $t8] = [$p10->thumbnail(1.5, 80), $p8->thumbnail(1.5, 80)];
            check($t10 !== null && $t8 !== null && $mean($t10->rgba(), $t8->rgba()) < 6, 'its thumbnail as its twin\'s: mean error ' . ($t10 === null || $t8 === null ? '-' : round($mean($t10->rgba(), $t8->rgba()), 2)));
            Player::$hardwareDecoding = true;
            $h = new Player(__DIR__ . '/testdata/clip-av1.mkv');
            if (\MyPHP\Native\CoreMedia::playable($h->videoTrack())) { // this Mac's hardware: the thumbnails alike (its frames on screen are not
                // compared: a seek's snapshot of the hardware's AV1 now and then shows a frame decoded on the way, 0.375 s's for 1 s)
                $ht = $h->thumbnail(1.5, 80);
                check($thumb !== null && $ht !== null && $mean($thumb->rgba(), $ht->rgba()) < 8, 'its thumbnail as the hardware\'s: mean error ' . ($thumb === null || $ht === null ? '-' : round($mean($thumb->rgba(), $ht->rgba()), 2)));
            }
        } finally {
            Player::$hardwareDecoding = true;
        }
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
    // Vorbis, which the system has no decoder of: decoded in PHP (Native\SoftAudio), played as PCM, stereo and 5.1; a seek plays on.
    foreach (['clip-vorbis.mkv', 'clip-vorbis51.mka'] as $file) {
        $p = new Player(__DIR__ . "/testdata/$file");
        check($p->audioTrack()?->codec === 'A_VORBIS', "$file: a Vorbis track plays");
        $p->play();
        for ($waited = 0; $p->time() === 0.0 && $waited < 40; $waited++) { $run(0.05); }
        $from = $p->time();
        $run(0.4);
        $failed = $p->out->failure();
        check($failed === null && $p->time() - $from > 0.25, "$file: Vorbis plays, decoded in PHP: " . ($failed ?? round($p->time() - $from, 2) . ' s in 0.4'));
        $p->seek(0.5); $run(0.3);
        check($p->out->failure() === null && $p->time() > 0.6, "$file: and on after a seek: at {$p->time()}");
        $p->pause();
    }
    // An external audio file (IINA's Load External Audio, mpv's audio-add): its track beside the film's, chosen, playing from where the film is.
    $p = new Player(__DIR__ . '/testdata/clip.mp4');
    $p->seek(0.5);
    $added = $p->loadAudio(__DIR__ . '/testdata/clip-vorbis.mkv');
    check(count($added) === 1 && $p->audioTrack() === $added[0] && $added[0]->name === 'clip-vorbis.mkv' && in_array($added[0], $p->audioTracks(), true), 'an external audio file: its track listed, named after it, chosen');
    $p->play(); $run(0.4);
    check($p->out->failure() === null && $p->time() > 0.7, "it plays with the film from where it was: at {$p->time()}");
    $p->pause();
    $err = null;
    try { $p->loadAudio(__DIR__ . '/testdata/clip-subs.zh.srt'); } catch (\RuntimeException $e) { $err = $e->getMessage(); }
    check($err !== null, "what is no audio: $err");
    // The equalizer (IINA's): on, the audio decoded here (by the system, Vorbis in PHP), filtered and played as PCM, from where it is; Flat, as before.
    foreach (['clip.mkv', 'clip.mp4', 'clip-vorbis.mkv'] as $file) {
        $p = new Player(__DIR__ . "/testdata/$file");
        $p->setEqualizer(\MyPHP\UI\MoviePlayerState::EQUALIZER_PRESETS['Rock']);
        $p->play();
        for ($waited = 0; $p->time() === 0.0 && $waited < 40; $waited++) { $run(0.05); }
        $from = $p->time();
        $run(0.4);
        check($p->out->failure() === null && $p->time() - $from > 0.25, "$file: the equalizer on, it plays filtered: " . ($p->out->failure() ?? round($p->time() - $from, 2) . ' s in 0.4'));
        $p->setEqualizer(array_fill(0, 10, 0));
        $from = $p->time();
        $run(0.3);
        check($p->out->failure() === null && $p->time() - $from > 0.15, "$file: Flat, it plays on as the system decodes it: " . ($p->out->failure() ?? round($p->time() - $from, 2) . ' s in 0.3'));
        $p->pause();
    }
    same(\MyPHP\Native\Equalizer::FREQUENCIES, Player::EQUALIZER_BANDS, "the equalizer's bands, IINA's");
    foreach ([[1, 2], array_fill(0, 10, 13)] as $bad) {
        $err = null;
        try { $p->setEqualizer($bad); } catch (\RuntimeException $e) { $err = $e->getMessage(); }
        check($err !== null, "an equalizer of $err");
    }

    // Subtitles: the file's text tracks, the files beside it named after it (shown first), the lines showing where it is, a delay.
    $p = $withFiles();
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
    // A secondary track (mpv's secondary-sid): its text beside the first's, styles left out, its own delay; one track shows once.
    $p->setSecondarySubtitleTrack($subs[1]);
    $p->seek(1.1);
    same([["Second line\ntwo rows"], ["ASS 一行, 带逗号\n第二行", 'overlapping']], [$p->subtitles(), $p->secondarySubtitles()], 'a secondary track: the ASS one\'s text beside the first\'s');
    $p->setSecondarySubtitleDelay(-0.5);
    $p->seek(0.6);
    same([['第一句 字幕'], ["ASS 一行, 带逗号\n第二行", 'overlapping']], [$p->subtitles(), $p->secondarySubtitles()], 'half a second early, the first not');
    $p->setSecondarySubtitleDelay(0);
    $err = null;
    try { $p->setSubtitleTrack($subs[1]); } catch (\RuntimeException $e) { $err = $e->getMessage(); }
    check($err !== null && $p->subtitleTrack() === $subs[0], 'the secondary track is refused as the first, as mpv\'s ("already selected")');
    $p->setSecondarySubtitleTrack(null);
    same([], $p->secondarySubtitles(), 'no secondary track: none');
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
    $err = null;
    try { $p->setSecondarySubtitleTrack($pgs[0]); } catch (\RuntimeException $e) { $err = $e->getMessage(); }
    check($err !== null && $p->secondarySubtitleTrack() === null, 'an image track is no secondary one');
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

    // MPEG-TS (Media\Ts): a .ts played, a seek to its keyframe before.
    $p = new Player(__DIR__ . '/testdata/clip.ts');
    check(abs($p->duration() - 2.02) < 0.05 && $p->size() === [160, 90] && $p->audioTrack()?->codec === 'A_AAC', 'an MPEG-TS file: its duration, size and sound');
    $p->play(); $run(0.6); $p->pause();
    check($p->time() > 0.3, "it plays: {$p->time()}");
    $p->seek(1.9);
    $p->seek(1.4, false); // back: to the keyframe before
    check(abs($p->time() - 1.023) < 0.01, "a keyframe seek: {$p->time()}");

    // A film over HTTP (testdata/range_server.php): read as it comes, played; sent slowly, the clock waits for it (the buffering event, how much came), then goes on.
    $dir = sys_get_temp_dir() . '/myphp-player-http-' . getmypid();
    @mkdir($dir);
    copy(__DIR__ . '/testdata/clip.mkv', "$dir/clip.mkv");
    copy(__DIR__ . '/testdata/clip-cues-front.mkv', "$dir/front.mkv"); // its cues before its clusters: it plays as its first bytes come
    copy(__DIR__ . '/testdata/clip.mp4', "$dir/clip.mp4");
    copy(__DIR__ . '/testdata/clip-chapters.mp4', "$dir/chapters.mp4"); // its movie box after its samples
    foreach (glob(__DIR__ . '/testdata/hls/*') ?: [] as $f) { copy($f, "$dir/" . basename($f)); }
    foreach (['clip-dash.mp4', 'clip-dash.m4a', 'clip-subs.zh.srt'] as $f) { copy(__DIR__ . "/testdata/$f", "$dir/$f"); }
    [$base, $stop] = rangeServer($dir);
    $fake = "$dir/yt-dlp"; // testdata/fake-yt-dlp.php, as Media\YtDlp runs a program
    file_put_contents($fake, "#!/bin/sh\nMYPHP_YTDLP_BASE='$base' exec '" . PHP_BINARY . "' '" . __DIR__ . "/testdata/fake-yt-dlp.php' \"\$@\"\n");
    chmod($fake, 0755);
    $ytdlp = \MyPHP\Media\YtDlp::$path;
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
        // MP4 over HTTP (Media\Mp4): played, its movie box where it is, its chapters.
        $p = new Player("$base/clip.mp4");
        check(abs($p->duration() - 2.04) < 0.05 && $p->size() === [160, 90] && $p->audioTrack()?->codec === 'A_AAC', 'an MP4 over HTTP: its duration, size and sound');
        $p->play(); $run(0.6); $p->pause();
        check($p->time() > 0.3, "it plays: {$p->time()}");
        $p = new Player("$base/chapters.mp4");
        same(['Opening', '', '第三章'], array_map(fn($c) => $c->title, $p->chapters()), 'its movie box after its samples, its chapters');
        $p->play(); $run(0.6); $p->pause();
        check($p->time() > 0.3, "it plays too: {$p->time()}");
        // HLS (Media\Hls): played; a master playlist's audio renditions, the one chosen played.
        $p = new Player("$base/ts.m3u8");
        check($p->remote() && abs($p->duration() - 2.042) < 0.01 && $p->size() === [160, 90], 'HLS: its duration and size');
        $p->play(); $run(0.6); $p->pause();
        check($p->time() > 0.3 && $p->cacheSpeed() >= 0, "it plays: {$p->time()}");
        $p = new Player("$base/master.m3u8");
        same(['English', 'Français'], array_map(fn(Track $t) => $t->name, $p->audioTracks()), 'its audio renditions, as tracks');
        same('English', $p->audioTrack()?->name, 'the default one plays');
        $p->play(); $run(0.3);
        $p->setAudioTrack($p->audioTracks()[1]);
        $from = $p->time();
        $run(0.6); $p->pause();
        check($p->audioTrack()?->name === 'Français' && $p->time() > $from + 0.3, "another chosen plays on: from $from to {$p->time()}");
        // A page (yt-dlp's, as mpv's ytdl_hook): a video's format and an audio's played as one, read with the page's headers; its
        // title, chapters, subtitles (downloaded once chosen); a playlist's entries, the first playing.
        \MyPHP\Media\YtDlp::$path = $fake;
        $p = new Player("$base/watch?v=x");
        same(['A clip of x', ['One', 'Two'], [1, 1001]], [$p->title(), array_map(fn($c) => $c->title, $p->chapters()), [$p->videoTrack()?->number, $p->audioTrack()?->number]],
            'a page: its title, chapters, the video\'s and the audio\'s tracks');
        $sub = array_values(array_filter($p->subtitleTracks(), fn(Track $t) => $t->name === 'Chinese'))[0] ?? null;
        check($sub !== null && $p->subtitleTrack() === null, 'its subtitles, listed, none shown');
        $p->setSubtitleTrack($sub);
        $p->play();
        for ($waited = 0; $p->subtitles() === [] && $waited < 40; $waited++) { $run(0.05); }
        check($p->time() > 0.2 && $p->subtitles() === ['第一句 字幕'], 'it plays, the subtitles chosen downloaded and shown: ' . json_encode($p->subtitles()) . " at {$p->time()}");
        $p->pause();
        $p = new Player("$base/list?id=L");
        same([['First', 'Second'], 0, 'A clip of a'], [array_map(fn($f) => $p->playlist()?->title($f), $p->playlist()?->files() ?? []), $p->playlist()?->index(), $p->title()], 'a playlist: its entries, the first playing');
        $err = null;
        try { new Player("$base/nothing.html?fail"); } catch (\RuntimeException $e) { $err = $e->getMessage(); }
        check($err !== null && str_contains($err, '404') && str_contains($err, 'Unsupported URL'), "a URL of no film, nor a page yt-dlp reads: both said: $err");
        \MyPHP\Media\YtDlp::$path = $ytdlp;

        $err = null;
        try { new Player("$base/clip.mkv?fail"); } catch (\RuntimeException $e) { $err = $e->getMessage(); }
        check($err !== null && str_contains($err, '404'), 'a film the server fails: said, ' . $err);
    } finally {
        $stop();
        \MyPHP\Media\YtDlp::$path = $ytdlp;
        foreach (glob("$dir/*") ?: [] as $f) { @unlink($f); }
        @rmdir($dir);
    }
    foreach (glob("$subsDir/*") ?: [] as $f) { @unlink($f); }
    @rmdir($subsDir);
}
