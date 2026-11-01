<?php
// IINA's Inspector (UI\MoviePlayerInspector): its tabs over the HUD, of no
// player nothing under them; of a film, General's video and audio as mpv
// names them, Tracks' track chosen, File's path, size and format,
// Status' display and its table of properties watched (the settings'
// watchProperties, mpv's names, Error of one the player has none of).
// Audio > Audio Device: auto and the system's outputs, the player's
// checked; Window > Inspector.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Media\Player;
use MyPHP\UI\{Context, MoviePlayerCommands, MoviePlayerInspector, MoviePlayerMenus, MoviePlayerState};

$s = null; $pane = null;
$h = new Headless(function (Context $c) use (&$s, &$pane): void { $pane = MoviePlayerInspector::build($c, $s)->kids[1] ?? null; }, 456, 519);
$h->frame();
same([12.0, 46.0, 444.0, 465.0], array_map('floatval', $h->rect($pane)), 'the tabs\' pane where IINA\'s tab view is: 12 from the left, 8 under the bar, 444 by 465');
check($h->byText('General') !== null && $h->byText('Status') !== null && $h->byText('Inspector') !== null, 'the title and the tabs in the bar');
check($h->byText('VIDEO') === null, 'of no player, nothing under them');
same(null, MoviePlayerCommands::propertyValue(new MoviePlayerState(), 'path'), 'no property of no film');
same(['Inspector', 'Cmd+I'], [MoviePlayerMenus::inspector(fn() => null)->label, MoviePlayerMenus::inspector(fn() => null)->accelerator], 'Window > Inspector');

if (PHP_OS_FAMILY === 'Darwin') {
    Player::$autoLoad = [0, '', '', false];
    $p = new Player(__DIR__ . '/testdata/clip.mkv');
    $s = new MoviePlayerState(); $s->setPlayer($p);
    $h->frame();
    check($h->byText('h264') !== null && $h->byText('H.264 / AVC / MPEG-4 AVC / MPEG-4 part 10') !== null, 'General: the video\'s format and codec, mpv\'s names');
    check($h->byText('160×90') !== null && $h->byText('avfoundation') !== null && $h->byText('bt.601-525 / bt.1886 (SDR)') !== null, 'its size, the driver, the primaries guessed of a small picture');
    check($h->byText('N/A') !== null, 'what the player cannot know, N/A');
    $h->clickText('File'); $h->frame();
    check($h->byText('matroska,webm') !== null && $h->byText($p->path()) !== null, 'File: its format and its path');
    check($h->byText(\MyPHP\UI\MoviePlayerState::byteCount(filesize($p->path()), false) . 'B') !== null, 'its size');
    $h->clickText('Tracks'); $h->frame();
    check($h->byText('1') !== null && $h->byText('h264') !== null, 'Tracks: the first, the video\'s');
    $s->setSetting('watchProperties', ['time-pos', 'filename', 'no-such-thing']);
    $h->clickText('Status'); $h->frame();
    check($h->byText('clip.mkv') !== null && $h->byText('0.000000') !== null && $h->byText('Error') !== null, 'Status: the properties watched, mpv\'s strings; Error of one unknown');
    same(['avfoundation', 'yes', null], [MoviePlayerCommands::propertyValue($s, 'current-vo'), MoviePlayerCommands::propertyValue($s, 'pause'), MoviePlayerCommands::propertyValue($s, 'estimated-vf-fps')], 'mpv\'s properties the player gives');
    $s->setSetting('watchProperties', []);

    // Audio > Audio Device: auto first, the player's checked.
    $menus = MoviePlayerMenus::menus($s);
    $find = function (array $items, string $id) use (&$find) { foreach ($items as $it) { if ($it->id === $id) { return $it; } if (($f = $find($it->submenu, $id)) !== null) { return $f; } } return null; };
    $dev = $find($menus, 'iina.audioDevice'); ($dev->onOpen)($dev);
    same(['[Autoselect device] auto', true], [$dev->submenu[0]->label, $dev->submenu[0]->checked], 'auto, the settings\' as it starts');
    $s->setAudioDevice('auto');
    same('auto', $s->audioDevice, 'the player\'s choice kept');
}
