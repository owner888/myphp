<?php
// IINA's other sources of subtitles online, without the network: assrt.net
// (Media\Assrt: a search's and a detail's responses as IINA reads them, its
// tokens), shooter.cn (Media\Shooter: a file's hash, a search's response, a
// file's name); the source the settings choose (onlineSubProvider), the
// menus' items of each, Search Online of a film from those of no key, the
// list of assrt.net's found.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Media\{Assrt, Player, PlayerSettings, Shooter};
use MyPHP\MenuItem;
use MyPHP\UI\{Context, MediaPlayerMenus, MediaPlayerState};

// assrt.net: a search's subtitles, IINA's "[No title]" and "Unknown"; none of `sub: {subs: {}}`; a detail's files, its archive's else its own.
same([
    ['id' => 645859, 'name' => 'Dune 2021/DUNE/沙丘瀚战', 'about' => 'SSA 英 简', 'uploaded' => '2021-12-13 16:30:49'],
    ['id' => 645712, 'name' => '[No title]', 'about' => 'Unknown 简', 'uploaded' => '2021-12-05 10:25:21'],
], Assrt::results(['status' => 0, 'sub' => ['result' => 'succeed', 'subs' => [ // as assrt.net answers now: its site's fields
    ['fileid' => '645859', 'video_chinese_name' => 'Dune 2021/DUNE/沙丘瀚战', 'videoname' => '沙丘2021/Dune2021.1440p.Bluray', 'm_subtype' => 'SSA', 'm_lang' => '英&nbsp;简', 'uploadtime' => '2021-12-13 16:30:49', 'sub_name' => 'Dune.zip'],
    ['fileid' => '645712', 'video_chinese_name' => '', 'm_lang' => '简', 'uploadtime' => '2021-12-05 10:25:21'],
]]]), 'a search\'s subtitles: the native name, the kind and language (its &nbsp; a space), the time uploaded; IINA\'s "[No title]" and "Unknown"');
same([['id' => 645859, 'name' => 'Dune 2021/DUNE/沙丘瀚战', 'about' => 'SSA 英 简', 'uploaded' => '2021-12-13 16:30:49'], ['id' => 7, 'name' => '[No title]', 'about' => 'Unknown ', 'uploaded' => '']],
    Assrt::results(['status' => 0, 'sub' => ['subs' => [ // or in its API's documented fields, IINA's
        ['id' => 645859, 'native_name' => 'Dune 2021/DUNE/沙丘瀚战', 'subtype' => 'SSA', 'lang' => ['desc' => '英 简', 'langlist' => ['langeng' => true]], 'upload_time' => '2021-12-13 16:30:49'],
        ['id' => 7, 'native_name' => ''],
    ]]]), 'the same of the API\'s fields: assrt.net answers in either');
$threw = false;
try { Assrt::results(['sub' => ['subs' => [['name' => 'x']]]]); } catch (\RuntimeException $e) { $threw = str_contains($e->getMessage(), 'wrongResponseFormat'); }
check($threw, 'a row of neither: IINA\'s wrongResponseFormat');
same([], Assrt::results(['status' => 0, 'sub' => ['subs' => (object)[]]]), 'none: assrt.net\'s empty object');
same([], Assrt::results(json_decode('{"status":0,"sub":{"subs":{}}}', true)), 'as JSON decodes it');
same([['https://file.assrt.net/a/1.ass', 'Dune.chs.ass'], ['https://file.assrt.net/a/2.srt', 'Dune.eng.srt']],
    Assrt::filesOf(['sub' => ['subs' => [['url' => 'https://file.assrt.net/a.rar', 'filename' => 'a.rar', 'filelist' => [['url' => 'https://file.assrt.net/a/1.ass', 'f' => 'Dune.chs.ass'], ['url' => 'https://file.assrt.net/a/2.srt', 'f' => 'Dune.eng.srt']]]]]]),
    'a detail\'s files: those of its archive');
