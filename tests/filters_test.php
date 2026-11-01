<?php
// IINA's video and audio filters (its Filters windows, saved filters,
// mpv's vf and af), without mpv: a filter's string as mpv writes it and
// IINA reads it (Media\Filter), IINA's presets' filters (FilterPresets),
// what MyPHP makes of the video's (Media\VideoFilters: the part shown,
// flips, Core Image's effects; Lut3d of a .cube) and the audio's
// (Media\AudioFilters: gains, biquads, pans), where the decoded picture
// lies (Picture::fit), the player's lists, saved filters and their menu
// items; on macOS, the player's and the windows.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Media\{AudioFilters, Filter, FilterPresets, Lut3d, Picture, Player, PlayerSettings, VideoFilters};
use MyPHP\MenuItem;
use MyPHP\UI\{Context, MediaPlayerFilters, MediaPlayerMenus, MediaPlayerState};

// mpv's strings: a label, a name, parameters by place (crop's w:h:x:y) or name; a lavfi's graph; the same filter whatever its parameters' order.
$f = Filter::parse('@iina_crop:crop=1280:536:0:92');
same(['iina_crop', 'crop', '@iina_crop:crop=1280:536:0:92'], [$f->label, $f->name, $f->string()], 'a label, a name, the string again');
same(['crop', ['w' => '1280', 'h' => '536', 'x' => '0', 'y' => '92']], $f->what(), 'crop\'s parameters by their place');
same(['unsharp', ['0' => '5', '1' => '5', '2' => '1.0', '3' => '5', '4' => '5', '5' => '1.0']], Filter::parse('lavfi=[unsharp=5:5:1.0:5:5:1.0]')->what(), 'a lavfi\'s one filter');
check(Filter::parse('equalizer=f=100:g=3')->equals(Filter::parse('equalizer=g=3:f=100')), 'the same of its parameters in another order');
check(!Filter::parse('@a:hflip')->equals(Filter::parse('hflip')), 'not of another label');
same([null, null], [Filter::parse(''), Filter::parse('@x=1')], 'no filter of nothing, nor of a label alone');
same('lavfi=[unsharp=5:5:-0.5:5:5:-0.5]', Filter::unsharp(-0.5)->string(), 'IINA\'s blur, unsharp of a negative amount');
same(['f' => 'a b', 'g' => '2'], Filter::byName('f=%3%a b:g=2'), 'mpv\'s %n% quoting undone');

// IINA's presets: their filters, as its transformers make them.
same([
    'crop=100:50::', 'expand=:::4:0:16', 'lavfi=[unsharp=7:7:1.0:7:7:1.0]', 'lavfi=[unsharp=5:5:-0.5:5:5:-0.5]', 'lavfi=[delogo=x=10:y=20:w=30:h=40]',
    'lavfi=[lutrgb=r=negval:g=negval:b=negval]', 'vflip', 'hflip', 'lavfi=[lut3d=file=/x.cube:interp=nearest]', 'highpass=f=200', 'lavfi=[highpass=f=200]',
], [
    FilterPresets::filter('crop', ['w' => '100', 'h' => '50', 'x' => '', 'y' => ''])->string(),
    FilterPresets::filter('expand', ['w' => '', 'h' => '', 'x' => '', 'y' => '4', 'aspect' => '0', 'round' => '16'])->string(),
    FilterPresets::filter('sharpen', ['msize' => 7, 'amount' => 1.0])->string(),
    FilterPresets::filter('blur', ['msize' => 5, 'amount' => 0.5])->string(),
    FilterPresets::filter('delogo', ['x' => '10', 'y' => '20', 'w' => '30', 'h' => '40'])->string(),
    FilterPresets::filter('negative', [])->string(), FilterPresets::filter('vflip', [])->string(), FilterPresets::filter('hflip', [])->string(),
    FilterPresets::filter('lut3d', ['file' => '/x.cube', 'interp' => 'nearest'])->string(),
    FilterPresets::filter('custom_mpv', ['name' => 'highpass', 'string' => 'f=200'])->string(),
    FilterPresets::filter('custom_ffmpeg', ['name' => 'highpass', 'string' => 'f=200'])->string(),
], 'IINA\'s presets\' strings');
same(['crop', 'expand', 'sharpen', 'blur', 'delogo', 'negative', 'vflip', 'hflip', 'lut3d', 'custom_mpv', 'custom_ffmpeg'], array_keys(FilterPresets::all(true)), 'the video\'s presets, IINA\'s');
same(['custom_mpv', 'custom_ffmpeg'], array_keys(FilterPresets::all(false)), 'the audio\'s, its custom two');

