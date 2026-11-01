<?php
// What the Online Media plugin's instances share (IINA's plugin-online-media options.ts, utils.ts and binary.ts).
namespace MyPHPPlugins\OnlineMedia;

use MyPHP\PlayerPlugins\Iina;

const YTDLP_URL = 'https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp_macos.zip';
const DENO_URL = 'https://github.com/denoland/deno/releases/latest/download/';
/** The JavaScript runtimes yt-dlp supports, in its order of priority. */
const JS_RUNTIMES = ['deno', 'node', 'quickjs', 'bun'];
const SAFE_PROTOS = ['http', 'https', 'ftp', 'ftps', 'rtmp', 'rtmps', 'rtmpe', 'rtmpt', 'rtmpts', 'rtmpte', 'data'];

/** The format to ask yt-dlp for, as the preferences say (IINA's opt.format). */
function format(Iina $iina): string
{
    $p = $iina->preferences;
    switch ($p->get('video_quality')) {
        case 'use_max':
            $max = $p->get('max_video_height');
            return "bestvideo[height<=$max]+bestaudio/best[height<=$max]";
        case 'custom':
            return (string)$p->get('custom_ytdl_format');
        default:
            return 'bestvideo+bestaudio/best';
    }
}

/** Whether $url is of the excluded (the preference's, "|" between them, each a regular expression of what follows "https://"). */
function isBlacklisted(Iina $iina, string $url): bool
{
    $exclude = (string)$iina->preferences->get('excluded_urls');
    if ($exclude === '') { return false; }
    if (preg_match('~^https?://(.+?)$~', $url, $m) !== 1) { return false; }
    foreach (explode('|', $exclude) as $b) {
        if (@preg_match('~' . str_replace('~', '\~', $b) . '~', $m[1]) === 1) {
            $iina->console->log('URL matches excluded substring. Skipping.');
            return true;
        }
    }
    return false;
}

function ytdlCodecToMpvCodec(string $codec): ?string
{
    return match (true) {
        $codec === 'vtt' => 'webvtt',
        $codec === 'opus' => 'opus',
        str_starts_with($codec, 'vp9') => 'vp9',
        str_starts_with($codec, 'avc') => 'h264',
        str_starts_with($codec, 'av0') => 'av1',
        str_starts_with($codec, 'mp4a') => 'aac',
        str_starts_with($codec, 'hev1') => 'hevc',
        default => null,
    };
}

function optionWasSet(Iina $iina, string $name): bool
{
    return $iina->mpv->getFlag("\"option-info/$name/set-from-commandline\"");
}

function optionWasSetLocally(Iina $iina, string $name): bool
{
    return $iina->mpv->getFlag("\"option-info/$name/set-locally\"");
}

/** @param ?array<string, string> $headers */
function setHTTPHeaders(Iina $iina, ?array $headers): void
{
    if (!$headers) { return; }
    $ua = $headers['User-Agent'] ?? null;
    if ($ua && !optionWasSet($iina, 'user-agent')) { $iina->mpv->set('file-local-options/user-agent', $ua); }
    $fields = [];
    foreach (['Cookie', 'Referer', 'X-Forwarded-For'] as $field) {
        if (!empty($headers[$field])) { $fields[] = "$field: {$headers[$field]}"; }
    }
    if ($fields !== [] && !optionWasSet($iina, 'http-header-fields')) { $iina->mpv->set('file-local-options/http-header-fields', $fields); }
}

function edlEscape(string $url): string
{
    return '%' . strlen($url) . "%$url";
}

function isSafeURL(Iina $iina, mixed $url): bool
{
    if (!is_string($url)) { return false; }
    if (preg_match('~^(.+?)://~', $url, $m) === 1 && in_array($m[1], SAFE_PROTOS, true)) { return true; }
    $iina->console->log("Ignoring potentially unsafe url $url");
    return false;
}

