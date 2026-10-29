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
use MyPHP\UI\{Context, Key, Node, VideoPlayer, VideoPlayerOptions, VideoPlayerState, VideoPlayerSubtitleStyle};

// Text subtitles in the settings' style, as mpv's sub-* options give it: ASS lines of a Default style on a plane 720 high.
$subSet = new \MyPHP\Media\PlayerSettings(sys_get_temp_dir() . '/myphp-substyle-' . getmypid() . '.json');
[$sl] = VideoPlayerSubtitleStyle::lines($subSet, ["two\nlines"], 16 / 9, 1.0);
same([1280, 720, 2, 25, 25, 22, true], [$sl->playResX, $sl->playResY, $sl->alignment, $sl->marginLeft, $sl->marginRight, $sl->marginVertical, $sl->scaledBorder], 'its plane and place: IINA\'s defaults (bottom center, margins 25 and 22)');
same(['two', "\n", 'lines'], array_map(fn($r) => $r->text, $sl->runs), 'its lines at its newline');
$r0 = $sl->runs[0];
same(['sans-serif', 55.0, 400, false, 0xffffff, 1.0, 0x000000, 1.0, 3.0, 0.0, 1], [$r0->font, $r0->size, $r0->weight, $r0->italic, $r0->color, $r0->alpha, $r0->outlineColor, $r0->outlineAlpha, $r0->border, $r0->shadow, $r0->borderStyle], 'its style: IINA\'s defaults');
same('{\\i1}x', VideoPlayerSubtitleStyle::lines($subSet, ['{\\i1}x'], 16 / 9, 1.0)[0]->text(), 'its text as it is: no tags read');
$subSet->set('subBold', true); $subSet->set('subAlignX', 0); $subSet->set('subAlignY', 0); $subSet->set('subTextColorString', '1/1/0/0.5'); $subSet->set('subBorderStyle', 4); $subSet->set('subShadowColorString', '0/0/1/1');
[$sl] = VideoPlayerSubtitleStyle::lines($subSet, ['x'], 4 / 3, 2.0);
same([960, 7, 50, 44, 700, 0xffff00, 0.5, 110.0, 3, 0x0000ff], [$sl->playResX, $sl->alignment, $sl->marginLeft, $sl->marginVertical, $sl->runs[0]->weight, $sl->runs[0]->color, round($sl->runs[0]->alpha, 2), $sl->runs[0]->size, $sl->runs[0]->borderStyle, $sl->runs[0]->outlineColor],
    'the settings\' (bold, top left, a color half clear, a box behind it of the shadow\'s color), its sizes scaled by the window');
same(8, VideoPlayerSubtitleStyle::lines($subSet, ['x'], 4 / 3, 1.0, true)[0]->alignment - 1 === 6 ? 8 : 0, 'the secondary at the top');
$ass = new \MyPHP\Media\Ass("[Script Info]\nPlayResY: 360\n");
$forced = VideoPlayerSubtitleStyle::forced($subSet, $ass->line('{\\fs10}y', 'Default'), 1.0);
same([27.5, 700, 7, 13, 11], [$forced->runs[0]->size, $forced->runs[0]->weight, $forced->alignment, $forced->marginLeft, $forced->marginVertical], 'an ASS line forced to it (sub-ass-override=force): its sizes of its own plane');
$subSet->set('subBlur', 2.0);
[$sl] = VideoPlayerSubtitleStyle::lines($subSet, ['x'], 4 / 3, 2.0);
$forced = VideoPlayerSubtitleStyle::forced($subSet, $ass->line('{\\frx20}y{\\p1}m 0 0 l 4 4', 'Default'), 1.0);
same([4.0, 1.0, 20.0, 'l'], [$sl->runs[0]->blur, $forced->runs[0]->blur, $forced->runs[0]->rotationX, $forced->runs[1]->drawing[1][0]],
    'the settings\' Blur (sub-blur) on text subtitles, scaled by the window, and on ASS forced to them (of its plane), what else the runs have kept');
same([[0xff8000, 0.5], '1/0.501961/0/0.5'], [VideoPlayerSubtitleStyle::color('1/0.5/0/0.5'), VideoPlayerSubtitleStyle::colorString(0xff8000, 0.5)], 'colors as IINA keeps them, r/g/b/a');
@unlink(sys_get_temp_dir() . '/myphp-substyle-' . getmypid() . '.json');
same(['12.5 Mi', '850 K', '0 ', '1 Gi'], [VideoPlayerState::byteCount(12.5 * 1024 * 1024, true), VideoPlayerState::byteCount(850000, false), VideoPlayerState::byteCount(0, true), VideoPlayerState::byteCount(1024 ** 3, true)], 'bytes as IINA writes them, binary and decimal');
$opening = new VideoPlayerState(); $opening->opening = 'https://example.com/film.mkv';
$t = new Headless(fn(Context $c) => $c->videoPlayer($opening), 960, 540);
$t->frame();
check($t->byText('Opening stream…') !== null, 'a URL opening, no film yet: IINA\'s "Opening stream…"');
$opening->adOver = microtime(true) + 12.3;
$t->frame();
check($t->byText('Skipping ad… 13s') !== null, 'a web page\'s video waiting for the ad before it: the seconds left');
$opening->adOver = null;
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