// The video's: the part shown, flips, effects; a crop after a mirror of the mirrored picture's place; errors of what MyPHP has not.
$vf = fn(string ...$s) => new VideoFilters(array_map(fn($x) => Filter::parse($x), $s), 1920, 1080);
same([0.25, 0.0, 0.5, 1.0], $vf('crop=960:1080:480:0')->part, 'a crop: the part, over the picture\'s size');
same([0.25, 0.25, 0.5, 0.5], $vf('crop=960:540')->part, 'centered where x and y are none');
same([0.0, -0.25, 1.0, 1.5], $vf('expand=1920:1620')->part, 'an expand: past the picture, centered');
same([0.0, -1 / 6, 1.0, 4 / 3], $vf('expand=aspect=4/3')->part, 'grown to an aspect');
$m = $vf('hflip', 'crop=480:1080:0:0');
same([[0.75, 0.0, 0.25, 1.0], true], [$m->part, $m->mirror], 'a crop of the mirrored picture\'s left: the decoded one\'s right');
same([true, false, null], [($x = $vf('vflip', 'vflip', 'flip'))->flip, $x->mirror, $x->part], 'flips one after the other');
same([['unsharp', 5, 1.0], ['negative'], ['delogo', 0.1, 0.2, 0.05, 0.1]],
    $vf('lavfi=[unsharp=5:5:1.0:5:5:1.0]', 'lavfi=[lutrgb=r=negval:g=negval:b=negval]', 'lavfi=[delogo=x=192:y=216:w=96:h=108]')->effects, 'unsharp, negative and delogo: the layer\'s');
same([0.6, 0.2], array_slice($vf('crop=960:1080:960:0', 'lavfi=[delogo=x=192:y=216:w=96:h=108]')->effects[0], 1, 2), 'a logo of the part cropped: of the decoded picture');
check(!$vf()->any() && $vf('hflip')->any(), 'none of no filter');
foreach (['scale=640:360' => 'no filter of this name', 'crop=iw/2:ih' => 'no number', 'crop=4000:10' => 'not within', 'lavfi=[lutrgb=r=0]' => 'negval', 'lavfi=[a,b]' => 'not one filter', 'lavfi=[lut3d=file=/nope.cube]' => 'cannot read'] as $s => $why) {
    $threw = '';
    try { $vf($s); } catch (\RuntimeException $e) { $threw = $e->getMessage(); }
    check(str_contains($threw, $why), "$s: $why ($threw)");
}

// A .cube's LUT: its size, its colors (red the fastest) over its domain, RGBA.
$cube = sys_get_temp_dir() . '/myphp-lut-' . getmypid() . '.cube';
file_put_contents($cube, "TITLE \"t\"\n# a comment\nLUT_3D_SIZE 2\nDOMAIN_MIN 0 0 0\nDOMAIN_MAX 2 2 2\n0 0 0\n2 0 0\n0 2 0\n2 2 0\n0 0 2\n2 0 2\n0 2 2\n2 2 2\n");
$lut = Lut3d::read($cube);
same([2, [0.0, 0.0, 0.0, 1.0, 1.0, 0.0, 0.0, 1.0]], [$lut->size, array_values(array_slice(unpack('g*', $lut->data), 0, 8))], 'the LUT over its domain, RGBA');
same('lut3d', $vf("lavfi=[lut3d=file=$cube:interp=trilinear]")->effects[0][0], 'lut3d of it');
unlink($cube);

// Where the decoded picture lies: of a crop of its right half, twice as wide as what shows and its middle a quarter of it left.
[[, , $vw, $vh], [$pw, $ph], $decoded] = Picture::fit(1000, 1000, 1.0, null, 0, [0.5, 0.0, 0.5, 1.0]);
same([1000.0, 1000.0, 1000.0, 1000.0, [2000.0, 1000.0, -500.0, 0.0]], [$vw, $vh, $pw, $ph, $decoded], 'the decoded picture about what shows');
same([400.0, 200.0, 0.0, 0.0], Picture::fit(400, 200, 2.0, null, 0)[2], 'itself where there is no part');