same([['https://file.assrt.net/b.srt', 'b.srt']], Assrt::filesOf(['sub' => ['subs' => [['url' => 'https://file.assrt.net/b.srt', 'filename' => 'b.srt']]]]), 'else its own');
$threw = false;
try { Assrt::filesOf(['sub' => ['subs' => []]]); } catch (\RuntimeException $e) { $threw = str_contains($e->getMessage(), 'wrongResponseFormat'); }
check($threw, 'a detail of no subtitle: IINA\'s wrongResponseFormat');
same([true, false, false], [Assrt::valid(str_repeat('a', 32)), Assrt::valid(''), Assrt::valid('short')], 'a user\'s token: 32 characters');

// shooter.cn: the MD5s of 4 KB at 4096, two thirds, a third and 8 KB before the end; none of a file under 12 KB; one that cannot be read throws.
$file = sys_get_temp_dir() . '/myphp-shooter-' . getmypid() . '.bin';
$bytes = implode('', array_map(fn(int $i) => chr(($i * 31 + ($i >> 7)) & 255), range(0, 99999)));
file_put_contents($file, $bytes);
$at = fn(int $o) => md5(substr($bytes, $o, 4096));
same(implode(';', [$at(4096), $at(66666), $at(33333), $at(100000 - 8192)]), Shooter::hash($file), 'the hash: four MD5s, joined by ";"');
file_put_contents($file, str_repeat('x', Shooter::LEAST - 1));
same(null, Shooter::hash($file), 'none of a file smaller than 12 KB');
unlink($file);
$threw = false;
try { Shooter::hash($file); } catch (\RuntimeException) { $threw = true; }
check($threw, 'a file that cannot be read throws');
same([['desc' => 'chs', 'delay' => 0, 'files' => [['ext' => 'srt', 'link' => 'https://file0.shooter.cn/a.srt']]]],
    Shooter::results([['Desc' => 'chs', 'Delay' => 0, 'Files' => [['Ext' => 'srt', 'Link' => 'https://file0.shooter.cn/a.srt']]], ['Desc' => 'none', 'Files' => []]]), 'a search\'s subtitles, those of no file left out');
same([[], []], [Shooter::results(null), Shooter::results(['a' => 1])], 'none where the answer is no list (shooter.cn\'s "\xff")');
same(['影片.chs.srt', 'Film.srt', 'a.ass'], [Shooter::fileName("attachment; filename*=UTF-8''%E5%BD%B1%E7%89%87.chs.srt", 'https://x/y'), Shooter::fileName('attachment; filename="Film.srt"', 'https://x/y'), Shooter::fileName('', 'https://file0.shooter.cn/sub/a.ass?x=1')],
    'a file\'s name: the server\'s, else the link\'s');

// The source the settings choose: opensubtitles.com at first, and of one unknown; the menus' title and items of each, by IINA's names.
$settings = sys_get_temp_dir() . '/myphp-onlinesub-' . getmypid() . '.json';
@unlink($settings);
$s = new MediaPlayerState(); $s->playerSettings = new PlayerSettings($settings);
same([':opensubtitles', ''], [$s->onlineProvider(), $s->settings()->get('assrtToken')], 'opensubtitles.com at first, no token');
$find = function (array $items, string $id) use (&$find): ?MenuItem {
    foreach ($items as $it) {
        if ($it->id === $id) { return $it; }
        if (($f = $find($it->submenu, $id)) !== null) { return $f; }
    }
    return null;
};
$menus = MediaPlayerMenus::menus($s);
same(['opensubtitles.com', 'assrt.net', 'shooter.cn', ''], array_map(fn(MenuItem $m) => $m->label, $find($menus, 'iina.onlineSubSource')->submenu), 'Find Online Subtitles from: IINA\'s three, a line before the plugins\' (none here)');
same('Find Online Subtitles from opensubtitles.com', $find($menus, 'iina.findOnlineSub')->label, 'Find Online Subtitles, from the source chosen');
$s->setSetting('onlineSubProvider', ':assrt');
same('Find Online Subtitles from assrt.net', $find(MediaPlayerMenus::menus($s), 'iina.findOnlineSub')->label, 'assrt.net chosen');
$s->setSetting('onlineSubProvider', ':gone');
same(':opensubtitles', $s->onlineProvider(), 'a source unknown: opensubtitles.com, as IINA\'s');

