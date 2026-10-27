<?php
// The video player Context::videoPlayer builds (UI\VideoPlayer,
// VideoPlayerState): its subtitles outlined, its labels as IINA's in the
// app's language (times, numbers, a track's row and its language's name);
// on macOS, built over a film: the title, the times, the window asked to
// keep the picture's shape and to show the traffic lights with the
// controls, a message at a pause, the sidebar opened by its gear and its
// tabs, the aspect, crop and rotation it sets, IINA's keys, the wheel;
// where films were left, noted and gone back to.
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

// Where films were left (Media\PlaybackPositions): by path, in its JSON file, read again by another; forgotten; the most recent KEEP kept.
$file = sys_get_temp_dir() . '/myphp-positions-' . getmypid() . '.json';
@unlink($file);
$pos = new \MyPHP\Media\PlaybackPositions($file);
same(null, $pos->get('/films/a.mkv'), 'a film never left: nowhere');
$pos->set('/films/a.mkv', 431.25); $pos->set('/films/b.mkv', 12.0);
same([431.25, 12.0], [(new \MyPHP\Media\PlaybackPositions($file))->get('/films/a.mkv'), (new \MyPHP\Media\PlaybackPositions($file))->get('/films/b.mkv')], 'where each was left, in its file');
$pos->forget('/films/a.mkv');
same(null, (new \MyPHP\Media\PlaybackPositions($file))->get('/films/a.mkv'), 'one watched to the end forgotten');
for ($i = 0; $i < \MyPHP\Media\PlaybackPositions::KEEP; $i++) { $pos->set("/films/$i.mkv", 1.0); }
same([null, 1.0], [$pos->get('/films/b.mkv'), $pos->get('/films/0.mkv')], 'the least recent forgotten past KEEP');
unlink($file);

// A playlist (Media\Playlist): the films of a folder in Finder's order, the one opened playing; next and previous; adding, taking out; durations read as asked.
$dir = sys_get_temp_dir() . '/myphp-playlist-' . getmypid();
@mkdir($dir);
foreach (['ep 10.mkv', 'ep 2.mkv', 'Ep 1.mkv', 'notes.txt', '.hidden.mkv'] as $f) { copy(__DIR__ . '/testdata/clip.mkv', "$dir/$f"); }
$list = \MyPHP\Media\Playlist::folder("$dir/ep 2.mkv");
same([['Ep 1.mkv', 'ep 2.mkv', 'ep 10.mkv'], 1, "$dir/ep 2.mkv"], [array_map('basename', $list->files()), $list->index(), $list->current()], 'the films of the folder, numbers by their value, the one opened playing');
same(["$dir/ep 10.mkv", null, "$dir/ep 2.mkv"], [$list->next(), $list->next(), $list->previous()], 'next, none after the last, previous');
check(abs($list->duration(0) - 2.023) < 0.01, 'a film\'s duration, read from its header: ' . $list->duration(0));
$list->add("$dir/ep 2.mkv"); $list->add("$dir/notes.txt");
same(4, $list->count(), 'a film added once');
$list->remove(0);
same([0, "$dir/ep 2.mkv"], [$list->index(), $list->current()], 'one before the one playing taken out: it stays the one playing');
same(null, $list->duration(2), 'no duration for what is no film');
$list->remove(0);
same([-1, null], [$list->index(), $list->current()], 'the one playing taken out: none plays');
same(['solo.webm'], array_map('basename', \MyPHP\Media\Playlist::folder("$dir/../solo.webm")->files()), 'a film alone: its playlist itself');
// Shuffled and sorted as IINA's, the one playing staying the one playing; unshuffled back to the order before, one added since after.
$list = new \MyPHP\Media\Playlist(['/m/b/2.mkv', '/m/a/3.mkv', '/m/c/1.mkv', '/m/a/4.mkv', '/m/b/5.mkv', '/m/c/6.mkv']);
$list->select('/m/a/4.mkv');
mt_srand(1);
$list->shuffle();
check($list->shuffled() && $list->current() === '/m/a/4.mkv' && $list->count() === 6, 'shuffled, the one playing still playing');
$list->add('/m/z/0.mkv');
$list->unshuffle();
same([['/m/b/2.mkv', '/m/a/3.mkv', '/m/c/1.mkv', '/m/a/4.mkv', '/m/b/5.mkv', '/m/c/6.mkv', '/m/z/0.mkv'], 3, false], [$list->files(), $list->index(), $list->shuffled()], 'unshuffled: the order before, the one added since after');
$list->sortByName();
same(['0', '1', '2', '3', '4', '5', '6'], array_map(fn($f) => basename($f, '.mkv'), $list->files()), 'sorted by name');
same('/m/a/4.mkv', $list->current(), 'the one playing still playing');
$list->sortByPath(false);
same(['/m/z/0.mkv', '/m/c/6.mkv', '/m/c/1.mkv', '/m/b/5.mkv', '/m/b/2.mkv', '/m/a/4.mkv', '/m/a/3.mkv'], $list->files(), 'by path, down');
foreach (scandir($dir) as $f) { if ($f[0] !== '.' || $f === '.hidden.mkv') { @unlink("$dir/$f"); } }
@rmdir($dir);

