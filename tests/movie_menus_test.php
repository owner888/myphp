<?php
// IINA's menus of the player (UI\MoviePlayerMenus): the keys of the
// bindings as menus' accelerators, a binding that does what an item does
// (IINA's sameKeyAction), the items IINA binds taking the default set's
// keys and its steps, all disabled of no player; their titles brought up
// to date as they open (MenuItem::$onOpen), a list's items replaced
// (MenuItem::setSubmenu). Of a film: the chapters "time – title", the
// tracks, Subtitles > Encoding reading the files again, Save Downloaded
// Subtitle of no subtitle downloaded.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Media\{Player, Subtitles};
use MyPHP\MenuItem;
use MyPHP\UI\{MoviePlayerMenus, MoviePlayerState};

$find = function (array $items, string $id) use (&$find): ?MenuItem {
    foreach ($items as $it) {
        if ($it->id === $id) { return $it; }
        if (($f = $find($it->submenu, $id)) !== null) { return $f; }
    }
    return null;
};

$acc = [];
foreach (['SPACE', 'Shift+Meta+LEFT', 'S', 'Ctrl+s', 'SHARP', 'F5', 'KP_ENTER', 'Meta+L', 'PLUS', 'Alt+Meta+]', 'MBTN_LEFT', 'a-b-c', 'WHEEL_UP'] as $k) { $acc[] = MoviePlayerMenus::accelerator($k); }
same(['space', 'Shift+Cmd+left', 'Shift+s', 'Ctrl+s', '#', 'f5', 'enter', 'Cmd+Shift+l', 'plus', 'Alt+Cmd+]', null, null, null], $acc,
    'mpv\'s keys as accelerators: names, Meta as Cmd, a capital as Shift and its letter; none of the mouse\'s or a sequence');

same([10.0, true], MoviePlayerMenus::sameAction(['seek', '10', 'exact'], ['seek', '5'], true, [5.0, 60.0]), 'a seek of the range, "exact" kept');
same([-5.0, true], MoviePlayerMenus::sameAction(['seek', '-5', 'relative+exact'], ['seek', '-5'], true, [-60.0, -5.0]), '"relative" aside');
same([5.0, true], MoviePlayerMenus::sameAction(['seek', '5', 'relative', 'exact'], ['seek', '5'], true, [5.0, 60.0]), 'mpv\'s old form of the flags');
same(null, MoviePlayerMenus::sameAction(['seek', '5', 'keyframes'], ['seek', '5'], true, [5.0, 60.0]), 'a flag of no item: not the item\'s');
same(null, MoviePlayerMenus::sameAction(['seek', '600'], ['seek', '5'], true, [5.0, 60.0]), 'out of the range');
same([1.2, false], MoviePlayerMenus::sameAction(['multiply', 'speed', '1.2'], ['multiply', 'speed', '1.1'], true, [1.01, 1.49]), 'a number of the range');
same([[1.0, false], null], [MoviePlayerMenus::sameAction(['set', 'speed', '1'], ['set', 'speed', '1.0'], true, null), MoviePlayerMenus::sameAction(['set', 'speed', '2'], ['set', 'speed', '1.0'], true, null)], 'of no range, the same number');
same([[null, false], null], [MoviePlayerMenus::sameAction(['cycle', 'pause'], ['cycle', 'pause'], false, null), MoviePlayerMenus::sameAction(['cycle', 'mute'], ['cycle', 'pause'], false, null)], 'words alike');

$none = MoviePlayerMenus::menus(null);
same(['Playback', 'Video', 'Audio', 'Subtitles'], array_map(fn(MenuItem $m) => $m->label, $none), 'IINA\'s four menus');
$enabled = function (array $items) use (&$enabled): array {
    $on = [];
    foreach ($items as $it) {
        if ($it->type !== MenuItem::SEPARATOR && !$it->disabled) { $on[] = $it->id; }
        $on = [...$on, ...$enabled($it->submenu)];
    }
    return $on;
};
same(['iina.playback', 'iina.video', 'iina.audio', 'iina.subtitles', 'iina.onlineSubSource'], $enabled([...$none, ...MoviePlayerMenus::fileItems(null)]), 'of no player, the items disabled, but the menus');

$s = new MoviePlayerState();
$menus = MoviePlayerMenus::menus($s);
$keys = [];
foreach (['pause', 'stop', 'forward', 'backward', 'nextFrame', 'screenshot', 'abLoop', 'fileLoop', 'speedUp', 'speedDown', 'speedReset', 'increaseVolume', 'increaseVolumeSlightly', 'mute', 'decreaseSubDelay', 'videoPanel', 'fitToScreen', 'jumpTo', 'speedUpSlightly'] as $name) {
    $keys[$name] = $find($menus, "iina.$name")?->accelerator;
}
same(['pause' => 'space', 'stop' => 'Cmd+.', 'forward' => 'right', 'backward' => 'left', 'nextFrame' => 'Alt+right', 'screenshot' => 'Cmd+s', 'abLoop' => 'Cmd+l', 'fileLoop' => 'Cmd+Shift+l',
    'speedUp' => 'Cmd+]', 'speedDown' => 'Cmd+[', 'speedReset' => 'Cmd+\\', 'increaseVolume' => 'up', 'increaseVolumeSlightly' => 'Alt+up', 'mute' => 'Cmd+/', 'decreaseSubDelay' => 'Shift+z',
    'videoPanel' => 'Cmd+Shift+v', 'fitToScreen' => 'Cmd+3', 'jumpTo' => 'Cmd+J', 'speedUpSlightly' => 'Alt+Cmd+]'], $keys, 'the items IINA binds with the default set\'s keys; Jump to… its own');
