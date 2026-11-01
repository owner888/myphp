<?php
// Each player's instance (IINA's plugin-online-media index.ts, ytdl-hook.ts and add-video.ts): a page's URL read by yt-dlp as it
// opens (on_load for ytdl://, then once the player could not open it, or first where the preferences say), what yt-dlp found
// given the player in its place (its video, its audio apart, title, headers, subtitles, chapters; a playlist's entries), its
// formats offered in the Plugin menu, the video downloaded by the app's instance.
use MyPHP\PlayerPlugins\Iina;
use function MyPHPPlugins\OnlineMedia\{edlEscape, edlTrackJoined, findBinary, format, getIndexFromYouTubePlaylist, isBlacklisted, isSafeURL, jsRuntimeOption, optionWasSet, optionWasSetLocally, setHTTPHeaders, ytdlCodecToMpvCodec};

require_once __DIR__ . '/lib.php';

return function (Iina $iina) {
    $core = $iina->core; $console = $iina->console; $mpv = $iina->mpv; $menu = $iina->menu; $utils = $iina->utils;
    $state = (object)['chapterList' => [], 'isSwitchingFormat' => false, 'currentVideoFormat' => null, 'currentAudioFormat' => null, 'currentURL' => ''];

    $matchTime = function (string $line): ?int {
        if (preg_match('/((\d+):)?(\d\d?):(\d\d)/', $line, $m) !== 1) { return null; }
        return ($m[2] !== '' ? (int)$m[2] : 0) * 3600 + (int)$m[3] * 60 + (int)$m[4];
    };
    $extractChapters = function (string $data, float $length) use ($matchTime): array {
        $out = [];
        foreach (preg_split('/\r|\n/', $data) ?: [] as $line) {
            if ($line === '') { continue; }
            $time = $matchTime($line);
            if ($time && $time < $length) { $out[] = ['time' => $time, 'title' => $line]; }
        }
        usort($out, fn($a, $b) => $a['time'] <=> $b['time']);
        return $out;
    };
    $isValidManifest = function (array $json): bool {
        $req = $json['requested_formats'][1] ?? [];
        if (empty($req['manifest_url']) && empty($json['manifest_url'])) { return false; }
        $proto = $req['protocol'] ?? $json['protocol'] ?? '';
        return $proto === 'http_dash_segments' ? false : str_starts_with($proto, 'm3u8'); // the player reads no DASH manifest (mpv's demuxer-lavf-list has no dash)
    };

    $processVideo = function (?array $reqfmts, array $json) use ($iina, $mpv, $console, $state, $isValidManifest, $extractChapters) {
        $streamURL = '';
        $maxBitrate = 0;
        if ($iina->preferences->get('use_manifests') && $isValidManifest($json)) { // the manifest's URL where there is one
            $mpdURL = $reqfmts ? ($reqfmts[0]['manifest_url'] ?? null) : ($json['manifest_url'] ?? null);
            if (!$mpdURL) { $console->error('No manifest URL found in JSON data.'); return; }
            if (!isSafeURL($iina, $mpdURL)) { return; }
            $streamURL = $mpdURL;
            $maxBitrate = $reqfmts ? max(array_map(fn($f) => (float)($f['tbr'] ?? 0), $reqfmts)) : (float)($json['tbr'] ?? 0);
        } elseif ($reqfmts) { // DASH: the video's and the audio's apart
            foreach ($reqfmts as $track) {
                $edlTrack = edlTrackJoined($iina, $track['fragments'] ?? null, $track['protocol'] ?? null, $json['is_live'] ?? null, $track['fragment_base_url'] ?? null);
                if (!$edlTrack && !isSafeURL($iina, $track['url'] ?? null)) { return; }
                if (($track['vcodec'] ?? null) && $track['vcodec'] !== 'none') {
                    $streamURL = $edlTrack ?: $track['url'];
                } elseif (($track['vcodec'] ?? null) === 'none') { // according to ytdl, if vcodec is None, it's audio
                    $mpv->command('audio-add', [$edlTrack ?: $track['url'], 'auto', $track['format_note'] ?? '']);
                }
                setHTTPHeaders($iina, $track['http_headers'] ?? null);
            }
        } elseif (!empty($json['url'])) {
            $edlTrack = edlTrackJoined($iina, $json['fragments'] ?? null, $json['protocol'] ?? null, $json['is_live'] ?? null, $json['fragment_base_url'] ?? null);
            if (!$edlTrack && !isSafeURL($iina, $json['url'])) { return; }
            $streamURL = $edlTrack ?: $json['url'];
            setHTTPHeaders($iina, $json['http_headers'] ?? null);
        } else {
            $console->error('No URL found in JSON data.');
            return;
        }
        $console->log("streamurl: $streamURL");
        $mpv->set('stream-open-filename', preg_replace('/^data/', 'data://', $streamURL));
        $mpv->set('file-local-options/force-media-title', (string)($json['title'] ?? ''));
        if ($maxBitrate > 0 && !optionWasSet($iina, 'hls-bitrate') && !optionWasSetLocally($iina, 'hls-bitrate')) {
            $mpv->set('file-local-options/hls-bitrate', $maxBitrate * 1000);
        }
        foreach (is_array($json['requested_subtitles'] ?? null) ? $json['requested_subtitles'] : [] as $lang => $subInfo) {
            $console->log("adding subtitle [$lang]");
            $sub = !empty($subInfo['data']) ? "memory://{$subInfo['data']}" : (!empty($subInfo['url']) && isSafeURL($iina, $subInfo['url']) ? $subInfo['url'] : null);
            if ($sub === null) { $console->log("No subtitle data/url for $lang"); continue; }
            $codec = ytdlCodecToMpvCodec((string)($subInfo['ext'] ?? ''));
            $edl = 'edl://!no_clip;!delay_open,media_type=sub' . ($codec ? ",codec=$codec;" : ';') . edlEscape($sub);
            $mpv->command('sub-add', [$edl, 'auto', $subInfo['name'] ?? $subInfo['ext'] ?? '', (string)$lang]);
        }
        if (!empty($json['chapters'])) {
            $console->log('Adding pre-parsed chapters');
            foreach (array_values($json['chapters']) as $i => $chapter) {
                $state->chapterList[] = ['time' => $chapter['start_time'] ?? 0, 'title' => $chapter['title'] ?? "Chapter $i"];
            }
        } elseif (!empty($json['description']) && !empty($json['duration'])) {
            $state->chapterList = $extractChapters((string)$json['description'], (float)$json['duration']);
        }
        if (!empty($json['start_time']) && !optionWasSet($iina, 'start') && !optionWasSetLocally($iina, 'start')) {
            $console->log("Setting start to: {$json['start_time']} secs");
            $mpv->set('file-local-options/start', $json['start_time']);
        }
        if (!empty($json['stretched_ratio']) && !optionWasSet($iina, 'video-aspect')) {
            $mpv->set('file-local-options/video-aspect', $json['stretched_ratio']);
        }
    };

    $formatDescription = fn(array $f): string => !empty($f['dynamic_range']) ? "{$f['format']} {$f['dynamic_range']}" : (string)($f['format'] ?? '');

    $addVideo = function (array $json) use ($iina, $core, $console, $mpv, $menu, $state, $processVideo, $formatDescription) {
        $reqfmts = $json['requested_formats'] ?? null;
        if (!empty($json['formats']) && $reqfmts) { // live streams can have no requested_formats
            if ($state->isSwitchingFormat) {
                $af = $vf = null;
                foreach ($json['formats'] as $f) {
                    if ($f['format_id'] === $state->currentAudioFormat) { $af = $f; }
                    if ($f['format_id'] === $state->currentVideoFormat) { $vf = $f; }
                }
                if ($af && $vf) { $reqfmts = [$af, $vf]; $state->isSwitchingFormat = false; }
            } else {
                foreach ($reqfmts as $f) {
                    if (($f['vcodec'] ?? 'none') !== 'none') { $state->currentVideoFormat = $f['format_id']; } else { $state->currentAudioFormat = $f['format_id']; }
                }
            }
            $console->log('reconstruct menu');
            $menu->removeAllItems();
            $menu->addItem($menu->separator());
            $menu->addItem($menu->item('Download this video', function () use ($iina, $core, $state) {
                $core->osd('Preparing for download');
                $iina->global?->postMessage('downloadVideo', $state->currentURL);
            }, ['keyBinding' => 'Meta+d']));
            $videoItem = $menu->item('Video Quality');
            $audioItem = $menu->item('Audio Quality');
            $reopen = fn() => $core->open((string)$mpv->getString('path')); // mpv's loadfile of the page again
            foreach ($json['formats'] as $f) {
                $audio = ($f['vcodec'] ?? null) === 'none';
                ($audio ? $audioItem : $videoItem)->addSubMenuItem($menu->item($formatDescription($f), function () use ($state, $f, $audio, $reopen) {
                    $audio ? $state->currentAudioFormat = $f['format_id'] : $state->currentVideoFormat = $f['format_id'];
                    $state->isSwitchingFormat = true;
                    $reopen();
                }, ['selected' => $f['format_id'] === ($audio ? $state->currentAudioFormat : $state->currentVideoFormat)]));
            }
            $menu->addItem($videoItem);
            $menu->addItem($audioItem);
            $menu->forceUpdate();
        }
        $processVideo($reqfmts, $json);
    };

    $ytdlSuccess = function (string $url, array $json, array $option) use ($iina, $core, $console, $mpv, $addVideo) {
        $core->osd('Opening media…');
        $json['proxy'] = $json['proxy'] ?? $option['proxy'];
        if (!empty($json['direct'])) {
            $console->log('Got direct URL');
            return;
        }
        if (in_array($json['_type'] ?? null, ['playlist', 'multi_video'], true)) {
            $entries = $json['entries'] ?? [];
            if ($entries === []) { $console->warn('Got empty playlist, nothing to play'); return; }
            $selfRedirecting = ($entries[0]['_type'] ?? null) !== 'url_transparent' && !empty($entries[0]['webpage_url']) && $entries[0]['webpage_url'] === ($json['webpage_url'] ?? null);
            if ($selfRedirecting) {
                if (count($entries) > 1 && ($entries[0]['protocol'] ?? null) === 'm3u8_native' && !empty($entries[0]['url'])) {
                    $console->log('Multi-arc video detected, building EDL');
                    $playlist = edlTrackJoined($iina, $entries);
                    $console->log("EDL: $playlist");
                    if (!$playlist) { return; }
                    setHTTPHeaders($iina, $entries[0]['http_headers'] ?? null);
                    $mpv->set('stream-open-filename', $playlist);
                    if (!empty($json['title'])) { $mpv->set('file-local-options/force-media-title', $json['title']); }
                } elseif (count($entries) === 1) {
                    $console->log('Playlist with single entry detected');
                    $addVideo($entries[0]);
                }
                return;
            }
            $playlistIndex = getIndexFromYouTubePlaylist($iina, $url, $json);
            $playlist = ['#EXTM3U'];
            foreach ($entries as $entry) {
                $site = $entry['url'] ?? null;
                if (!empty($entry['title'])) { $playlist[] = '#EXTINF:0,' . preg_replace('/\s+/', ' ', $entry['title'], 1); }
                if (!empty($entry['webpage_url'])) { $site = $entry['webpage_url']; }
                if (!$site) { continue; }
                if (!str_contains($site, '://')) {
                    $playlist[] = (str_contains($site, ':') ? 'ytdl://' : 'https://youtu.be/') . $site;
                } elseif (isSafeURL($iina, $site)) {
                    $playlist[] = $site;
                }
            }
            if ($option['usePlaylist'] && optionWasSet($iina, 'playlist-start') && $playlistIndex) { $mpv->set('playlist-start', $playlistIndex); }
            $mpv->set('stream-open-filename', 'memory://' . implode("\n", $playlist));
            return;
        }
        $addVideo($json); // a single video
    };

    $runYTDLHook = function (string $url) use ($iina, $core, $console, $mpv, $utils, $state, $ytdlSuccess) {
        $core->osd($state->isSwitchingFormat ? 'Switching quality…' : 'Fetching online media information…');
        $format = format($iina);
        $allsubs = true;
        $option = ['proxy' => null, 'usePlaylist' => false];
        if (str_starts_with($url, 'ytdl://')) { $url = substr($url, 7); }
        $state->currentURL = $url;
        $args = ['--no-warnings', '--dump-single-json', '--flat-playlist', '--sub-format', 'ass/srt/best'];
        if ($mpv->getString('options/vid') === 'no') {
            $format = 'bestaudio/best';
            $console->log('Video is disabled. Only use audio');
        }
        array_push($args, '--format', $format);
        $rawOptions = (string)$iina->preferences->get('raw_options');
        $includeSubs = (bool)$iina->preferences->get('include_subs');
        $includeAutoSubs = $includeSubs && $iina->preferences->get('include_auto_subs');
        $raw = explode(' ', $rawOptions);
        foreach ($raw as $index => $rawArg) {
            $arg = $rawArg;
            if (str_contains($rawArg, '—')) {
                $arg = preg_replace('/—/', '--', $rawArg, 1);
                $console->warn("Argument $rawArg contains \"—\", trying to autocorrect");
            }
            if (str_starts_with($arg, '--')) {
                if (str_contains($arg, '=')) { [$argName, $argValue] = explode('=', $arg, 2); } else { $argName = substr($arg, 2); $argValue = $raw[$index + 1] ?? null; }
                if (in_array($argName, ['sub-lang', 'sub-langs', 'srt-lang'], true) && $argValue) { $allsubs = false; }
                elseif ($argName === 'proxy' && $argValue) { $option['proxy'] = $argValue; }
                elseif ($argName === 'yes-playlist') { $option['usePlaylist'] = true; }
            }
            if ($arg !== '') { $args[] = $arg; }
        }
        if ($allsubs && $includeSubs) { array_push($args, '--sub-langs', 'all'); }
        if ($includeAutoSubs) { $args[] = '--write-auto-subs'; }
        if (!$option['usePlaylist']) { $args[] = '--no-playlist'; }
        try {
            $console->log('Running youtube-dl...');
            ['path' => $path, 'jsRuntime' => $jsRuntime] = findBinary($iina);
            if ($jsRuntime) {
                $resolved = (string)$utils->resolvePath($jsRuntime);
                $value = jsRuntimeOption($resolved);
                if ($value) {
                    $console->log("Using JS runtime: $resolved");
                    array_push($args, '--js-runtimes', $value);
                } else {
                    $console->warn("$resolved is not named after a JS runtime yt-dlp supports, ignoring it");
                }
            }
            array_push($args, '--', $url);
            $out = $utils->exec($path, $args);
            if (($out['status'] ?? 1) !== 0) {
                $core->osd('Failed to run youtube-dl');
                $console->error('Error running youtube-dl: ' . ($out['stderr'] ?? ''));
                return;
            }
            $console->log('Finished running youtube-dl');
            $json = json_decode($out['stdout'], true);
            if (!is_array($json)) {
                $core->osd('Failed to fetch online media information');
                $console->error("Failed to parse youtube-dl's output: " . json_last_error_msg());
                return;
            }
            $console->log('Youtube-dl succeeded.');
            $ytdlSuccess($url, $json, $option);
        } catch (\Throwable $e) {
            $core->osd('Unknown error.');
            $console->error("Unexpected error: {$e->getMessage()}");
        }
    };

    $tryFirst = (bool)$iina->preferences->get('try_ytdl_first');
    if (!$tryFirst) {
        $mpv->addHook('on_load', 10, function ($next) use ($console, $mpv, $runYTDLHook) {
            $console->log('ytdl:// hook');
            $url = (string)$mpv->getString('stream-open-filename');
            if (str_starts_with($url, 'ytdl://')) { $runYTDLHook($url); }
            $next();
        });
    }
    $mpv->addHook($tryFirst ? 'on_load' : 'on_load_fail', 10, function ($next) use ($iina, $console, $mpv, $runYTDLHook) {
        $console->log('ytdl full hook');
        $url = (string)$mpv->getString('stream-open-filename');
        if ((str_starts_with($url, 'ytdl://') || str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) && !isBlacklisted($iina, $url)) { $runYTDLHook($url); }
        $next();
    });
    $mpv->addHook('on_preloaded', 10, function ($next) use ($console, $mpv, $state) {
        $console->log('ytdl preload hook');
        if ($state->chapterList !== []) {
            $console->log('Setting chapter list');
            $mpv->set('chapter-list', $state->chapterList);
            $state->chapterList = [];
        }
        $next();
    });

    $iina->global?->onMessage('downloading', fn() => $core->osd('Video downloading'));
    $iina->global?->onMessage('downloaded', fn() => $core->osd('Video downloaded'));
};