// The audio's: FFmpeg's names and options.
$af = fn(string ...$s) => (new AudioFilters(array_map(fn($x) => Filter::parse($x), $s)))->stages;
same([['gain', 2.0], ['gain', 10 ** (-6 / 20)]], $af('volume=2', 'volume=volume=-6dB'), 'volume: a factor, or dB');
same(['biquad', 'hp', 200.0, 0.0, 'q', 0.707, 1], $af('lavfi=[highpass=f=200:p=1]')[0], 'highpass of one pole');
same(['biquad', 'peak', 1000.0, 3.0, 'o', 2.0, 2], $af('equalizer=f=1000:t=o:w=2:g=3')[0], 'an equalizer\'s band');
same(['biquad', 'low', 100.0, 6.0, 'q', 0.5, 2], $af('bass=g=6')[0], 'bass, FFmpeg\'s defaults');
same(['pan', 2, [[0.0, 1.0], [1.0]]], $af('lavfi=[pan=stereo|c0=c1|c1=c0]')[0], 'pan: the channels swapped');
same(['pan', 1, [[0.5, 0.5]]], $af('pan=mono|c0<c0+c1')[0], 'normalized of <');
$id = AudioFilters::coefficients(['biquad', 'peak', 1000.0, 0.0, 'q', 1.0, 2], 48000);
same([1.0, $id[3], $id[4]], array_slice($id, 0, 3), 'a band of no gain: its zeros its poles, the identity');
$lp = AudioFilters::coefficients(['biquad', 'lp', 1000.0, 0.0, 'q', 0.707, 2], 48000);
closeFloat(1.0, ($lp[0] + $lp[1] + $lp[2]) / (1 + $lp[3] + $lp[4]), 'a lowpass passes what does not change');
same(null, AudioFilters::coefficients(['biquad', 'hp', 30000.0, 0.0, 'q', 0.707, 2], 48000), 'none past half the rate');
foreach (['atempo=2' => 'no filter of this name', 'volume=volume=2*x' => 'no volume', 'pan=5.1|c0=c0' => 'no layout', 'highpass=f=1:p=3' => 'poles'] as $s => $why) {
    $threw = '';
    try { $af($s); } catch (\RuntimeException $e) { $threw = $e->getMessage(); }
    check(str_contains($threw, $why), "$s: $why ($threw)");
}

// The keys of saved filters: as IINA shows them, and as the menus' accelerators.
same(['⌃⇧⌘X', 'Shift+Cmd+x', 'Ctrl+Alt+Shift+Cmd+x', ''], [MediaPlayerFilters::readableKey('x', 'msc'), MediaPlayerFilters::accelerator('x', 'sm'), MediaPlayerFilters::accelerator('x', 'csmo'), MediaPlayerFilters::accelerator('', 'm')], 'IINA\'s c o s m');

// The player's lists, of no film: set, added, toggled (the same whatever the order), removed; saved filters kept in the settings, their menu items.
$settings = sys_get_temp_dir() . '/myphp-filters-' . getmypid() . '.json';
@unlink($settings);
$s = new MediaPlayerState(); $s->playerSettings = new PlayerSettings($settings);
check($s->addFilter(true, Filter::parse('hflip')) && $s->toggleFilter(true, 'crop=100:100:0:0'), 'added, toggled on');
same(['hflip', 'crop=100:100:0:0'], array_map(fn(Filter $f) => $f->string(), $s->filters(true)), 'the video\'s, in order');
check($s->filterOn(false, 'equalizer=g=3:f=100') === false && $s->toggleFilter(false, 'equalizer=f=100:g=3') && $s->filterOn(false, 'equalizer=g=3:f=100'), 'the audio\'s, on whatever the order');
check($s->toggleFilter(false, 'equalizer=g=3:f=100') && $s->filters(false) === [], 'toggled off');
check($s->removeFilter(true, 0) && !$s->removeFilter(true, 5), 'removed by its place, none of a place there is not');
$s->setSavedFilters(true, [['name' => 'Wide', 'filterString' => 'crop=100:100:0:0', 'shortcutKey' => 'w', 'shortcutKeyModifiers' => 'sm']]);
same('Wide', (new PlayerSettings($settings))->get('savedVideoFilters')[0]['name'], 'saved in the settings');
$find = function (array $items, string $id) use (&$find): ?MenuItem {
    foreach ($items as $it) {
        if ($it->id === $id) { return $it; }
        if (($f = $find($it->submenu, $id)) !== null) { return $f; }
    }
    return null;
};
$menus = MediaPlayerMenus::menus($s);
$item = $find($menus, 'iina.savedVideoFilter0');
same(['Wide', 'Shift+Cmd+w', true, 'Cmd+Shift+f', 'Cmd+Shift+g'], [$item?->label, $item?->accelerator, $item?->checked, $find($menus, 'iina.videoFilters')?->accelerator, $find($menus, 'iina.audioFilters')?->accelerator],
    'Saved Video Filters: its name, key, on; Video Filters… and Audio Filters…, IINA\'s keys');
