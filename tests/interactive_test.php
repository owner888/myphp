<?php
// IINA's interactive mode (InteractiveModeController, CropBoxView): Crop >
// Custom… and Video > Delogo choose a rectangle over the paused picture;
// where the picture shows, a drag choosing anew, an edge dragged, the
// picture's edges taking one near them, the ratios; on macOS, the player's:
// paused, its bar, Done's crop and delogo, labeled, Cancel, full screen
// leaving it, the menus.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Media\{Player, PlayerSettings};
use MyPHP\MenuItem;
use MyPHP\UI\{Context, InputEvent, MediaPlayerInteractive, MediaPlayerMenus, MediaPlayerState};

// Where the picture shows: 20 from the top, as large as leaves 20 at the sides and 88 under it, centered across.
same([96.0, 20.0, 768.0, 432.0], MediaPlayerInteractive::frame(960, 540, 16 / 9), 'a wide window: as tall as it may be');
same([20.0, 20.0, 360.0, 202.5], MediaPlayerInteractive::frame(400, 400, 16 / 9), 'a square one: as wide');

// A press and its drag over a picture of 1920 by 1080 pixels shown 960 by 540 points.
$s = new MediaPlayerState();
$s->interactive = ['mode' => 'crop', 'rect' => [0.0, 1080.0, 0.0, 0.0], 'paused' => true, 'fullScreen' => false, 'drag' => null, 'from' => [0.0, 0.0], 'last' => [0.0, 0.0], 'aspect' => -1];
$ev = fn(int $kind, float $x, float $y) => MediaPlayerInteractive::input($s, new InputEvent($kind, x: $x, y: $y, button: 0), 1920, 1080, 960, 540);
$ev(InputEvent::POINTER_DOWN, 300, 200); $ev(InputEvent::POINTER_MOVE, 100, 50); $ev(InputEvent::POINTER_UP, 100, 50);
same([200.0, 100.0, 400.0, 300.0], $s->interactive['rect'], 'a drag up and left: the box from where it went down to where it is, in the video\'s pixels');
same([200, 100, 400, 300], MediaPlayerInteractive::pixels($s->interactive['rect']), 'in whole pixels');
$ev(InputEvent::POINTER_DOWN, 300, 120); $ev(InputEvent::POINTER_MOVE, 350, 125); $ev(InputEvent::POINTER_UP, 350, 125);
same([200.0, 100.0, 500.0, 300.0], $s->interactive['rect'], 'its right edge dragged: only its width');
$ev(InputEvent::POINTER_DOWN, 101, 120); $ev(InputEvent::POINTER_MOVE, 2, 120); $ev(InputEvent::POINTER_UP, 2, 120);
same([0.0, 100.0, 698.0, 300.0], $s->interactive['rect'], 'its left edge dragged near the picture\'s: within 4 pixels of it, on it');
$ev(InputEvent::POINTER_DOWN, 101, 51); $ev(InputEvent::POINTER_MOVE, 101, 60); $ev(InputEvent::POINTER_UP, 101, 60);
same([0.0, 118.0, 698.0, 282.0], $s->interactive['rect'], 'a corner: the top edge, as IINA\'s');
$ev(InputEvent::POINTER_DOWN, 500, 300); $ev(InputEvent::POINTER_MOVE, 2000, -50);
same([1000.0, 0.0, 920.0, 600.0], $s->interactive['rect'], 'the pointer kept on the picture');
$ev(InputEvent::POINTER_UP, 2000, -50);
$s->interactive['rect'] = [400.0, 0.0, 400.0, 600.0];
$ev(InputEvent::POINTER_DOWN, 200, 150); $ev(InputEvent::POINTER_MOVE, 300, 150); $ev(InputEvent::POINTER_MOVE, 500, 150);
same([800.0, 0.0, 200.0, 600.0], $s->interactive['rect'], 'the left edge past the right');
$ev(InputEvent::POINTER_MOVE, 600, 150); $ev(InputEvent::POINTER_UP, 600, 150);
same([800.0, 0.0, 400.0, 600.0], $s->interactive['rect'], 'and on: the right one now');
check(!$ev(InputEvent::POINTER_MOVE, 10, 10), 'a move unpressed: not its');

