<?php
// myphp's official plugins of PHP (plugins/): Online Media's hooks giving the player what yt-dlp found of
// a page (a fake yt-dlp's JSON): its video, the audio apart, title, headers, subtitles, chapters, a
// playlist's entries; its formats in the Plugin menu; User Scripts' scripts run with $iina; the
// OpenSubtitles provider and menu.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Media\{Opening, PlayerSettings};
use MyPHP\PlayerPlugins\{Plugin, Plugins};
use MyPHP\UI\MediaPlayerState;

$root = sys_get_temp_dir() . '/myphp-official-' . getmypid();
$dir = "$root/plugins";
@mkdir($dir, 0777, true);
foreach (['online-media' => 'io.myphp.ytdl', 'userscript' => 'io.myphp.user-script', 'opensub' => 'io.myphp.opensub'] as $folder => $id) {
    exec('cp -R ' . escapeshellarg(__DIR__ . "/../plugins/$folder") . ' ' . escapeshellarg("$dir/$id.iinaplugin"));
}
$settings = new PlayerSettings("$root/settings.json");
Plugins::configure($dir, $settings);
same(['io.myphp.opensub', 'io.myphp.user-script', 'io.myphp.ytdl'], array_map(fn(Plugin $p) => $p->identifier, Plugins::all()), 'the three load');
foreach (Plugins::all() as $p) { Plugins::setEnabled($p, true); }
check(Plugins::hasYTDL(), 'Online Media on: the player\'s yt-dlp is not');

// A fake yt-dlp: the JSON of a video of its formats apart, subtitles and chapters; a playlist's for a list.
$bin = "$root/yt-dlp";
$video = ['title' => 'A Video', 'formats' => [['format_id' => '137', 'format' => '137 - 1080p', 'vcodec' => 'avc1'], ['format_id' => '140', 'format' => '140 - audio', 'vcodec' => 'none']],
    'requested_formats' => [['format_id' => '137', 'vcodec' => 'avc1', 'url' => 'https://cdn.example/v.mp4', 'http_headers' => ['User-Agent' => 'UA', 'Referer' => 'https://example.com/']],
        ['format_id' => '140', 'vcodec' => 'none', 'url' => 'https://cdn.example/a.m4a', 'format_note' => 'medium']],
    'requested_subtitles' => ['en' => ['ext' => 'vtt', 'url' => 'https://cdn.example/en.vtt', 'name' => 'English']],
    'chapters' => [['start_time' => 0, 'title' => 'Intro'], ['start_time' => 30, 'title' => 'Main']]];
$list = ['_type' => 'playlist', 'webpage_url' => 'https://example.com/list', 'entries' => [['url' => 'https://example.com/1', 'title' => 'One'], ['url' => 'https://example.com/2', 'title' => 'Two  Spaced']]];
file_put_contents("$root/video.json", json_encode($video));
file_put_contents("$root/list.json", json_encode($list));
file_put_contents($bin, "#!/bin/sh\nfor a in \"\$@\"; do last=\"\$a\"; done\necho \"\$@\" > " . escapeshellarg("$root/args") . "\ncase \"\$last\" in *list*) cat " . escapeshellarg("$root/list.json") . ";; *) cat " . escapeshellarg("$root/video.json") . ";; esac\n");
chmod($bin, 0755);
Plugins::byId('io.myphp.ytdl')->setPreference('ytdl_path', $bin);

