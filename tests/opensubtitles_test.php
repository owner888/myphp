<?php
// OpenSubtitles' REST API as IINA's OpenSubClient uses it (Media\OpenSubtitles),
// without the network: a file's hash (its wiki's HashSourceCodes, against
// Python's 64-bit sums), the system's languages as its codes, a search's
// response as the rows IINA lists; the settings of IINA's Settings window
// the player has (Media\PlayerSettings), the window's search, and, on
// macOS, the window (UI\MoviePlayerSettings); what a file's
// name tells of its film, to look it up on TMDB by (Media\MetaVideo, of
// agc_player's names).
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Media\{MetaVideo, OpenSubtitles, PlayerSettings};
use MyPHP\UI\{Context, MoviePlayerSettings, MoviePlayerSettingsPages, MoviePlayerState};

$file = sys_get_temp_dir() . '/myphp-hash-' . getmypid() . '.bin';
$n = 200003;
file_put_contents($file, implode('', array_map(fn(int $i) => chr((($i * 7 + 3) * ($i >> 8) + $i) & 255), range(0, $n - 1))));
same('101030b010139d43', OpenSubtitles::hash($file), 'the hash of a file: its size and the 64-bit words of its first and last 64 KB, wrapping (Python\'s)');
file_put_contents($file, str_repeat("\xff", 131072));
same(null, OpenSubtitles::hash($file), 'none of a file of 128 KB or less, as OpenSubtitles hashes none');
unlink($file);

$supported = ['en', 'zh-cn', 'zh-tw', 'pt-br', 'pt-pt', 'ja', 'fr'];
same(['zh-cn', 'en'], OpenSubtitles::languagesOf(['zh-Hans-SG', 'en-SG', 'en-US'], $supported), 'Chinese of the simplified script as zh-cn, English once');
same(['zh-tw', 'pt-br', 'pt-pt'], OpenSubtitles::languagesOf(['zh-Hant-TW', 'pt-BR', 'pt'], $supported), 'zh-tw of the traditional, pt-br of Brazil\'s, pt-pt else');
same(['en'], OpenSubtitles::languagesOf(['tlh', 'xx-YY'], $supported), 'English where it has none of them, as IINA\'s');

$response = ['total_count' => 3, 'data' => [
    ['id' => '1', 'type' => 'subtitle', 'attributes' => ['language' => 'en', 'download_count' => 1234, 'fps' => 23.976, 'upload_date' => '2024-05-01T10:00:00Z',
        'feature_details' => ['year' => 2019], 'files' => [['file_id' => 42, 'file_name' => 'Film.2019.en.srt']]]],
    ['id' => '2', 'type' => 'subtitle', 'attributes' => ['language' => 'zh-cn', 'files' => []]], // no file: left out
    ['id' => '3', 'type' => 'feature', 'attributes' => []], // no subtitle: left out
]];
same([['file' => 42, 'name' => 'Film.2019.en.srt', 'language' => 'en', 'year' => 2019, 'fps' => 23.976, 'downloads' => 1234, 'uploaded' => '2024-05-01T10:00:00Z']],
    (new \ReflectionMethod(OpenSubtitles::class, 'results'))->invoke(null, $response), 'a search\'s subtitles, those of no file or no subtitle left out, as IINA\'s');

// The names of files agc_player reads: name, year, show or film, season, episode, TMDB id.
$read = fn(string $file, string $dir = ''): array => [($m = new MetaVideo($file, $dir))->name(), $m->year, $m->tv, $m->season, $m->episode, $m->tmdbId];
same(['The Wandering Earth', 2019, false, null, null, null], $read('The.Wandering.Earth.2019.2160p.WEB-DL.H265.HDR.DDP5.1.mkv'), 'a film: its name up to its year, the 1 of DDP5.1 no episode');
same(['流浪地球', 2019, false, null, null, null], $read('流浪地球.The.Wandering.Earth.2019.1080p.BluRay.x264.mkv'), 'the Chinese name before the English');
same(['Breaking Bad', null, true, 2, 5, null], $read('Breaking.Bad.S02E05.1080p.BluRay.x264-DEMAND.mkv'), 'a show\'s S02E05');
same(['葬送的芙莉蓮', null, true, 1, 12, null], $read('[ANi] 葬送的芙莉蓮 - 12 [1080P][Baha][WEB-DL][AAC AVC][CHT].mp4'), 'a group\'s name off the front, the number after the dash its episode');
same(['黑暗荣耀', null, true, 2, 1, null], $read('01.mkv', '黑暗荣耀 第二季'), 'a file of only a number: the folder\'s name and season');
same(['三体', null, true, 1, 3, null], $read('第3集.mp4', '三体'), '第3集 in a folder of the show');
same(['Oppenheimer', 2023, false, null, null, 872585], $read('Oppenheimer (2023) {tmdb-872585}.mkv'), 'a TMDB id written in the name');
same(['Dune', 2021, false, null, null, 438631], $read('Dune.2021.mkv', 'Dune (2021) {tmdbid=438631}'), 'or in its folder\'s');
same(['Game of Thrones', null, true, 8, 3, null], $read('Game.of.Thrones.Season.8.Episode.3.mkv'), 'Season 8 Episode 3, the word Season out of the name');
same(['Rocky II', 1979, false, null, null, null], $read('Rocky.II.1979.720p.mkv'), 'a Roman numeral in the name');
same('', (new MetaVideo('hdr-av1.mkv'))->name(), 'no name in what is only a codec\'s');
same([3, 12, 20, 105], array_map(MetaVideo::chineseNumber(...), ['三', '十二', '二十', '一百零五']), 'Chinese numbers');

