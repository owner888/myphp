<?php
// OpenSubtitles' REST API as IINA's OpenSubClient uses it (Media\OpenSubtitles),
// without the network: a file's hash (its wiki's HashSourceCodes, against
// Python's 64-bit sums), the system's languages as its codes, a search's
// response as the rows IINA lists; the settings of IINA's Subtitles pane
// the player has (Media\SubtitleSettings), and their pane; what a file's
// name tells of its film, to look it up on TMDB by (Media\MetaVideo, of
// agc_player's names).
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Media\{MetaVideo, OpenSubtitles, SubtitleSettings};
use MyPHP\UI\{Context, VideoPlayerState};

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

// The settings: the preferred languages and the account's user, in their JSON file, read again by another; none at first.
$file = sys_get_temp_dir() . '/myphp-subtitle-settings-' . getmypid() . '.json';
@unlink($file);
$set = new SubtitleSettings($file);
same([[], ''], [$set->languages(), $set->user()], 'none at first: the system\'s languages, no account');
$set->setLanguages(['zh-Hans', ' en ', '', 'zh-Hans']); $set->setUser('kitty');
$again = new SubtitleSettings($file);
same([['zh-Hans', 'en'], 'kitty'], [$again->languages(), $again->user()], 'in their file: the languages trimmed, once each, none empty');
// Their pane (Context::videoPlayerSettings): the account logged in, Logout; the languages a token each, one added kept.
$s = new VideoPlayerState(); $s->subtitleSettings = $again;
$h = new Headless(fn(Context $c) => $c->videoPlayerSettings($s), 560, 260);
$h->frame();
check($h->byText('Logged in as kitty') !== null && $h->byText('Logout') !== null && $h->byText('zh-Hans') !== null && $h->byText('Online Subtitles:') !== null, 'the pane: the account and its Logout, the languages');
$again->setUser(''); $h->frame();
check($h->byText('Not logged in') !== null && $h->byText('Login') !== null, 'none logged in: Login');
unlink($file);