$s = new MediaPlayerState(); $s->playerSettings = $settings;
$s->loadPlugins();
same(3, count($s->plugins()), 'a player\'s instances');
$hooks = new \ReflectionMethod($s, 'runHooks');
$o = $hooks->invoke($s, 'on_load', new Opening('https://example.com/watch', 'https://example.com/watch'));
same(false, $o->changed(), 'on_load: a page of http left to the player first');
$o = $hooks->invoke($s, 'on_load_fail', new Opening('https://example.com/watch', 'https://example.com/watch'));
same(['https://cdn.example/v.mp4', 'A Video', ['User-Agent' => 'UA', 'Referer' => 'https://example.com/'], ['https://cdn.example/a.m4a'], [['url' => 'https://cdn.example/en.vtt', 'name' => 'English', 'language' => 'en', 'ext' => 'vtt']]],
    [$o->url, $o->title, $o->headers, $o->audio, $o->subtitles], 'on_load_fail: yt-dlp\'s video in its place, its audio apart, title, headers, subtitles');
check(str_contains((string)file_get_contents("$root/args"), '--dump-single-json --flat-playlist --sub-format ass/srt/best --format bestvideo+bestaudio/best --sub-langs all --no-playlist -- https://example.com/watch'), 'yt-dlp asked as IINA\'s plugin asks it');
$o = $hooks->invoke($s, 'on_preloaded', new Opening('https://example.com/watch', 'https://example.com/watch'));
same([['Intro', 0.0], ['Main', 30.0]], array_map(fn($c) => [$c->title, $c->start], $o->chapters), 'on_preloaded: its chapters');
$ytdl = $s->pluginInstance(Plugins::byId('io.myphp.ytdl'));
same(['', 'Download this video', 'Video Quality', 'Audio Quality'], array_map(fn($i) => $i->isSeparator ? '' : $i->title, $ytdl->menuItems), 'its menu: download, the formats');
same([['137 - 1080p', true]], array_map(fn($i) => [$i->title, $i->selected], $ytdl->menuItems[2]->items), 'the video\'s formats, the one playing checked');
$o = $hooks->invoke($s, 'on_load', new Opening('ytdl://https://example.com/list', 'ytdl://https://example.com/list'));
same("memory://#EXTM3U\n#EXTINF:0,One\nhttps://example.com/1\n#EXTINF:0,Two Spaced\nhttps://example.com/2", $o->url, 'ytdl:// at on_load; a playlist: its entries, as an M3U in memory');
$m = new \ReflectionMethod($s, 'openMemoryPlaylist');
// a playlist given in memory: taken, its first entry opened (a file that is none here), as mpv expands it
same(false, $m->invoke($s, "memory://#EXTM3U\n#EXTINF:0,One\n$root/1.mkv\n$root/2.mkv", true), 'its first entry opened: none here');
same(["$root/1.mkv", "$root/2.mkv", 'One', "$root/1.mkv"], [...$s->playlist->files(), $s->playlist->title("$root/1.mkv"), $s->playlist->current()], 'the playlist, its titles, its first');

// User Scripts: a script of the list run with $iina.
$us = Plugins::byId('io.myphp.user-script');
@mkdir($us->dataDir(), 0777, true);
file_put_contents($us->dataDir() . '/script-list.json', json_encode([['id' => 'abc', 'name' => 'Hello']]));
file_put_contents($us->dataDir() . '/abc.php', '$GLOBALS["userScriptRan"] = $iina->core !== null;');
$s2 = new MediaPlayerState(); $s2->playerSettings = $settings;
$s2->loadPlugins();
same(true, $GLOBALS['userScriptRan'] ?? null, 'a user script: run in the player\'s instance');

// OpenSubtitles: its provider; Show Sidebar as the Plugin menu opens (iina.menu-update).
$os = $s2->pluginInstance(Plugins::byId('io.myphp.opensub'));
$threw = '';
try { $os->iina->subtitle->search('open-sub'); } catch (\MyPHP\PlayerPlugins\PluginError $e) { $threw = $e->getMessage(); }
check(str_contains($threw, 'window is not available'), 'its provider: the sidebar searches, once the window is there');
$s2->emitForPlugins('iina.menu-update');
same(['Show Sidebar'], array_map(fn($i) => $i->title, $os->menuItems), 'Show Sidebar, as the menu opens');
Plugins::stop();
exec('rm -rf ' . escapeshellarg($root));