/** @param array<string, mixed> $json */
function getIndexFromYouTubePlaylist(Iina $iina, string $url, array $json): ?int
{
    if (($json['extractor'] ?? null) !== 'youtube:playlist') { return null; }
    $q = strpos($url, '?');
    if ($q === false || $q === strlen($url) - 1) { return null; }
    parse_str(substr($url, $q + 1), $args);
    $entries = $json['entries'] ?? [];
    $maybe = (int)($args['index'] ?? 0);
    if ($maybe && count($entries) >= $maybe && ($entries[$maybe]['id'] ?? null) === ($args['v'] ?? null)) {
        $iina->console->log('index matches requested video');
        return $maybe;
    }
    foreach ($entries as $i => $e) { if (($e['id'] ?? null) === ($args['v'] ?? null)) { return $i; } }
    $iina->console->log('requested video not found in playlist');
    return null;
}

/** @param array<string, mixed> $fragment */
function joinURL(?string $base, array $fragment): string
{
    if ($base && isset($fragment['path'])) {
        $url = (string)$fragment['path'];
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) { return $url; }
        if (preg_match('~(https?://)([^/]+/)(.*)/?~', $base, $m) !== 1) { return $url; }
        $resolved = [];
        foreach ([...explode('/', $m[3]), ...explode('/', $url)] as $seg) {
            if ($seg === '..') { array_pop($resolved); } elseif ($seg !== '.') { $resolved[] = $seg; }
        }
        return $m[1] . $m[2] . implode('/', $resolved);
    }
    return (string)($fragment['url'] ?? '');
}

/** An EDL of $fragments, null for none or one unsafe (mpv's ytdl_hook's edl_track_joined). @param ?list<array<string, mixed>> $fragments */
function edlTrackJoined(Iina $iina, ?array $fragments, ?string $protocol = null, ?bool $isLive = null, ?string $base = null): ?string
{
    if (!$fragments) {
        $iina->console->log('No fragments to join into EDL');
        return null;
    }
    $parts = [];
    if ($protocol === 'http_dash_segments' && empty($fragments[0]['duration']) && !$isLive) {
        $parts[] = '!mp4_dash,init=' . edlEscape(joinURL($base, $fragments[0]));
        foreach (array_slice($fragments, 1) as $f) {
            if (empty($f['duration'])) {
                $iina->console->error("EDL doesn't support fragments without duration with MP4 DASH");
                return null;
            }
        }
    }
    foreach ($fragments as $frag) {
        if (!isSafeURL($iina, joinURL($base, $frag))) { return null; }
        $parts[] = edlEscape(joinURL($base, $frag)) . (!empty($frag['duration']) ? ",length={$frag['duration']}" : '');
    }
    return 'edl://' . implode(';', $parts) . ';';
}

function formatFileSize(float $bytes, bool $si = false, int $dp = 1): string
{
    $thresh = $si ? 1000 : 1024;
    if (abs($bytes) < $thresh) { return "$bytes B"; }
    $units = $si ? ['kB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'] : ['KiB', 'MiB', 'GiB', 'TiB', 'PiB', 'EiB', 'ZiB', 'YiB'];
    $u = -1;
    $r = 10 ** $dp;
    do {
        $bytes /= $thresh;
        ++$u;
    } while (round(abs($bytes) * $r) / $r >= $thresh && $u < count($units) - 1);
    return number_format($bytes, $dp, '.', '') . " {$units[$u]}";
}

function formatSeconds(?float $sec): string
{
    if ($sec === null || is_nan($sec)) { return '-'; }
    return gmdate($sec < 3600 ? 'i:s' : 'H:i:s', (int)$sec);
}

/** The yt-dlp to run, and the JavaScript runtime for it ('' for none): the preferences', the plugin's own, the system's (IINA's findBinary). @return array{path: string, jsRuntime: string} */
function findBinary(Iina $iina): array
{
    $path = 'youtube-dl';
    foreach ([$iina->preferences->get('ytdl_path'), '@data/yt-dlp/yt-dlp_macos', 'yt-dlp', 'youtube-dl'] as $item) {
        if (is_string($item) && $item !== '' && $iina->utils->fileInPath($item)) {
            $iina->console->log("Found youtube-dl; using $item");
            $path = $item;
            break;
        }
    }
    $jsRuntime = '';
    foreach ([$iina->preferences->get('js_runtime'), '@data/deno/deno'] as $item) {
        if (is_string($item) && $item !== '' && $iina->utils->fileInPath($item)) {
            $iina->console->log("Found JS runtime; using $item");
            $jsRuntime = $item;
            break;
        }
    }
    return ['path' => $path, 'jsRuntime' => $jsRuntime];
}

