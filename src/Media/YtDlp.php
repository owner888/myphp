<?php
declare(strict_types=1);

namespace MyPHP\Media;

use MyPHP\Native\CurlLoop;
use RuntimeException;

/**
 * yt-dlp, the program that finds the video of a web page (YouTube's and
 * a thousand sites'), as mpv's ytdl_hook runs it for IINA: `yt-dlp -J
 * --flat-playlist` on a URL the player could not open as a film, its JSON
 * read: the formats chosen (yt-dlp's default, bestvideo+bestaudio, or
 * best: one URL or a video's and an audio's), the headers to read them
 * with, the title, chapters, subtitles; or a playlist's entries.
 */
final class YtDlp
{
    /** The yt-dlp run, null to find it (IINA's ytdl search path): in PATH, Homebrew's, /usr/local/bin. */
    public static ?string $path = null;

    /** The program found, null for none. */
    public static function find(): ?string
    {
        if (self::$path !== null) {
            return is_executable(self::$path) ? self::$path : null;
        }
        $dirs = [...explode(PATH_SEPARATOR, (string)getenv('PATH')), '/opt/homebrew/bin', '/usr/local/bin']; // an app the Finder opens has only the system's PATH
        foreach (['yt-dlp', 'youtube-dl'] as $name) { // mpv's names, the fork first
            foreach ($dirs as $d) {
                if ($d !== '' && is_file("$d/$name") && is_executable("$d/$name")) { return "$d/$name"; }
            }
        }
        return null;
    }