if (PHP_OS_FAMILY === 'Darwin') {
    $run = function (float $seconds): void {
        $until = ObjC::msg('p:d', ObjC::cls('NSDate'), 'dateWithTimeIntervalSinceNow:', $seconds);
        ObjC::msg('v:p', ObjC::msg('p:', ObjC::cls('NSRunLoop'), 'currentRunLoop'), 'runUntilDate:', $until);
    };
    $p = new Player(__DIR__ . '/testdata/clip.mkv');
    $s = new VideoPlayerState();
    $s->setPlayer($p);
    $s->positions = new \MyPHP\Media\PlaybackPositions($scratch = sys_get_temp_dir() . '/myphp-positions-ui-' . getmypid() . '.json'); // not the user's
    $s->title = 'clip.mkv';
    $h = new Headless(fn(Context $c) => $c->videoPlayer($s), 960, 540);
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
    // The wheel over the picture: up and down the volume, across a seek (at most one a tenth of a second).
    $h->input->push(['scroll', 300.0, 200.0, 0.0, -20.0, true]); $h->frame();
    check(abs($s->volume - 0.85) < 1e-9, 'the wheel down 20 points: the volume 10 lower: ' . $s->volume);
    $p->seek(1.5); $from = $p->time();
    $h->input->push(['scroll', 300.0, 200.0, -5.0, 0.0, true]); $h->frame();
    check(abs($p->time() - ($from - 0.5)) < 0.01, 'across 5 points: half a second back: ' . $p->time());
    // Where it was left noted at a pause, for the next time (more than 5 s in, which a clip of 2 s is not: noted, not gone back to).
    $posFile = sys_get_temp_dir() . '/myphp-positions-player-' . getmypid() . '.json';
    $s2 = new VideoPlayerState(); $s2->positions = new \MyPHP\Media\PlaybackPositions($posFile);
    $p2 = new Player(__DIR__ . '/testdata/clip.mp4'); $s2->setPlayer($p2);
    $h2 = new Headless(fn(Context $c) => $c->videoPlayer($s2), 960, 540);
    $h2->frame();
    $p2->play(); $s2->togglePause();
    same(round($p2->time(), 3), (new \MyPHP\Media\PlaybackPositions($posFile))->get($p2->path()), 'where it was paused noted');
    @unlink($posFile);
    $off = new Headless(fn(Context $c) => $c->videoPlayer($s, new VideoPlayerOptions(titleBar: false, osd: false, sidebar: false, keys: false)), 960, 540);
    $off->frame();
    check($off->byText('clip.mkv') === null && !isset($off->rt->windowAsks['buttons']), 'without its title bar: no title, the traffic lights left alone');
    $off->key(Key::SPACE, 'insertText:', 0, ' '); $off->frame();
    check(!$p->playing(), 'without its keys: Space is the app\'s');
    $p->play();
    check($p->awake(), 'playing, the display kept awake');
    $sleepy = new Headless(fn(Context $c) => $c->videoPlayer($s, new VideoPlayerOptions(keepAwake: false)), 960, 540);
    $sleepy->frame();
    check($p->playing() && !$p->awake(), 'without keepAwake: the display may sleep while it plays');
    $p->pause();
    @unlink($scratch);
    // Opening films: a player of each, the playlist of its folder; the next at the end of one; the playlist's sidebar, a double click playing a row.
    $s3 = new VideoPlayerState(); $s3->positions = new \MyPHP\Media\PlaybackPositions($scratch);
    // A folder of a few films, all of whose rows show in the sidebar (testdata's are more than it shows).
    $films = sys_get_temp_dir() . '/myphp-films-' . getmypid();
    @mkdir($films);
    foreach (['clip.mkv', 'clip.mp4', 'clip-chapters.mkv', 'clip-pgs.mkv'] as $f) { copy(__DIR__ . "/testdata/$f", "$films/$f"); }
    check($s3->open("$films/clip.mkv") && $s3->player?->path() === "$films/clip.mkv" && $s3->title === 'clip.mkv', 'a film opened: its player, its title');
    $names = array_map('basename', $s3->playlist->files());
    same(['clip-chapters.mkv', 'clip-pgs.mkv', 'clip.mkv', 'clip.mp4'], $names, 'its playlist the films of its folder, by name');
    same('clip.mkv', $names[$s3->playlist->index()], 'the one opened playing');
    $first = $s3->player;
    check($s3->next() && $s3->player !== $first && !$first->playing(), 'the next one plays, the last one paused');
    check(!$s3->open(__DIR__ . '/testdata/upright.jpg') && $s3->error !== '' && $s3->player !== $first, 'what is no film: its error, the film playing going on');
    $h3 = new Headless(fn(Context $c) => $c->videoPlayer($s3), 960, 540);
    $s3->sidebar = 'playlist'; $h3->frame(); $run(0.3); $h3->frame();
    check($h3->byText('clip.mkv') !== null && count(array_filter($h3->texts(), fn($t) => str_starts_with($t, 'Total '))) === 1, 'the playlist\'s sidebar: its films, how long they last together');
    [$mx, $my] = $h3->middle($h3->byText('clip.mkv'));
    $h3->click($mx, $my); $h3->click($mx, $my, 0, 2); $h3->frame();
    same('clip.mkv', basename($s3->player->path()), 'a double click on a film plays it');
    $s3->player->pause();
    // The loop: off, the file, the playlist; the playlist's goes from the last to the first, the file's plays it again at its end.
    $s3->nextLoop();
    same('file', $s3->loop, 'the loop button: the file');
    $h3->frame();
    $s3->nextLoop(); same('playlist', $s3->loop, 'then the playlist');
    $last = $s3->playlist->files()[$s3->playlist->count() - 1];
    $s3->open($last);
    check($s3->next() && $s3->playlist->index() === 0, 'after the last, the first');
    check($s3->previous() && $s3->player->path() === $last, 'before the first, the last');
    $s3->nextLoop(); same('off', $s3->loop, 'then off');
    check(!$s3->next(), 'off: none after the last');
    $s3->player->pause();
    // The sort button: a menu under its header, as IINA's; by name down, the one playing still playing.
    $byTip = function (?\MyPHP\UI\Node $n, string $tip) use (&$byTip): ?\MyPHP\UI\Node {
        if ($n === null || $n->tooltip === $tip) { return $n; }
        foreach ($n->kids as $k) { if (($r = $byTip($k, $tip)) !== null) { return $r; } }
        return null;
    };
    $s3->playlistTab = 'playlist'; $h3->frame(); $run(0.3); $h3->frame();
    $sortBtn = $byTip($h3->root, 'Sort the playlist');
    check($sortBtn !== null, 'the sort button');
    $h3->press(...$h3->middle($sortBtn)); $h3->release(...$h3->middle($sortBtn));
    same(['# Sorting', 'Filename Ascending', 'Filename Descending', 'File Path Ascending', 'File Path Descending'], $h3->menuLabels(), 'its menu');
    $playing = $s3->playlist->current();
    $h3->chooseMenuItem('Filename Descending');
    $names = array_map('basename', $s3->playlist->files());
    same([$names, $playing], [array_reverse(array_map('basename', (function ($f) { usort($f, fn($a, $b) => strcmp(basename($a), basename($b))); return $f; })($s3->playlist->files()))), $s3->playlist->current()], 'sorted by name down, the one playing still playing');
    // Image subtitles over the picture: a PGS picture where it is on the plane, scaled with it (1920 by 1080 over 960 by 540).
    $s3->open("$films/clip-pgs.mkv");
    $s3->player->pause(); $s3->player->seek(0.5); $s3->sidebar = null;
    $h3->frame(); $run(0.3); $h3->frame();
    $images = function (?\MyPHP\UI\Node $n) use (&$images): array {
        if ($n === null) { return []; }
        $out = $n->kind === \MyPHP\UI\Node::IMAGE && $n->passThrough ? [$n] : [];
        foreach ($n->kids as $k) { array_push($out, ...$images($k)); }
        return $out;
    };
    $shown = array_map(fn($n) => array_map(fn($v) => round($v, 1), $h3->rect($n)), $images($h3->root));
    same([[356.5, 450.0, 247.0, 39.0]], $shown, 'the PGS picture where it shows, at half size');
    // The chapters' tab: a film's chapters (an untitled one by its place), when each starts and the next does, the last to the end; a double click goes to one and plays.
    $s3->open("$films/clip-chapters.mkv");
    $s3->player->pause();
    $s3->sidebar = 'playlist'; $s3->playlistTab = 'chapters'; $h3->frame(); $run(0.3); $h3->frame();
    check($h3->byText('Opening') !== null && $h3->byText('Chapter 1') !== null && $h3->byText('第三章') !== null, 'the chapters, the untitled one by its place');
    check($h3->byText('00:00 → 00:01') !== null && $h3->byText('00:01 → End') !== null, 'their times, the last to the end');
    [$mx, $my] = $h3->middle($h3->byText('第三章'));
    $h3->click($mx, $my); $h3->click($mx, $my, 0, 2); $h3->frame();
    check(abs($s3->player->time() - 1.5) < 0.1 && $s3->player->playing() && $s3->player->chapter() === 2, 'a double click goes to a chapter and plays: ' . $s3->player->time());
    $s3->player->pause();
    @unlink($scratch);
    $s3->setPlayer(new Player(__DIR__ . '/testdata/clip.mkv')); // the folder's films let go before it goes
    foreach (glob("$films/*") ?: [] as $f) { unlink($f); }
    rmdir($films);
}