/** yt-dlp's --js-runtimes RUNTIME:PATH of $runtimePath, its name its file's; null for none it supports. */
function jsRuntimeOption(string $runtimePath): ?string
{
    $name = basename($runtimePath);
    return in_array($name, JS_RUNTIMES, true) ? "$name:$runtimePath" : null;
}

/** Downloads Deno into @data/deno: null, else why it failed. */
function downloadDeno(Iina $iina): ?string
{
    $res = $iina->utils->exec('/bin/bash', ['-c', 'uname -m']);
    if (($res['status'] ?? 1) !== 0) { return 'Failed to get system architecture: ' . ($res['stderr'] ?? ''); }
    $arch = trim($res['stdout']) === 'arm64' ? 'aarch64' : 'x86_64';
    $fileName = "deno-$arch-apple-darwin.zip";
    $zip = $iina->utils->resolvePath("@data/$fileName");
    $dest = $iina->utils->resolvePath('@data/deno');
    $error = null;
    try {
        $iina->console->log("Downloading Deno to $zip");
        $iina->http->download(DENO_URL . $fileName, "@data/$fileName");
        $res = $iina->utils->exec('/bin/bash', ['-c', "unzip \"$zip\" -d \"$dest\" && rm \"$zip\" && xattr -cr \"$dest\""]);
        if ($res['status'] !== 0) { throw new \RuntimeException("Failed to unzip Deno: {$res['stderr']}"); }
    } catch (\Throwable $e) {
        $iina->console->error($e->getMessage());
        $error = $e->getMessage();
    } finally {
        try { if ($iina->file->exists("@data/$fileName")) { $iina->file->delete("@data/$fileName"); } } catch (\Throwable $e) { $iina->console->error('Failed to delete temp files: ' . $e->getMessage()); }
    }
    return $error;
}

/** Downloads the latest yt-dlp into @data/yt-dlp: null, else why it failed. */
function downloadYTDLP(Iina $iina): ?string
{
    $id = (int)(microtime(true) * 1000);
    $zip = $iina->utils->resolvePath("@data/yt-dlp_$id.zip");
    $unzip = $iina->utils->resolvePath("@data/yt-dlp_$id");
    $dest = $iina->utils->resolvePath('@data/yt-dlp');
    $error = null;
    try {
        $iina->console->log("Downloading yt-dlp to $zip");
        $iina->http->download(YTDLP_URL, "@data/yt-dlp_$id.zip");
        $res = $iina->utils->exec('/bin/bash', ['-c', <<<SH
            TARGET="$dest";
            if [ -e "\$TARGET" ] && [ ! -d "\$TARGET" ] && [ -f "\$TARGET" ]; then
              rm -rf "\$TARGET";
            fi;
            if [ ! -e "\$TARGET" ]; then
              mkdir -p "\$TARGET";
            fi;
            unzip "$zip" -d "$unzip" &&
            rm -rf "\$TARGET"/* &&
            mv "$unzip"/* "\$TARGET"/ &&
            rm "$zip" &&
            xattr -cr "\$TARGET"
            SH]);
        if ($res['status'] !== 0) { throw new \RuntimeException("Failed to unzip yt-dlp: {$res['stderr']}"); }
    } catch (\Throwable $e) {
        $iina->console->error($e->getMessage());
        $error = $e->getMessage();
    } finally {
        try {
            if ($iina->file->exists("@data/yt-dlp_$id.zip")) { $iina->file->delete("@data/yt-dlp_$id.zip"); }
            if ($iina->file->exists("@data/yt-dlp_$id")) { $iina->file->delete("@data/yt-dlp_$id"); }
        } catch (\Throwable $e) {
            $iina->console->error('Failed to delete temp files: ' . $e->getMessage());
        }
    }
    return $error;
}