check(MediaPlayerMenus::key($s) !== (function () use ($s) { $s->setSavedFilters(true, []); return MediaPlayerMenus::key($s); })(), 'the menus made again as saved filters change');

if (PHP_OS_FAMILY === 'Darwin') {
    // The player's: a crop lays the decoded picture out about it; a filter it cannot do, the filters as they were; the next film's the same.
    Player::$autoLoad = [0, '', '', false];
    $p = new Player(__DIR__ . '/testdata/clip.mkv');
    $s->setPlayer($p);
    [$w, $h] = $p->size();
    check($s->setFilters(true, [Filter::of('crop', [(string)intdiv($w, 2), (string)$h, '0', '0'])]), 'a crop of the left half');
    [, [$pw], [$dw, , $dx]] = $p->picture(400, 400);
    check(!$p->plainPicture() && abs($dw - 2 * $pw) < 1e-6 && abs($dx - $dw / 4) < 1e-6, "the decoded picture twice as wide, its middle a quarter of it right ($pw, $dw, $dx)");
    check(!$s->setFilters(true, [Filter::parse('scale=10:10')]) && count($p->videoFilters()) === 1, 'one it cannot do: as they were');
    check($s->setFilters(false, [Filter::parse('volume=0.5'), Filter::parse('lavfi=[pan=stereo|c0=c1|c1=c0]')]), 'the audio\'s');
    $p2 = new Player(__DIR__ . '/testdata/clip.mp4'); $s->setPlayer($p2);
    same([1, 2], [count($p2->videoFilters()), count($p2->audioFilters())], 'kept for the next film, as mpv\'s');

    // The window: a row of each filter on, the saved filters; − removes the one chosen; New Filter's sharpen added and told.
    $s->setSavedFilters(true, [['name' => 'Wide', 'filterString' => 'hflip', 'shortcutKey' => 'w', 'shortcutKeyModifiers' => 'm']]);
    $v = MediaPlayerFilters::view(true);
    $h = new Headless(fn(Context $c) => MediaPlayerFilters::build($c, $s, $v), MediaPlayerFilters::WIDTH, MediaPlayerFilters::HEIGHT);
    $h->frame();
    check($h->byText($s->filters(true)[0]->string()) !== null && $h->byText('Saved filters') !== null && $h->byText('Wide') !== null && $h->byText('⌘W') !== null, 'the filter on, the saved one, its key');
    $h->clickOn($h->byLabel('Wide')); $h->frame();
    check($s->filterOn(true, 'hflip'), 'its checkbox: on');
    $h->clickText($s->filters(true)[0]->string()); $h->frame();
    $h->clickOn($h->byLabel('−')); $h->frame();
    same(['hflip'], array_map(fn(Filter $f) => $f->string(), $s->filters(true)), '− removes the one chosen');
    $v->sheet = 'new'; $v->preset = 'sharpen'; $v->values = ['msize' => 5, 'amount' => 1.0];
    $sh = new Headless(fn(Context $c) => MediaPlayerFilters::sheet($c, $s, $v), 450, 200);
    $sh->frame();
    check($sh->byText('Sharpen') !== null && $sh->byText('Matrix size') !== null && $sh->byText('Amount') !== null, 'New Filter: the presets, the parameters of the one chosen');
    $sh->clickOn($sh->byLabel('Add')); $sh->frame();
    same(['hflip', 'lavfi=[unsharp=5:5:1.0:5:5:1.0]'], array_map(fn(Filter $f) => $f->string(), $s->filters(true)), 'Add: the preset\'s filter');
    same(['Added Filter: Sharpen', null], [$s->message[0], $v->sheet], 'told, the sheet closed');
    $v->sheet = 'new';
    $sh->frame();
    $sh->clickOn($sh->byLabel('Negative')); $sh->frame();
    check($v->preset === 'negative' && $sh->byText('Matrix size') === null, 'another preset chosen: its parameters, none');
    $sh->clickOn($sh->byLabel('Add')); $sh->frame();
    same('lavfi=[lutrgb=r=negval:g=negval:b=negval]', $s->filters(true)[2]->string(), 'Negative added');
}
@unlink($settings);
