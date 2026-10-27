<?php
// The video player Context::videoPlayer builds (UI\VideoPlayer,
// VideoPlayerState): its subtitles outlined, its labels as IINA's in the
// app's language (times, numbers, a track's row and its language's name);
// on macOS, built over a film: the title, the times, the window asked to
// keep the picture's shape and to show the traffic lights with the
// controls, a message at a pause, the sidebar opened by its gear and its
// tabs, the aspect, crop and rotation it sets, IINA's keys.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\App;
use MyPHP\Media\{Player, Track};
use MyPHP\Native\ObjC;
use MyPHP\UI\{Context, Key, VideoPlayer, VideoPlayerOptions, VideoPlayerState};

$t = new Headless(function (Context $c) { $c->column(0, 0, 1); VideoPlayer::subtitle($c, "two\nlines", 20, 40); $c->end(); }, 900, 600);
$t->frame();
same(9, count(array_filter($t->texts(), fn($s) => $s === "two\nlines")), 'its subtitles outlined: the text in white over eight copies in black');
same(['07:09', '1:58:37', '1.5', '2', '0.25'], [VideoPlayerState::clock(429), VideoPlayerState::clock(7117), VideoPlayerState::number(1.5), VideoPlayerState::number(2.0), VideoPlayerState::number(0.25)], 'times and numbers as IINA writes them');
App::setLanguages('en', 'zh-Hans'); App::setLocale('zh-Hans');
$track = new Track(2, Track::AUDIO, 'A_AC3', '', 'chi', '国语', true, 0, 0, 0, 44100, 2);
$row = null;
$t = new Headless(function (Context $c) use ($track, &$row) { $row = VideoPlayer::trackRow($c, $track, '2ch, 44.1kHz'); $c->column(); $c->end(); }, 100, 100);
$t->frame();
same(['国语 (ac3, 2ch, 44.1kHz, 默认)', '中文'], $row, 'an audio track as IINA lists it, in the app\'s language');
same(['English', '日本語'], [VideoPlayer::language('eng', 'en'), VideoPlayer::language('jpn', 'ja')], 'a language\'s name by its ISO 639-2 code, in a locale');
App::setLocale(''); App::setLanguages();
same('', VideoPlayer::language('und', 'en'), 'none for an undetermined language');

if (PHP_OS_FAMILY === 'Darwin') {
    $run = function (float $seconds): void {
        $until = ObjC::msg('p:d', ObjC::cls('NSDate'), 'dateWithTimeIntervalSinceNow:', $seconds);
        ObjC::msg('v:p', ObjC::msg('p:', ObjC::cls('NSRunLoop'), 'currentRunLoop'), 'runUntilDate:', $until);
    };
    $p = new Player(__DIR__ . '/testdata/clip.mkv');
    $s = new VideoPlayerState();
    $s->title = 'clip.mkv';
    $h = new Headless(fn(Context $c) => $c->videoPlayer($p, $s), 960, 540);
    $h->frame();
    check($h->byText('clip.mkv') !== null && $h->byText('00:00') !== null && $h->byText('00:02') !== null, 'the title, where it plays and how long it lasts');
    same([16 / 9, true], [$h->rt->windowAsks['aspect'] ?? null, $h->rt->windowAsks['buttons'] ?? null], 'the window asked to keep the picture\'s shape, its traffic lights showing with the controls');
    $h->paint();
    same(1, count($h->rt->videoPlaces), 'the picture under the controls');
    check(count($h->rt->materialPlaces) >= 2, 'the title bar and the control bar in the system\'s materials');
    $s->togglePause();
    $h->frame();
    check($p->playing() && $h->byText('Resume') !== null, 'playing, told at the top left');
    $p->pause();
    $s->sidebar = 'video'; $h->frame(); $run(0.3); $h->frame();
    check($h->byText('Aspect Ratio') !== null && $h->byText('Speed') !== null, 'the sidebar, its video tab');
    $h->clickText('16:9'); $h->frame();
    same(16 / 9, $p->aspectRatio(), 'a click on 16:9 sets the aspect ratio');
    check($h->byText('Aspect Ratio: 16:9') !== null, 'and tells it');
    same(16 / 9, $h->rt->windowAsks['aspect'] ?? null, 'the window keeps that shape');
    $h->clickText('90°'); $h->frame();
    same(90, $p->rotation(), 'a rotation');
    same(9 / 16, $h->rt->windowAsks['aspect'] ?? null, 'the window turned with the picture');
    $s->tab = 'audio'; $h->frame();
    check($h->byText('Delay') !== null && $h->byText('Equalizer') !== null, 'the audio tab');
    $h->key(Key::ESCAPE, 'cancelOperation:'); $h->frame(); $run(0.3); $h->frame();
    check($s->sidebar === null && $h->byText('Equalizer') === null, 'Escape closes the sidebar');
    $h->key(Key::UP, 'moveUp:'); $h->frame();
    check(abs($s->volume - 1.0) < 1e-9 && $h->byText('Volume: 100') !== null, 'Up raises the volume, as far as 100');
    $h->key(Key::DOWN, 'moveDown:'); $h->frame();
    check(abs($p->volume() - 0.95) < 1e-9, 'Down lowers it by 5');
    $h->key(Key::RIGHT, 'moveRight:'); $h->frame();
    check(abs($p->time() - 5.0) < 0.01 || abs($p->time() - $p->duration()) < 0.01, 'Right goes 5 s on: ' . $p->time());
    $h->key(Key::SPACE, 'insertText:', 0, ' '); $h->frame();
    check($p->playing(), 'Space plays');
    $p->pause();
    $off = new Headless(fn(Context $c) => $c->videoPlayer($p, $s, new VideoPlayerOptions(titleBar: false, osd: false, sidebar: false, keys: false)), 960, 540);
    $off->frame();
    check($off->byText('clip.mkv') === null && !isset($off->rt->windowAsks['buttons']), 'without its title bar: no title, the traffic lights left alone');
    $off->key(Key::SPACE, 'insertText:', 0, ' '); $off->frame();
    check(!$p->playing(), 'without its keys: Space is the app\'s');
}