same(['Step Forward 5s', 'Speed Up by 2x', 'Volume + 5%', 'Volume - 1%', 'Subtitle Delay - 0.5s', 'Pause', 'Show Video Panel', 'Enter Full Screen'],
    array_map(fn(string $id) => $find($menus, "iina.$id")->label, ['forward', 'speedUp', 'increaseVolume', 'decreaseVolumeSlightly', 'decreaseSubDelay', 'pause', 'videoPanel', 'fullScreen']), 'titles of their steps');
same([true, true, false], [$find($menus, 'iina.nextFrame')->alternate, $find($menus, 'iina.speedUpSlightly')->alternate, $find($menus, 'iina.forward')->alternate], 'the slight steps shown with Option');
check(MoviePlayerMenus::key(null) !== MoviePlayerMenus::key($s) && MoviePlayerMenus::key($s) !== MoviePlayerMenus::key(new MoviePlayerState()), 'another player: other menus');
same(MoviePlayerMenus::key($s), MoviePlayerMenus::key($s), 'the same player and bindings: the same menus');

// Their titles as they open: the panel shown, the subtitles hidden.
$s->sidebar = 'video'; $s->subtitlesVisible = false; $s->loop = 'file';
$video = $find($menus, 'iina.video'); ($video->onOpen)($video);
$subs = $find($menus, 'iina.subtitles'); ($subs->onOpen)($subs);
$play = $find($menus, 'iina.playback'); ($play->onOpen)($play);
same(['Hide Video Panel', 'Show Subtitles', true], [$find($menus, 'iina.videoPanel')->label, $find($menus, 'iina.hideSubtitles')->label, $find($menus, 'iina.fileLoop')->checked],
    'titles and checks brought up to date as the menu opens');

$m = new MenuItem('List', submenu: [new MenuItem('a')]);
$m->setSubmenu([new MenuItem('b'), new MenuItem('c')]);
same([['b', 'c'], [$m, $m]], [array_map(fn($i) => $i->label, $m->submenu), array_map(fn($i) => $i->parent, $m->submenu)], 'a submenu\'s items replaced, the item holding them');

if (PHP_OS_FAMILY === 'Darwin') {
    Player::$autoLoad = [0, '', '', false];
    $p = new Player(__DIR__ . '/testdata/clip-chapters.mkv');
    $s = new MoviePlayerState(); $s->setPlayer($p);
    $menus = MoviePlayerMenus::menus($s);
    $chapters = $find($menus, 'iina.chapters'); ($chapters->onOpen)($chapters);
    check(count($chapters->submenu) === count($p->chapters()) && preg_match('/^\d+:\d\d – /u', $chapters->submenu[0]->label) === 1 && $chapters->submenu[0]->checked, 'the chapters, "time – title", the one playing checked');
    $audio = $find($menus, 'iina.audioTrack'); ($audio->onOpen)($audio);
    same(['<None>', true], [$audio->submenu[0]->label, $audio->submenu[0]->disabled], 'the sound\'s tracks, none not chosen');
    check(count(array_filter($audio->submenu, fn($i) => $i->checked)) === 1, 'the sound playing checked');
    $rotation = $find($menus, 'iina.rotation'); ($rotation->onOpen)($rotation);
    same([['0°', '90°', '180°', '270°'], [true, false, false, false]], [array_map(fn($i) => $i->label, $rotation->submenu), array_map(fn($i) => $i->checked, $rotation->submenu)], 'the rotations in degrees, the one set checked');
    // Subtitles > Encoding: a Big5 file read as GB18030 by default, as Big5 once chosen.
    $srt = sys_get_temp_dir() . '/myphp-menus-' . getmypid() . '.srt';
    file_put_contents($srt, iconv('UTF-8', 'BIG5', "1\n00:00:00,000 --> 00:00:05,000\n繁體字幕\n\n"));
    $s->loadSubtitles($srt);
    $misread = implode('', $p->subtitles());
    $s->setSubEncoding('BIG5');
    same(['繁體字幕', 'BIG5'], [implode('', $p->subtitles()), $s->subEncoding], 'the files read again in the encoding chosen');
    check($misread !== '繁體字幕', 'misread before');
    $enc = $find($menus, 'iina.encoding'); ($enc->onOpen)($enc);
    same(['Traditional Chinese (BIG5)'], array_values(array_map(fn($i) => $i->label, array_filter($enc->submenu, fn($i) => $i->checked))), 'the encoding chosen checked');
    same($srt, $p->subtitlePath($p->subtitleTrack()), 'a subtitle track\'s file');
    Subtitles::$encoding = 'auto';
    @unlink($srt);
}