if (PHP_OS_FAMILY === 'Darwin') {
    // A film: Search Online of assrt.net and shooter.cn though OpenSubtitles has no key; the list of assrt.net's found, a row each of its kind and language.
    $s->setSetting('onlineSubProvider', ':assrt');
    $s->setPlayer(new Player(__DIR__ . '/testdata/clip.mkv'));
    $s->openSubtitles = null;
    same([false, true, true, true], [$s->canSearchOnline(':opensubtitles'), $s->canSearchOnline(':assrt'), $s->canSearchOnline(':shooter'), $s->canSearchOnline()], 'of no key OpenSubtitles alone not searched');
    $menus = MediaPlayerMenus::menus($s);
    same([false, true, true, true], array_map(fn(MenuItem $m) => !$m->disabled, $find($menus, 'iina.onlineSubSource')->submenu), 'its item alone disabled (the line after them none)');
    $s->sidebar = 'subtitles'; $s->tab = 'subtitles';
    $s->onlineSource = ':assrt';
    $s->onlineSubtitles = Assrt::results(['sub' => ['subs' => [
        ['fileid' => '1', 'video_chinese_name' => 'Clip 简体', 'm_subtype' => 'SRT', 'm_lang' => '简', 'uploadtime' => '2021-10-22 10:11:12'],
        ['fileid' => '2', 'video_chinese_name' => 'Clip 繁體', 'm_subtype' => 'ASS', 'm_lang' => '繁', 'uploadtime' => '2021-10-23 10:11:12'],
    ]]]);
    $h = new Headless(fn(Context $c) => $c->mediaPlayer($s, new \MyPHP\UI\MediaPlayerOptions(openSubtitlesKey: '')), 960, 900);
    $h->rt->reduceMotion = true;
    $h->frame();
    check($h->byText('2 subtitles found.') !== null && $h->byText('Clip 繁體') !== null && $h->byText('ASS 繁') !== null, 'the list of assrt.net\'s: each its name over its kind and language');
    $h->clickText('Clip 简体');
    same([0], $s->onlineChosen, 'a click chooses one');
    $s->cancelOnlineSubtitles();

    // The settings' Subtitle source of assrt.net: its API token, typed and kept; no OpenSubtitles account under it.
    $w = new Headless(fn(Context $c) => $c->mediaPlayerSettings($s), 600, 1600); // tall enough for Online Subtitles
    $w->frame();
    $w->clickOn($w->byText('Subtitles')); $w->frame();
    check($w->byText('assrt.net') !== null && $w->byText('Assrt API token') !== null && $w->byText('Login') === null, 'assrt.net\'s token under its source, no account');
    $fields = function (\MyPHP\UI\Node $n) use (&$fields): array {
        $all = $n->kind === \MyPHP\UI\Node::INPUT ? [$n] : [];
        foreach ($n->kids as $k) { $all = [...$all, ...$fields($k)]; }
        return $all;
    };
    $label = $w->rect($w->byText('Assrt API token'));
    $field = array_values(array_filter($fields($w->frame()), fn($f) => abs(($r = $w->rect($f))[1] + $r[3] / 2 - $label[1] - $label[3] / 2) < 6))[0] ?? null;
    check($field !== null, 'its field beside it');
    if ($field !== null) { $w->clickOn($field); $w->frame(); }
    $w->type(str_repeat('a', 32)); $w->frame();
    same(str_repeat('a', 32), $s->settings()->get('assrtToken'), 'the token typed: kept');
}
@unlink($settings);