// The settings (IINA's, by its names and defaults): its defaults at first; set ones in their JSON file, read again by another; each of its type; told of changes.
$file = sys_get_temp_dir() . '/myphp-settings-' . getmypid() . '.json';
@unlink($file);
$set = new PlayerSettings($file);
same([[], '', 1, true, '~/Pictures/Screenshots'], [$set->get('subLang'), $set->get('openSubUsername'), $set->get('hardwareDecoder'), $set->get('keepOpenOnFileEnd'), $set->get('screenShotFolder')], 'IINA\'s defaults at first');
check(!$set->stored('hardwareDecoder'), 'none stored: the hardware decoder the app\'s');
$told = [];
$set->onChange('hardwareDecoder', function (int $v) use (&$told) { $told[] = $v; });
$set->set('subLang', ['zh-Hans', 'en']); $set->set('openSubUsername', 'kitty'); $set->set('hardwareDecoder', 0); $set->set('hardwareDecoder', 0);
same([0], $told, 'told once of a change, not of the same value again');
$again = new PlayerSettings($file);
same([['zh-Hans', 'en'], 'kitty', 0, true], [$again->get('subLang'), $again->get('openSubUsername'), $again->get('hardwareDecoder'), $again->stored('hardwareDecoder')], 'in their file');
same(['subLang', 'openSubUsername', 'hardwareDecoder'], array_reverse(array_keys(json_decode((string)file_get_contents($file), true))), 'by IINA\'s names of them');
$threw = false; try { $set->set('hardwareDecoder', 'auto'); } catch (\InvalidArgumentException) { $threw = true; }
check($threw, 'a value of the wrong type throws');
$threw = false; try { $set->get('noSuchSetting'); } catch (\InvalidArgumentException) { $threw = true; }
check($threw, 'a setting IINA has not throws');
file_put_contents($file, '{"hardwareDecoder": "auto"}');
$threw = false; try { (new PlayerSettings($file))->get('hardwareDecoder'); } catch (\RuntimeException) { $threw = true; }
check($threw, 'a file of settings of the wrong types is no settings: it throws');
unlink($file);

// The settings window's search (IINA's): every word in a word of a row (or a row under one); by page, then section, then label.
$pages = [];
(new Headless(function (Context $c) use (&$pages) { $pages = (new MoviePlayerSettingsPages($c, new MoviePlayerState(), (object)[]))->all(); $c->column(); $c->end(); }, 600, 480))->frame();
same(['general', 'ui', 'video', 'audio', 'subtitles', 'network', 'control', 'key.bindings', 'advanced', 'utilities'], array_keys($pages), 'the pages, in IINA\'s order');
same(['hardwareDecoder.label'], array_column(MoviePlayerSettings::search($pages, 'HARDWARE'), 'id'), 'a word of a label, whatever its case');
same(['hardwareDecoder.label'], array_column(MoviePlayerSettings::search($pages, 'filters'), 'id'), 'a word of what an option says');
same(['pauseWhenMinimized'], array_column(MoviePlayerSettings::search($pages, 'minimized'), 'id'), 'a row under another');
same('Pause/resume when', MoviePlayerSettings::search($pages, 'minimized')[0]['parent'], 'with the row it is under');
same([], MoviePlayerSettings::search($pages, 'source hardware'), 'each word in the same row');
same([], MoviePlayerSettings::search($pages, '   '), 'nothing for no words');

if (PHP_OS_FAMILY === 'Darwin') {
    // The window (Context::moviePlayerSettings): the pages, the Video page's sections, the hardware decoder's choices; the Subtitles page's account and languages.
    @unlink($file);
    $s = new MoviePlayerState(); $s->playerSettings = $again = new PlayerSettings($file);
    $again->set('openSubUsername', 'kitty'); $again->set('subLang', ['zh-Hans']);
    $h = new Headless(fn(Context $c) => $c->moviePlayerSettings($s), 600, 480);
    $h->frame();
    check($h->byText('General') !== null && $h->byText('At launch') !== null, 'the General page first');
    $h->clickOn($h->byText('Video')); $h->frame();
    check($h->byText('Decoding') !== null && $h->byText('Hardware decoder') !== null, 'the Video page: its section, its setting');
    check($h->byText('Disabled') !== null && $h->byText('Auto') !== null, 'the hardware decoder\'s choices');
    $h->clickOn($h->byText('Disabled')); $h->frame();
    same(0, $again->get('hardwareDecoder'), 'Disabled chosen: kept');
    check(!\MyPHP\Media\Player::$hardwareDecoding, 'and the films opened next decode in software');
    $s->setSetting('hardwareDecoder', 1);
    $h->clickOn($h->byText('Subtitles')); $h->frame();
    check($h->byText('Logged in as kitty') !== null && $h->byText('Logout') !== null && $h->byText('zh-Hans') !== null && $h->byText('Online Subtitles') !== null, 'the Subtitles page: the account and its Logout, the languages');
    $again->set('openSubUsername', ''); $h->frame();
    check($h->byText('Not logged in') !== null && $h->byText('Login') !== null, 'none logged in: Login');
    unlink($file);
}
