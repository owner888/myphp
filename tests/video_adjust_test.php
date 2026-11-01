<?php
// The picture's adjustments, as IINA's (SidebarVideoPane's equalizer, Video
// > Flip): mpv's brightness, contrast, saturation, gamma and hue, -100 to
// 100, as the layer's Core Image filters, told with a bar; set by the key
// bindings' set, add and cycle; kept from film to film; flip and mirror,
// which a quarter turn swaps, the picture no longer plain; the video tab's
// sliders and their buttons back to 0.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Media\Player;
use MyPHP\Native\ObjC;
use MyPHP\UI\{Context, MediaPlayerState};

same(['brightness', 'contrast', 'saturation', 'gamma', 'hue'], Player::VIDEO_EQUALIZER, 'mpv\'s video equalizer, IINA\'s sliders');

if (PHP_OS_FAMILY === 'Darwin') {
    Player::$autoLoad = [0, '', '', false];
    $p = new Player(__DIR__ . '/testdata/clip.mkv');
    $s = new MediaPlayerState(); $s->setPlayer($p);
    $filters = function () use ($p): array { // Core Image's filters by name, after the layer's own
        $all = ObjC::msg('p:', $p->out->layer, 'filters');
        $names = [];
        for ($i = 0; $all !== null && $i < ObjC::msg('L:', $all, 'count'); $i++) {
            $f = ObjC::msg('p:L', $all, 'objectAtIndex:', $i);
            if (ObjC::msg('b:p', $f, 'isKindOfClass:', ObjC::cls('CIFilter'))) { $names[] = ObjC::phpStr(ObjC::msg('p:', $f, 'name')); }
        }
        return $names;
    };
    same([], $filters(), 'no filter of a picture as it is');
    $s->setVideoEqualizer('brightness', 20);
    same([20, ['CIColorControls'], ['Brightness: 20', 'eq:brightness']], [$p->videoEqualizer('brightness'), $filters(), array_slice($s->message, 0, 2)], 'brightness: Core Image\'s color controls, told with its bar');
    $s->setVideoEqualizer('gamma', 150); $s->setVideoEqualizer('hue', -30);
    same([100, ['CIColorControls', 'CIGammaAdjust', 'CIHueAdjust']], [$p->videoEqualizer('gamma'), $filters()], 'gamma kept within 100; gamma and hue of their own filters');

    $h = new Headless(fn(Context $c) => $c->mediaPlayer($s), 960, 540);
    $s->perform(['add', 'contrast', '10']); $s->perform(['cycle', 'saturation']); $s->perform(['set', 'hue', '0']);
    $h->frame();
    same([10, 1, 0], [$p->videoEqualizer('contrast'), $p->videoEqualizer('saturation'), $p->videoEqualizer('hue')], 'the bindings\' add, cycle and set of them');

    // Flip and mirror: the picture reversed as it shows, a quarter turn swapping them.
    check($p->plainPicture(), 'a plain picture');
    $s->perform(['mirror'], true); $h->frame();
    same([true, [true, false]], [$s->mirror, $p->reversed()], 'IINA\'s mirror: left for right');
    check(!$p->plainPicture(), 'laid out by the window then');
    $p->setRotation(90);
    same([false, true], $p->reversed(), 'turned a quarter: upside down');
    $p->setRotation(0);

    // The video tab: its sliders, a button back to 0 each.
    $s->sidebar = 'video'; $s->tab = 'video';
    $s->setVideoEqualizer('brightness', 20);
    $h->frame();
    check($h->byText('Brightness') !== null && $h->byText('Hue') !== null, 'the equalizer\'s rows');
    $button = function (\MyPHP\UI\Node $n, string $label) use (&$button): ?\MyPHP\UI\Node {
        if ($n->label === $label && $n->role === \MyPHP\UI\Role::BUTTON) { return $n; }
        foreach ($n->kids as $k) { if (($f = $button($k, $label)) !== null) { return $f; } }
        return null;
    };
    $h = new Headless(fn(Context $c) => $c->mediaPlayer($s), 960, 1000); // tall enough for the tab's last card
    $h->frame(30); // the sidebar slid in
    $reset = $button($h->frame(), 'Brightness');
    check($reset !== null, 'Brightness\'s button back to 0');
    if ($reset !== null) { $h->clickOn($reset); $h->frame(); }
    same(0, $p->videoEqualizer('brightness'), 'clicked: brightness back to 0');

    // The next film: the same adjustments, as mpv keeps its options and filters.
    $p2 = new Player(__DIR__ . '/testdata/clip.mp4'); $s->setPlayer($p2);
    same([10, true], [$p2->videoEqualizer('contrast'), $p2->mirrored()], 'kept for the next film');
}