// The equalizer's profiles (Media\EqualizerProfiles): gains by name, in their JSON file, in the order saved, a renamed one the last; removed; a file of other than ten gains refused.
$file = sys_get_temp_dir() . '/myphp-eq-profiles-' . getmypid() . '.json';
@unlink($file);
$eq = new \MyPHP\Media\EqualizerProfiles($file);
same([], $eq->all(), 'none at first');
$warm = [1, 2, 3, 0, 0, 0, 0, -1, -2, -3.5];
$eq->set('Warm', $warm); $eq->set('0', array_fill(0, 10, 1.5)); $eq->set('Bright', array_reverse($warm));
same(['Warm', '0', 'Bright'], array_map('strval', array_keys((new \MyPHP\Media\EqualizerProfiles($file))->all())), 'in their file, in the order saved (a name like "0" too)');
same(array_map('floatval', $warm), (new \MyPHP\Media\EqualizerProfiles($file))->all()['Warm'], 'their gains');
$eq->rename('Warm', 'Cozy');
same(['0', 'Bright', 'Cozy'], array_map('strval', array_keys((new \MyPHP\Media\EqualizerProfiles($file))->all())), 'a renamed one the last, IINA\'s');
$eq->remove('0'); $eq->remove('Bright'); $eq->remove('Cozy');
same('{}', file_get_contents($file), 'all removed: an object of none');
file_put_contents($file, '{"Bad":[1,2,3]}');
$threw = false;
try { (new \MyPHP\Media\EqualizerProfiles($file))->all(); } catch (\RuntimeException) { $threw = true; }
check($threw, 'a profile of three gains refused');
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
// The audio files of the folder after its films, as IINA's (its videos, then its audio); their tags, read as asked (IINA's playlist's metadata); a film's none.
copy(__DIR__ . '/testdata/clip-tags.mka', "$dir/b song.mka"); copy(__DIR__ . '/testdata/clip-tags.mka', "$dir/A song.mka");
$list = \MyPHP\Media\Playlist::folder("$dir/b song.mka");
same(['Ep 1.mkv', 'ep 2.mkv', 'ep 10.mkv', 'A song.mka', 'b song.mka'], array_map('basename', $list->files()), 'the films, then the audio');
same([null, ['title' => 'A Song', 'artist' => 'The Band', 'album' => 'The Album']], [$list->tags(0), $list->tags(4)], 'an audio file\'s tags, none of a film');
unlink("$dir/b song.mka"); unlink("$dir/A song.mka");
// A Matroska file's tags and its cover, as music mode shows them (mpv's metadata, its attached picture).
$mka = new \MyPHP\Media\Matroska(__DIR__ . '/testdata/clip-tags.mka');
same(['title' => 'A Song', 'artist' => 'The Band', 'album' => 'The Album'], $mka->tags(), 'its tags');
same("\x89PNG", substr((string)$mka->cover(), 0, 4), 'its cover, the PNG attached');
same([['title' => '', 'artist' => '', 'album' => ''], null], [(new \MyPHP\Media\Matroska(__DIR__ . '/testdata/clip.mkv'))->tags(), (new \MyPHP\Media\Matroska(__DIR__ . '/testdata/clip.mkv'))->cover()], 'none of a file of none');
// Its ReplayGain tags (of the segment and of the track), and the gain mpv's replaygain makes of them.
$gain = (new \MyPHP\Media\Matroska(__DIR__ . '/testdata/clip-gain.mka'))->replayGain();
same(['trackGain' => -6.48, 'trackPeak' => 0.988, 'albumGain' => -7.1, 'albumPeak' => null], $gain, 'its ReplayGain tags, "dB" taken off');
same([null, null], [(new \MyPHP\Media\Matroska(__DIR__ . '/testdata/clip-tags.mka'))->replayGain()['trackGain'], (new \MyPHP\Media\Matroska(__DIR__ . '/testdata/clip-tags.mka'))->replayGain()['albumGain']], 'none of a file of none');
$of = fn(array $tags, int $mode, float $preamp = 0, bool $clip = false, float $fallback = 0) => round(Player::replayGainOf($tags, $mode, $preamp, $clip, $fallback), 4);
same(1.0, $of($gain, 0), 'no adjustment: none');
same(round(10 ** (-6.48 / 20), 4), $of($gain, 1), "the track's");
same(round(10 ** (-7.1 / 20), 4), $of($gain, 2), "the album's");
same(round(10 ** (-0.48 / 20), 4), $of($gain, 1, 6), 'with the preamp');
same(round(1 / 0.988, 4), $of($gain, 1, 12), 'no louder than the peak allows');
same(round(10 ** (5.52 / 20), 4), $of($gain, 1, 12, true), 'louder, clipping allowed');
same(round(10 ** (-6.48 / 20), 4), $of(['albumGain' => null, 'albumPeak' => null] + $gain, 2), "the album's: the track's where it has none");
same(round(10 ** (-3 / 20), 4), $of(['trackGain' => null, 'trackPeak' => null, 'albumGain' => null, 'albumPeak' => null], 1, 6, false, -3), 'the fallback for a file of no tags');
// The tracks' languages, as mpv's alang and slang match them.
same(['en', 'en', 'en', 'zh', 'zh', 'de', 'ja', ''], array_map([\MyPHP\Media\Track::class, 'primaryLanguage'], ['eng', 'en-US', 'en', 'chi', 'zh-Hans', 'ger', 'jpn', 'und']), 'their primary languages');
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
    Player::$autoLoad = [0, '', '', false]; // testdata's films: no subtitle files of theirs matched (IINA's matching gives clip.mkv, first, every "clip…" one)
    $run = function (float $seconds): void {
        $until = ObjC::msg('p:d', ObjC::cls('NSDate'), 'dateWithTimeIntervalSinceNow:', $seconds);
        ObjC::msg('v:p', ObjC::msg('p:', ObjC::cls('NSRunLoop'), 'currentRunLoop'), 'runUntilDate:', $until);
    };
    $p = new Player(__DIR__ . '/testdata/clip.mkv');
    $thumbDir = sys_get_temp_dir() . '/myphp-thumbs-' . getmypid(); // not the user's cache
    $thumbCache = new \MyPHP\Media\ThumbnailCache($thumbDir);
    $s = new VideoPlayerState(); $s->thumbnailCache = $thumbCache;
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
    // The seek bar's thumbnails (IINA's): made a few at a time, one of each keyframe (at 0 and 1 s of the clip); over the bar, the time there and its thumbnail above it.
    for ($waited = 0; $s->thumbnails === [] && $waited < 100; $waited++) { $run(0.1); }
    same([0.0, 1.0], array_map(fn($t) => round($t->time, 3), $s->thumbnails), 'the thumbnails, one of each keyframe');
    same([240, 135], [$s->thumbnails[0]->width ?? 0, $s->thumbnails[0]->height ?? 0], '240 pixels wide, of the film\'s shape');
    same(1.0, $s->thumbnailAt(1.5)?->time, 'the thumbnail at a time: the keyframe before');
    // Kept on disk: another state of the film has them at once, from JPEGs, their pixels close to those made.
    $again = new VideoPlayerState(); $again->thumbnailCache = $thumbCache;
    $again->setPlayer(new Player(__DIR__ . '/testdata/clip.mkv'));
    same([0.0, 1.0], array_map(fn($t) => round($t->time, 3), $again->thumbnails), 'the thumbnails from the cache, at once');
    $a = $again->thumbnails[1]->rgba(); $b = $s->thumbnails[1]->rgba();
    [$sum, $n, $max] = [0, 0, 0];
    for ($k = 0; $k < strlen($a); $k++) { if ($k % 4 !== 3) { $d = abs(ord($a[$k]) - ord($b[$k])); $sum += $d; $n++; $max = max($max, $d); } }
    $mean = round($sum / max(1, $n), 2);
    check(strlen($a) === 240 * 135 * 4 && $mean < 6, "their pixels, decoded: within JPEG's loss, on the mean (a channel swapped would be tens off): $mean, at most $max");
    $h->hover(308 + 1.5 + 0.75 * 341, 406 + 49 + 9); $run(0.3); $h->frame(); // the controls fading in again
    $images = function (?\MyPHP\UI\Node $n) use (&$images): array {
        if ($n === null) { return []; }
        $out = $n->image !== null && $n->image->w === 240 ? [$n] : [];
        foreach ($n->kids as $k) { array_push($out, ...$images($k)); }
        return $out;
    };
    $peek = $images($h->root)[0] ?? null;
    check($h->byText('00:01') !== null, 'over the bar at three quarters: the time there');
    check($peek !== null && [round($peek->w), round($peek->h)] === [120.0, 68.0], 'and its thumbnail, 120 points wide');
    $h->hover(100, 100); $h->frame();
    check($images($h->root) === [] && $h->byText('00:01') === null, 'off the bar, neither');
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
    // The A-B loop (Cmd+L, IINA's): A, then B, which loops (from A at once, at B), then none, told as it goes; their knobs on the bar.
    $p->seek(0.5);
    $h->shortcut('l', Key::COMMAND); $h->frame();
    same([0.5, null, 'AB-Loop: A'], [round($s->loopA, 3), $s->loopB, $s->message[0]], 'Cmd+L: A where it is');
    $p->seek(1.2);
    $h->shortcut('l', Key::COMMAND); $h->frame();
    same([0.5, 1.2, 'AB-Loop: B', 0.5], [round($s->loopA, 3), round($s->loopB, 3), $s->message[0], round($p->time(), 3)], 'again: B, and from A at once');
    check($h->byText('AB-Loop: B') !== null, 'told');
    $p->play(); $run(1.2); $at = $p->time(); $p->pause();
    check($at >= 0.5 && $at < 1.25, "it plays from A again at B: at $at after 1.2 s");
    $h->shortcut('l', Key::COMMAND); $h->frame();
    same([null, null, 'AB-Loop: Cleared'], [$s->loopA, $s->loopB, $s->message[0]], 'again: none');
    $p->seek(1.0);
    $h->key(Key::PERIOD, 'insertText:', 0, '.'); $h->frame();
    $on = round($p->time(), 3);
    $h->key(Key::LEFT, 'moveLeft:', Key::OPTION); $h->frame();
    same([1.042, 1.0], [$on, round($p->time(), 3)], '. steps a frame on, Option+Left one back');
    $h->key(Key::SPACE, 'insertText:', 0, ' '); $h->frame();
    check($p->playing(), 'Space plays');
    $p->pause();
    // The wheel over the picture, as IINA's settings say by default: up and down the volume (volumeScrollAmount 3: 0.75 a point), across a seek (relativeSeekAmount 3: a quarter of a second a point; at most one a tenth of a second).
    $h->input->push(['scroll', 300.0, 200.0, 0.0, -20.0, true]); $h->frame();
    check(abs($s->volume - 0.80) < 1e-9, 'the wheel down 20 points: the volume 15 lower: ' . $s->volume);
    $s->settings()->set('useExactSeek', 1); // exact, not to the clip's keyframe (IINA's default seeks to keyframes)
    $p->seek(1.5); $from = $p->time();
    $h->input->push(['scroll', 300.0, 200.0, -5.0, 0.0, true]); $h->frame();
    check(abs($p->time() - ($from - 1.25)) < 0.01, 'across 5 points: a second and a quarter back: ' . $p->time());
    $s->settings()->set('useExactSeek', 0);
    // Where it was left noted at a pause, for the next time (more than 5 s in, which a clip of 2 s is not: noted, not gone back to).
    $posFile = sys_get_temp_dir() . '/myphp-positions-player-' . getmypid() . '.json';
    $s2 = new VideoPlayerState(); $s2->thumbnailCache = $thumbCache; $s2->positions = new \MyPHP\Media\PlaybackPositions($posFile);
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
    // The sidebar's nodes keep their ids as the subtitles come and go (the overlays keyed, their nodes scoped): its scroll stays.
    $s3 = new VideoPlayerState(); $s3->thumbnailCache = $thumbCache;
    $p3 = new Player(__DIR__ . '/testdata/clip-subs.mkv'); $s3->setPlayer($p3);
    $p3->setSecondarySubtitleTrack($p3->subtitleTracks()[1]);
    $s3->sidebar = 'subtitles'; $s3->tab = 'subtitles';
    $h3 = new Headless(fn(Context $c) => $c->videoPlayer($s3), 960, 540);
    $ids = [];
    foreach ([0.5, 1.9, 1.1] as $at3) { $p3->seek($at3); $h3->frame(); $ids[] = $h3->byText('Load Subtitle…')?->id; }
    check($ids[0] !== null && count(array_unique($ids)) === 1, 'the sidebar\'s nodes keep their ids as the subtitles, first and secondary, come and go: ' . json_encode($ids));
    // The audio tab's equalizer: a preset chosen from its pop-up sets its gains, of this player and the next; an arrow on a band's slider makes it Manual.
    $s4 = new VideoPlayerState(); $s4->thumbnailCache = $thumbCache;
    $p4 = new Player(__DIR__ . '/testdata/clip.mkv'); $s4->setPlayer($p4);
    $s4->sidebar = 'audio'; $s4->tab = 'audio';
    $h4 = new Headless(fn(Context $c) => $c->videoPlayer($s4), 960, 900);
    $h4->rt->reduceMotion = true;
    $h4->frame();
    $h4->clickText('Manual');
    same(['Save the current EQ as a preset…', '-', 'Manual'], array_slice($h4->menuLabels() ?? [], 0, 3), "the pop-up: saving, then Manual (IINA's; no profile yet, no renaming or removing)");
    check(in_array('Rock', $h4->menuLabels() ?? [], true), "then IINA's presets");
    $h4->chooseMenuItem('Rock');
    same([array_map('floatval', VideoPlayerState::EQUALIZER_PRESETS['Rock']), 'Rock'], [$p4->equalizer(), $s4->equalizerPreset], 'Rock chosen: its gains the player\'s');
    check($h4->byText('Rock') !== null, 'the pop-up says it');
    $band = $h4->byLabel('1k Hz');
    $h4->rt->focus = $band->id; $h4->up(); $h4->frame();
    check($s4->equalizerPreset === '' && $p4->equalizer()[5] > VideoPlayerState::EQUALIZER_PRESETS['Rock'][5], 'an arrow up the 1 kHz band: higher, and Manual: ' . $p4->equalizer()[5]);
    // The gains saved as a profile of the user's: chosen, the pop-up shows it, and renaming and removing it; another's gains, then it again.
    $s4->profiles = new \MyPHP\Media\EqualizerProfiles($eqFile = sys_get_temp_dir() . '/myphp-eq-profiles-ui-' . getmypid() . '.json'); // not the user's
    @unlink($eqFile);
    $mine = $p4->equalizer();
    $s4->saveProfile('Mine'); $h4->frame();
    same(['Mine', ''], [$s4->equalizerProfile, $s4->equalizerPreset], 'saved: the profile chosen');
    check($h4->byText('Mine') !== null, 'the pop-up says it');
    $h4->clickText('Mine');
    $labels = $h4->menuLabels() ?? [];
    check(in_array('Rename the current EQ preset…', $labels, true) && in_array('Remove the current EQ preset', $labels, true) && end($labels) === 'Mine', 'its pop-up: renaming and removing it, and it the last: ' . json_encode($labels));
    $h4->chooseMenuItem('Pop');
    same('', $s4->equalizerProfile, 'a preset chosen: no profile');
    $h4->clickText('Pop'); $h4->chooseMenuItem('Mine');
    same([$mine, 'Mine'], [$p4->equalizer(), $s4->equalizerProfile], 'the profile chosen: its gains again');
    $s4->renameProfile('Ours');
    same(['Ours'], $s4->profileNames(), 'renamed');
    $s4->removeProfile(); $h4->frame();
    same([[], '', ''], [$s4->profileNames(), $s4->equalizerProfile, $s4->equalizerPreset], 'removed: Manual, the gains as they were');
    check($h4->byText('Manual') !== null, 'the pop-up says Manual');
    unlink($eqFile);
    $p5 = new Player(__DIR__ . '/testdata/clip.mp4'); $s4->setPlayer($p5);
    same($p4->equalizer(), $p5->equalizer(), 'the next player takes the gains');
    // The video tab's hardware decoding, as IINA's quick setting: an AV1 film switched to libdav1d as it plays and back, of the next film too;
    // a film the system alone decodes here (H.264), on and fixed.
    if (\MyPHP\Native\Dav1d::unavailable() === null && (new Player(__DIR__ . '/testdata/clip-av1.mkv'))->decodingSwitchable()) { // libdav1d and this Mac's AV1
        $s6 = new VideoPlayerState(); $s6->thumbnailCache = $thumbCache;
        $p6 = new Player(__DIR__ . '/testdata/clip-av1.mkv'); $s6->setPlayer($p6);
        $s6->sidebar = 'video'; $s6->tab = 'video';
        $h6 = new Headless(fn(Context $c) => $c->videoPlayer($s6), 960, 900);
        $h6->rt->reduceMotion = true;
        $h6->frame();
        $was = $p6->hardwareDecoding();
        $h6->clickOn($h6->byLabel('Hardware Decoding'));
        same([!$was, !$was], [$p6->hardwareDecoding(), $s6->hardwareDecoding], 'switched: the film decodes the other way');
        $p6->play(); $run(0.5); $p6->pause();
        check($p6->out->failure() === null && $p6->time() > 0.2, 'it plays on so: ' . ($p6->out->failure() ?? round($p6->time(), 2) . ' s'));
        $h6->clickOn($h6->byLabel('Hardware Decoding'));
        same($was, $p6->hardwareDecoding(), 'and back');
        $h6->clickOn($h6->byLabel('Hardware Decoding'));
        $p7 = new Player(__DIR__ . '/testdata/clip-av1.mp4'); $s6->setPlayer($p7);
        same(!$was, $p7->hardwareDecoding(), 'the next film takes it');
        $p8 = new Player(__DIR__ . '/testdata/clip.mkv'); $s6->setPlayer($p8); $h6->frame();
        $h6->clickOn($h6->byLabel('Hardware Decoding'));
        check(!$p8->decodingSwitchable() && $p8->hardwareDecoding() && $h6->byLabel('Hardware Decoding')?->checked === 2, 'an H.264 film, which the system alone decodes here: the switch on, fixed');
    }
    // Online subtitles (IINA's Find Online Subtitles): Search Online disabled of no key (''); the list of those found, a row each,
    // a click choosing one, Command another, Cancel taking it away (no request made: OpenSubtitles' own in opensubtitles_test, offline).
    $s9 = new VideoPlayerState(); $s9->thumbnailCache = $thumbCache;
    $p9 = new Player(__DIR__ . '/testdata/clip.mkv'); $s9->setPlayer($p9);
    $s9->sidebar = 'subtitles'; $s9->tab = 'subtitles';
    $h9 = new Headless(fn(Context $c) => $c->videoPlayer($s9, new \MyPHP\UI\VideoPlayerOptions(openSubtitlesKey: '')), 960, 900); // none: no request made
    $h9->rt->reduceMotion = true;
    $h9->frame();
    $h9->clickText('Search Online…');
    same([null, false, null], [$s9->openSubtitles, $s9->searchingOnline, $s9->onlineSubtitles], 'no key: Search Online does nothing');
    $row = fn(int $file, string $name) => ['file' => $file, 'name' => $name, 'language' => 'en', 'year' => 2019, 'fps' => 23.976, 'downloads' => 12, 'uploaded' => '2024-05-01T10:00:00Z'];
    $s9->onlineSubtitles = [$row(1, 'Film.en.srt'), $row(2, 'Film.forced.en.srt')];
    $h9->frame();
    check($h9->byText('2 subtitles found.') !== null && $h9->byText('Film.forced.en.srt') !== null && $h9->byText("en  (2019)  23.98 fps  \u{2b07}12") !== null, 'the list: how many, each its name over its language, year, fps and downloads');
    $h9->clickText('Film.en.srt');
    same([0], $s9->onlineChosen, 'a click chooses one');
    $h9->clickOn($h9->byText('Film.forced.en.srt'), \MyPHP\UI\Key::COMMAND);
    same([0, 1], $s9->onlineChosen, 'Command another too');
    $h9->clickText('CANCEL');
    same([null, []], [$s9->onlineSubtitles, $s9->onlineChosen], 'Cancel: the list gone');
    check($p->playing() && !$p->awake(), 'without keepAwake: the display may sleep while it plays');
    $p->pause();
    @unlink($scratch);
    // Opening films: a player of each, the playlist of its folder; the next at the end of one; the playlist's sidebar, a double click playing a row.
    $s3 = new VideoPlayerState(); $s3->thumbnailCache = $thumbCache; $s3->positions = new \MyPHP\Media\PlaybackPositions($scratch);
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
    // ASS lines over the picture where they are, as libass puts them: the point of \an5 at \pos (1440, 540 of 1920 by 1080), the top line within its margin, the bottom rows above theirs.
    $s4 = new VideoPlayerState(); $s4->thumbnailCache = $thumbCache; $s4->positions = new \MyPHP\Media\PlaybackPositions($scratch);
    $s4->setPlayer(new Player(__DIR__ . '/testdata/clip-ass.mkv'));
    $s4->player->seek(1.0);
    $h4 = new Headless(fn(Context $c) => $c->videoPlayer($s4), 960, 540);
    $h4->frame(); $run(0.3); $h4->frame();
    $all = function (?\MyPHP\UI\Node $n, string $text) use (&$all): array { // the text's nodes: its shadow's and outline's copies, then the text, drawn last
        if ($n === null) { return []; }
        $out = $n->kind === \MyPHP\UI\Node::RICH && $n->plainText() === $text ? [$n] : [];
        foreach ($n->kids as $k) { array_push($out, ...$all($k, $text)); }
        return $out;
    };
    $copies = $all($h4->root, 'At three quarters');
    [$x, $y, $w, $h] = $h4->rect(end($copies));
    check(abs($x + $w / 2 - 720) < 2 && abs($y + $h / 2 - 270) < 2, "\\an5 at \\pos: centered on (720, 270): " . round($x + $w / 2, 1) . ', ' . round($y + $h / 2, 1));
    same(1, count($copies), 'once, its outline and shadow its glyphs\' own');
    $ts = end($copies)->textStyle();
    same([[3.0, 0x008000, 1.0], [1.5, 1.5, 0x000000, 0.5]], [array_map(fn($v) => is_float($v) ? round($v, 2) : $v, $ts->outline ?? []), array_map(fn($v) => is_float($v) ? round($v, 2) : $v, $ts->textShadow ?? [])], '\\bord6 in \\3c green, three points here; its style\'s Shadow 3 in its BackColour at half');
    $box = $all($h4->root, 'A box behind');
    same(1, count($box), 'BorderStyle 3: a box behind, no copies');
    $top = $all($h4->root, 'Top: yellow, bold, dark blue outline');
    [, $ty] = $h4->rect(end($top) ?: throw new \RuntimeException('no top line'));
    check(abs($ty - 20) < 2, "the top line within its margin, 40 of 1080: at $ty");
    $second = $all($h4->root, '第二行 粗体 下划线');
    [, $by, , $bh] = $h4->rect(end($second) ?: throw new \RuntimeException('no second row'));
    check(abs($by + $bh - 510) < 4, 'the bottom row on its margin, 60 of 1080: ends at ' . round($by + $bh, 1));
    $s4->player->pause();
    // ASS that moves: \move halfway at 1 s of 2, its center at (960, 120) of 1920 by 1080; frames asked for while it moves.
    $s4->setPlayer(new Player(__DIR__ . '/testdata/clip-ass-motion.mkv'));
    $s4->player->seek(1.0);
    $h4->frame(); $run(0.3); $h4->frame();
    $moving = $all($h4->root, 'Moving across');
    $mn = end($moving) ?: throw new \RuntimeException('no moving line');
    [$x, $y, $w, $h] = $h4->rect($mn);
    check(abs($x + $w / 2 - 150) < 2 && abs($y + $h / 2 - 60) < 2, '\\move: laid out at its start, centered on (150, 60): ' . round($x + $w / 2, 1) . ', ' . round($y + $h / 2, 1));
    [$cx, $cy] = $mn->world->point($x + $w / 2, $y + $h / 2);
    check(abs($cx - 480) < 2 && abs($cy - 60) < 2, '\\move halfway at 1 s: shown centered on (480, 60), moved by its transform: ' . round($cx, 1) . ', ' . round($cy, 1));
    check($h4->rt->nextWake() !== null, 'a frame asked for while it moves');
    $s4->player->pause();
    // ASS's drawings, 3D turns, shears, blurs and \kf (a file of them, 1920 by 1080 on 960 by 540): as libass draws them.
    $fx = sys_get_temp_dir() . '/myphp-ass-fx-' . getmypid() . '.ass';
    file_put_contents($fx, "[Script Info]\nPlayResX: 1920\nPlayResY: 1080\n\n[V4+ Styles]\n"
        . "Format: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding\n"
        . "Style: Default,Arial,60,&H00FFFFFF,&H000000FF,&H00000000,&H00000000,0,0,0,0,100,100,0,0,1,0,0,2,40,40,40,1\n"
        . "Style: Box,Arial,60,&H00FFFFFF,&H000000FF,&H00000000,&H00000000,0,0,0,0,100,100,0,0,3,4,0,2,40,40,40,1\n\n[Events]\n"
        . "Format: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text\n"
        . "Dialogue: 0,0:00:00.00,0:00:04.00,Default,,0,0,0,,{\\an7\\pos(100,100)\\p1}m 0 0 l 200 0 200 100 0 100{\\p0}\n"
        . "Dialogue: 0,0:00:00.00,0:00:04.00,Default,,0,0,0,,{\\an5\\pos(960,540)\\fry40}Turned\n"
        . "Dialogue: 0,0:00:00.00,0:00:04.00,Default,,0,0,0,,{\\an8\\pos(960,100)\\fax0.5}Sheared\n"
        . "Dialogue: 0,0:00:00.00,0:00:04.00,Default,,0,0,0,,{\\blur4}Blurred\n"
        . "Dialogue: 0,0:00:00.00,0:00:04.00,Default,,0,0,0,,{\\an7\\pos(100,700)\\kf200}Swept\n"
        . "Dialogue: 0,0:00:00.00,0:00:04.00,Default,,0,0,0,,{\\an5\\pos(960,800)\\frz10}Left{\\frz-10}Right\n"
        . "Dialogue: 0,0:00:00.00,0:00:04.00,Default,,0,0,0,,{\\an7\\pos(100,900)\\bord4\\1a&H80&}Clear\n"
        . "Dialogue: 0,0:00:00.00,0:00:04.00,Box,,0,0,0,,{\\an7\\pos(1000,900)}Boxed\n"
        . "Dialogue: 0,0:00:00.00,0:00:04.00,Default,,0,0,0,,{\\an7\\pos(100,300)\\iclip(0,0,1920,320)}Cut\n"
        . "Dialogue: 0,0:00:00.00,0:00:04.00,Default,,0,0,0,,{\\an7\\pos(100,400)\\clip(m 0 0 l 1920 0 1920 420 0 420)}Masked\n"
        . "Dialogue: 0,0:00:00.00,0:00:04.00,Default,,0,0,0,,{\\an7\\pos(1000,600)}Base{\\p1}m 0 0 l 22 0 22 22 0 22\n"
        . "Dialogue: 0,0:00:00.00,0:00:04.00,Default,,0,0,0,,{\\an7\\pos(100,1000)\\fay0.2}Stairs\n");
    $s4->player->loadSubtitles($fx);
    $s4->player->seek(1.0);
    $h4->frame(); $h4->frame();
    $drawn = function (?\MyPHP\UI\Node $n) use (&$drawn): array {
        if ($n === null) { return []; }
        $out = $n->draw !== null ? [$n] : [];
        foreach ($n->kids as $k) { array_push($out, ...$drawn($k)); }
        return $out;
    };
    $boxes = array_map(fn($n) => array_map(fn($v) => round($v, 1), $h4->rect($n)), array_filter($drawn($h4->root), fn($n) => abs($n->w - 100) < 0.5 && abs($n->h - 50) < 0.5));
    same([[50.0, 50.0, 100.0, 50.0]], array_values($boxes), 'a drawing: a box its points span, its top left at \\an7\\pos');
    $found = $all($h4->root, 'Turned'); $turned = end($found) ?: throw new \RuntimeException('no turned line');
    [$x, $y, $w, $h] = $h4->rect($turned);
    [, $ly1] = $turned->world->point($x, $y); [, $ly2] = $turned->world->point($x, $y + $h);
    [, $ry1] = $turned->world->point($x + $w, $y); [, $ry2] = $turned->world->point($x + $w, $y + $h);
    check($ry2 - $ry1 < $ly2 - $ly1 - 0.01, '\\fry40: its right side away, shorter in perspective: ' . round($ly2 - $ly1, 2) . ' and ' . round($ry2 - $ry1, 2));
    $found = $all($h4->root, 'Sheared'); $sheared = end($found) ?: throw new \RuntimeException('no sheared line');
    [$x, $y, $w, $h] = $h4->rect($sheared);
    [$tx] = $sheared->world->point($x, $y); [$bx] = $sheared->world->point($x, $y + $h);
    check(abs(($bx - $tx) - 0.5 * $h) < 0.5, '\\fax0.5: its foot half its height to the right of its top: ' . round($bx - $tx, 2) . ' of ' . round($h, 2));
    $found = $all($h4->root, 'Blurred'); $blurred = end($found) ?: throw new \RuntimeException('no blurred line');
    same(round(4 * 0.5 * 2 / sqrt(log(256)), 3), round($blurred->textStyle()->blur ?? 0.0, 3), '\\blur4: a Gaussian of libass\'s sigma, 2 / sqrt(ln 256) of its units');
    $swept = $all($h4->root, 'Swept');
    same(2, count($swept), '\\kf: the syllable in the secondary, the primary over it');
    $parentOf = function (\MyPHP\UI\Node $n, \MyPHP\UI\Node $in) use (&$parentOf): ?\MyPHP\UI\Node {
        foreach ($in->kids as $k) { if ($k === $n) { return $in; } if (($p = $parentOf($n, $k)) !== null) { return $p; } }
        return null;
    };
    [, , $sw] = $h4->rect($swept[0]);
    [, , $cw] = $h4->rect($parentOf($swept[1], $h4->root));
    check(abs($cw - $sw / 2) < 2, 'half way through its two seconds: the primary cut to half its glyphs: ' . round($cw, 1) . ' of ' . round($sw, 1));
    same(2, count($all($h4->root, 'LeftRight')), 'runs turned apart: the line laid out for each, as libass turns each glyph');
    $found = $all($h4->root, 'Clear'); $clear = end($found) ?: throw new \RuntimeException('no clear line');
    same([true, false], $clear->textStyle()->cutOut, 'a fill half clear: the glyphs cut out of its outline (libass\'s ass_fix_outline)');
    $found = $all($h4->root, 'Boxed'); $boxed = end($found) ?: throw new \RuntimeException('no boxed line');
    $holder = $parentOf($boxed, $h4->root);
    check($holder !== null && $holder->kids[0]->draw !== null && $holder->kids[0] !== $boxed, 'BorderStyle 3: a box drawn behind its line');
    $masks = function (?\MyPHP\UI\Node $n) use (&$masks): array {
        if ($n === null) { return []; }
        $out = $n->clipMask !== null ? [[$n->clipMask[0] === null ? null : 'image', $n->clipMask[1]]] : [];
        foreach ($n->kids as $k) { array_push($out, ...$masks($k)); }
        return $out;
    };
    same([[null, true], ['image', false]], $masks($h4->root), '\\iclip of a rectangle: the box itself cut away; \\clip of a drawing: its mask');
    $found = $all($h4->root, 'Base'); [, $ty, , $th] = $h4->rect(end($found));
    $squares = array_values(array_filter($drawn($h4->root), fn($n) => abs($n->w - 11) < 0.5 && abs($n->h - 11) < 0.5));
    [, $dy, , $dh] = $h4->rect($squares[0] ?? throw new \RuntimeException('no square'));
    check($ty + $th - ($dy + $dh) > 2, 'a drawing beside text: its foot on the text\'s baseline, above the text\'s descent: ' . round($ty + $th - ($dy + $dh), 1));
    same([1, 1, 1, 1, 1, 1, 1], array_map(fn(string $t) => count($all($h4->root, $t)), ['Stairs', 'S', 't', 'a', 'i', 'r', 's']), '\\fay: each glyph sheared on its own (libass\'s of its own x), laid out alone, over the text laid out once');
    @unlink($fx);
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
    // The system's Now Playing (VideoPlayerOptions::$nowPlaying): the film, its chapter and rate (0 while paused) as it changes; ended without it.
    $nowPlaying = function (): array {
        $center = ObjC::msg('p:', ObjC::cls('MPNowPlayingInfoCenter'), 'defaultCenter');
        $info = ObjC::msg('p:', $center, 'nowPlayingInfo');
        $out = ['state' => ObjC::msg('L:', $center, 'playbackState')];
        if ($info === null) { return $out; }
        $keys = ObjC::msg('p:', $info, 'allKeys');
        for ($i = 0, $n = ObjC::msg('L:', $keys, 'count'); $i < $n; $i++) {
            $k = ObjC::msg('p:L', $keys, 'objectAtIndex:', $i);
            $out[ObjC::phpStr($k)] = ObjC::phpStr(ObjC::msg('p:', ObjC::msg('p:p', $info, 'objectForKey:', $k), 'description'));
        }
        return $out;
    };
    $np = $nowPlaying();
    same(['clip-chapters', '3', '2', '0', 2], [$np['title'] ?? null, $np['MPNowPlayingInfoPropertyChapterCount'] ?? null, $np['MPNowPlayingInfoPropertyChapterNumber'] ?? null, $np['MPNowPlayingInfoPropertyPlaybackRate'] ?? null, $np['state']], 'Now Playing: the film, its chapters, paused');
    $s3->player->play();
    $np = $nowPlaying();
    same(['1', 1], [$np['MPNowPlayingInfoPropertyPlaybackRate'] ?? null, $np['state']], 'and playing, told by the player');
    $s3->player->pause();
    $off3 = new Headless(fn(Context $c) => $c->videoPlayer($s3, new VideoPlayerOptions(nowPlaying: false)), 960, 540);
    $off3->frame();
    same(['state' => 3], $nowPlaying(), 'without it: nothing in Now Playing, stopped');
    @unlink($scratch);
    $s3->setPlayer(new Player(__DIR__ . '/testdata/clip.mkv')); // the folder's films let go before it goes
    foreach (glob("$films/*") ?: [] as $f) { unlink($f); }
    rmdir($films);
    foreach (scandir($thumbDir) ?: [] as $f) { if ($f[0] !== '.') { unlink("$thumbDir/$f"); } }
    rmdir($thumbDir);

    // Music mode (IINA's mini player): an audio file switches to it (autoSwitchToMusicMode), its title, artist and album shown, the window asked
    // to be 300 wide, the picture square (no cover: the art of its own), the controls 72 under it; back by hand, a film then leaves it so.
    $m = new VideoPlayerState();
    $m->setPlayer(new Player(__DIR__ . '/testdata/clip-tags.mka'));
    check($m->musicMode, 'an audio file: music mode');
    $mh = new Headless(fn(Context $c) => $c->videoPlayer($m), 300, 372);
    $mh->frame();
    check($mh->byText('A Song') !== null && $mh->byText('The Band - The Album') !== null, 'its tags: ' . json_encode($mh->texts()));
    same([[300, 372, 372, 372], false], [$mh->rt->windowAsks['compact'] ?? null, $mh->rt->windowAsks['buttons'] ?? null], 'the window 300 by 300 + 72, of that height, its traffic lights hidden');
    $m->setSetting('musicModeShowPlaylist', true); $mh->frame();
    same([300, 672, 572, 0], $mh->rt->windowAsks['compact'] ?? null, 'the playlist shown: 300 more, at least 200');
    $m->setSetting('musicModeShowPlaylist', false);
    $m->toggleMusicMode(); $mh->frame();
    check(!$m->musicMode && array_key_exists('compact', $mh->rt->windowAsks) && $mh->rt->windowAsks['compact'] === null, 'back to the video\'s window: its own size');
    $m->setPlayer(new Player(__DIR__ . '/testdata/clip-tags.mka'));
    check(!$m->musicMode, 'switched by hand: the next audio file leaves it so');
    $m2 = new VideoPlayerState();
    $m2->setPlayer(new Player(__DIR__ . '/testdata/clip-tags.mka'));
    $m2->setPlayer(new Player(__DIR__ . '/testdata/clip.mkv'));
    check(!$m2->musicMode, 'a film after the audio: out of it');
}