    /**
     * What yt-dlp says of $url: its JSON, decoded; waited for, as transfers
     * are (in a fiber of the app, the UI goes on). Its error, thrown.
     * @return array<string, mixed>
     */
    public static function info(string $url): array
    {
        $bin = self::find() ?? throw new RuntimeException('myphp: no yt-dlp to read the page with (brew install yt-dlp, or set Media\YtDlp::$path)');
        // mpv's ytdl_hook's: the JSON, a playlist's entries flat (each read as it plays), its subtitles' formats.
        $proc = proc_open([$bin, '--no-warnings', '-J', '--flat-playlist', '--sub-format', 'ass/srt/vtt/best', '--', $url], [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        if ($proc === false) {
            throw new RuntimeException("myphp: cannot run $bin");
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = ''; $err = '';
        CurlLoop::waitUntil(function () use ($pipes, &$out, &$err): bool {
            $out .= (string)stream_get_contents($pipes[1]);
            $err .= (string)stream_get_contents($pipes[2]);
            return feof($pipes[1]) && feof($pipes[2]);
        });
        fclose($pipes[1]); fclose($pipes[2]);
        $code = proc_close($proc);
        $json = $code === 0 ? json_decode($out, true) : null;
        if (!is_array($json)) {
            $why = trim((string)preg_replace('~^ERROR:\s*~m', '', trim($err)));
            throw new RuntimeException('myphp: yt-dlp: ' . ($why !== '' ? $why : "exit status $code"));
        }
        return $json;
    }

    /** The sites whose pages yt-dlp reads before the URL is opened as a film (mpv's ytdl_hook's include, IINA's): YouTube's, Twitch's. */
    private const FIRST = '~^https?://(?:[\w-]+\.)?(?:youtube\.com|youtu\.be|twitch\.tv)/~i';

    /** Whether $url is read by yt-dlp first (mpv's on_load hook for those it includes), else once it failed to open (its on_load_fail). @internal Media\Player */
    public static function first(string $url): bool
    {
        return preg_match(self::FIRST, $url) === 1;
    }

    /**
     * The seconds until a video's formats can be read (yt-dlp's
     * available_at: YouTube's ad before it, which the site makes a client
     * wait out, its URLs refused meanwhile), 0 when they can at once: what
     * mpv's ytdl_hook waits before it opens them.
     * @param array<string, mixed> $info
     * @internal Media\Player
     */
    public static function wait(array $info): float
    {
        $at = 0;
        foreach ($info['requested_formats'] ?? [$info] as $f) {
            if (is_array($f) && is_int($f['available_at'] ?? null)) { $at = max($at, $f['available_at']); }
        }
        return $at > 0 ? max(0.0, $at - microtime(true)) : 0.0;
    }

    /**
     * Of a video's JSON: the URLs to play, each with its protocol, the
     * headers to read it with and the size of the requests yt-dlp makes of
     * it (yt-dlp's requested_formats, a video's and an audio's, else its
     * one format).
     * @param array<string, mixed> $info
     * @return list<array{url: string, protocol: string, headers: array<string, string>, chunk: int}>
     */
    public static function formats(array $info): array
    {
        $out = [];
        foreach ($info['requested_formats'] ?? [$info] as $f) {
            if (!is_array($f) || !is_string($f['url'] ?? null)) {
                throw new RuntimeException('myphp: yt-dlp found no URL of the video');
            }
            $out[] = ['url' => $f['url'], 'protocol' => (string)($f['protocol'] ?? 'https'), 'headers' => array_map('strval', is_array($f['http_headers'] ?? null) ? $f['http_headers'] : []),
                'chunk' => (int)($f['downloader_options']['http_chunk_size'] ?? 0)]; // YouTube's 10 MB: how yt-dlp reads it fast
        }
        return $out;
    }

    /** A video's chapters (yt-dlp's chapters: start_time, title). @param array<string, mixed> $info @return list<Chapter> */
    public static function chapters(array $info): array
    {
        $out = [];
        foreach (is_array($info['chapters'] ?? null) ? $info['chapters'] : [] as $c) {
            $out[] = new Chapter((string)($c['title'] ?? ''), (float)($c['start_time'] ?? 0));
        }
        return $out;
    }

    /**
     * A video's subtitles (yt-dlp's subtitles, not the automatic captions,
     * as mpv's without --write-auto-subs): of each language, the first of
     * the formats the player reads in the order asked (ASS, SRT, WebVTT).
     * @param array<string, mixed> $info
     * @return list<array{language: string, name: string, url: string, ext: string, headers: array<string, string>}>
     */
    public static function subtitles(array $info): array
    {
        $out = [];
        foreach (is_array($info['subtitles'] ?? null) ? $info['subtitles'] : [] as $lang => $list) {
            if (!is_array($list)) { continue; }
            foreach (['ass', 'srt', 'vtt'] as $ext) {
                foreach ($list as $s) {
                    if (is_array($s) && ($s['ext'] ?? '') === $ext && is_string($s['url'] ?? null)) {
                        $out[] = ['language' => (string)$lang, 'name' => (string)($s['name'] ?? $lang), 'url' => $s['url'], 'ext' => $ext,
                            'headers' => array_map('strval', is_array($s['http_headers'] ?? null) ? $s['http_headers'] : [])];
                        continue 3;
                    }
                }
            }
        }
        return $out;
    }

    /**
     * A playlist's entries (flat: each a video's page, read as it plays):
     * their URLs and titles; and the one to play first, the video the URL
     * names besides its list (YouTube's watch?v=…&list=…), as mpv starts there.
     * @param array<string, mixed> $info
     * @return array{0: list<array{0: string, 1: string}>, 1: int}
     */
    public static function entries(array $info, string $url): array
    {
        $out = []; $start = 0;
        parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
        foreach (is_array($info['entries'] ?? null) ? $info['entries'] : [] as $e) {
            $u = is_array($e) ? ($e['url'] ?? $e['webpage_url'] ?? null) : null;
            if (!is_string($u)) { continue; }
            if (isset($q['v']) && ($e['id'] ?? null) === $q['v']) { $start = count($out); }
            $out[] = [$u, (string)($e['title'] ?? $u)];
        }
        return [$out, $start];
    }
}