if (PHP_OS_FAMILY === 'Darwin') {
    $settings = sys_get_temp_dir() . '/myphp-interactive-' . getmypid() . '.json';
    @unlink($settings);
    Player::$autoLoad = [0, '', '', false];
    $p = new Player(__DIR__ . '/testdata/clip.mkv');
    [$vw, $vh] = $p->size();
    $s = new MediaPlayerState(); $s->playerSettings = new PlayerSettings($settings);
    $s->setPlayer($p);
    $find = function (array $items, string $id) use (&$find): ?MenuItem {
        foreach ($items as $it) {
            if ($it->id === $id) { return $it; }
            if (($f = $find($it->submenu, $id)) !== null) { return $f; }
        }
        return null;
    };
    $crop = $find(MediaPlayerMenus::menus($s), 'iina.crop'); ($crop->onOpen)($crop);
    same(['None', '', 'Custom…'], [$crop->submenu[0]->label, $crop->submenu[count($crop->submenu) - 2]->label, $crop->submenu[count($crop->submenu) - 1]->label], 'Crop: None, the ratios, a separator, Custom…');

    // Custom…: paused, the window its background's color and its size, the picture inset, all of it chosen.
    $p->play();
    ($crop->submenu[count($crop->submenu) - 1]->click)();
    same(['crop', false, false], [$s->interactive['mode'] ?? null, $p->playing(), $s->interactive['paused'] ?? null], 'the crop chosen: paused, playing before');
    $h = new Headless(fn(Context $c) => $c->mediaPlayer($s), 960, 540);
    $h->frame();
    $h->paint();
    same([[96.0, 20.0, 768.0, 432.0], true, false], [$h->rt->videoPlaces[0]['frame'] ?? null, $h->rt->windowAsks['fixedSize'] ?? null, $h->rt->windowAsks['buttons'] ?? null],
        'the picture inset, the window kept its size, the traffic lights hidden');
    check($h->byText("(0, 0) ({$vw}×{$vh})") !== null && $h->byText('Cancel') !== null && $h->byText('Done') !== null && $h->byText('21:9') !== null, 'all of it chosen, told; the ratios, Cancel, Done');
    $h->clickOn($h->byLabel('4:3'));
    $w43 = (int)($vh * 4 / 3);
    check($h->byText('(' . (int)(($vw - $vh * 4 / 3) / 2) . ", 0) ({$w43}×{$vh})") !== null && $s->interactive['aspect'] === 0, '4:3: its largest box, centered');
    $h->clickOn($h->byLabel('Done'));
    [$x, $y, $w, $hh] = MediaPlayerInteractive::pixels([($vw - $vh * 4 / 3) / 2, 0.0, $vh * 4 / 3, (float)$vh]);
    same(["@iina_crop:crop=$w:$hh:$x:$y", "Crop: ($x, $y) ({$w}×{$hh})", null, true], [$s->filters(true)[0]->string() ?? null, $s->message[0], $s->interactive, $p->playing()], 'Done: the crop, labeled, told; out of the mode, playing again');
    ($crop->onOpen)($crop);
    same([], array_keys(array_filter(array_map(fn($i) => $i->checked, $crop->submenu))), 'a custom crop: none of the ratios checked');
    $s->setCropRatio('16:9');
    same([[], 16 / 9], [$s->filters(true), $p->crop()], 'a ratio in its place');

    // Delogo: a box dragged over the picture, Done; checked; again, removed.
    $s->togglePause();
    $s->toggleDelogo();
    $h->frame();
    check($s->interactive['mode'] === 'delogo' && $h->byText("(0, {$vh}) (0×0)") !== null, 'Delogo: nothing chosen, at the bottom left as IINA\'s');
    $h->press(96 + 100, 20 + 100); $h->drag(96 + 300, 20 + 200); $h->release(96 + 300, 20 + 200);
    $box = MediaPlayerInteractive::pixels([100 * $vw / 768, 100 * $vh / 432, 200 * $vw / 768, 100 * $vh / 432]);
    check($h->byText("({$box[0]}, {$box[1]}) ({$box[2]}×{$box[3]})") !== null, 'a drag over the picture: the box chosen');
    $h->clickOn($h->byLabel('Done'));
    same(["@iina_delogo:lavfi=[delogo=x={$box[0]}:y={$box[1]}:w={$box[2]}:h={$box[3]}]", false], [$s->filters(true)[0]->string() ?? null, $p->playing()], 'Done: the delogo, labeled; paused as it was');
    check($find(MediaPlayerMenus::menus($s), 'iina.delogo')->checked, 'Video > Delogo checked');
    $s->toggleDelogo();
    same([[], null], [$s->filters(true), $s->interactive], 'again: removed');

    // Cancel, and going full screen, leave it as it was.
    $s->enterInteractive('crop', true);
    $h->frame();
    $h->clickOn($h->byLabel('Cancel'));
    same([null, []], [$s->interactive, $s->filters(true)], 'Cancel: nothing done');
    $s->enterInteractive('crop', true);
    $h->frame();
    $h->rt->fullScreen = true;
    $h->frame();
    same(null, $s->interactive, 'full screen: out of it');
    $h->rt->fullScreen = false;
    @unlink($settings);
}
