<?php
declare(strict_types=1);

namespace MyPHP\Media;

use RuntimeException;

/**
 * OpenSubtitles' REST API (api.opensubtitles.com/api/v1), as IINA's
 * OpenSubClient and OpenSub.Fetcher use it: the languages it has, the
 * subtitles of a film by its hash (a file's) and its name, or by its
 * TMDB id (agc_player's way, and a show's season and episode), and a
 * subtitle's file (the download method's link, then the link's bytes).
 * Each request carries the API key (myphp's own, KEY, as IINA's, or the
 * app's) and goes as Https::request does (the User-Agent "MyPHP", in a
 * fiber the app going on), and throws what fails: the server's
 * message, or OpenSubtitlesQuota once the downloads allowed are spent. An
 * account logged in (login(), IINA's optional OpenSubtitles account) has
 * its token sent too, and its server (a VIP's) asked.
 *
 * @internal UI\VideoPlayerState
 */
final class OpenSubtitles
{
    private const HOST = 'api.opensubtitles.com';
    /**
     * myphp's API key of OpenSubtitles, reversed, as IINA keeps its own
     * (OpenSubClient's apiKey): an app built has it, where anyone can read
     * it, as any key a client is built with; reversed, it is not found by a
     * search of the source for keys.
     */
    private const KEY = 'bcfBKSz8jkLSdYRDgKnoZqIGNcGMVYVn';
    /** OpenSubtitles' sizes of a film it hashes (its wiki's HashSourceCodes: more than 131072 bytes), and the hash's chunks at each end. */
    private const LEAST = 131072, CHUNK = 65536;
    /** @var ?list<string> the languages' codes it has, read once a process (IINA's Fetcher.supportedLanguages) */
    private static ?array $languages = null;
    /** The token of the account logged in, '' for none; the host its requests go to (a VIP's own, the login's base_url). */
    private string $token = '', $host = self::HOST;
    private readonly string $key;

    /** OpenSubtitles of API key $key, myphp's own (KEY) where null. */
    public function __construct(?string $key = null)
    {
        $this->key = $key ?? strrev(self::KEY);
    }

    /** Whether an account is logged in (login()). */
    public function loggedIn(): bool
    {
        return $this->token !== '';
    }

    /**
     * Logs in as $user with $password (the login method, IINA's), its token
     * sent with the requests after, its server asked; the downloads it
     * allows. What fails throws (a wrong password, the server's 401).
     */
    public function login(string $user, string $password): int
    {
        $r = $this->json('POST', 'login', json_encode(['username' => $user, 'password' => $password], JSON_THROW_ON_ERROR));
        $token = $r['token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new RuntimeException('OpenSubtitles: the login gave no token');
        }
        $this->token = $token;
        $host = $r['base_url'] ?? '';
        if (is_string($host) && preg_match('~^[a-z0-9.-]+$~i', $host) === 1) { $this->host = $host; } // IINA's formBaseURL: a hostname, the API's path after it
        return (int)($r['user']['allowed_downloads'] ?? 0);
    }

    /** Logs out (the logout method), the token dropped, as IINA does when its app quits. */
    public function logout(): void
    {
        if ($this->token === '') {
            return;
        }
        try {
            $this->json('DELETE', 'logout');
        } finally {
            [$this->token, $this->host] = ['', self::HOST];
        }
    }

    /**
     * OpenSubtitles' hash of the file at $path (its wiki's HashSourceCodes):
     * its size plus the 64-bit little-endian words of its first and last
     * 64 KB, wrapping; null for a file too small to be hashed.
     */
    public static function hash(string $path): ?string
    {
        clearstatcache(true, $path); // its size now, not PHP's of before (a file still written)
        $size = filesize($path);
        if ($size === false || $size <= self::LEAST) {
            return null;
        }
        $f = fopen($path, 'rb') ?: throw new RuntimeException("cannot read $path");
        try {
            [$lo, $hi] = [$size & 0xFFFFFFFF, $size >> 32]; // two halves of 32 bits: PHP's ints have no 64-bit unsigned sum
            foreach ([0, $size - self::CHUNK] as $at) {
                fseek($f, $at);
                $words = unpack('V*', (string)fread($f, self::CHUNK)) ?: [];
                for ($i = 1, $n = count($words); $i < $n; $i += 2) {
                    $lo += $words[$i];
                    $hi += $words[$i + 1] + ($lo >> 32);
                    $lo &= 0xFFFFFFFF; $hi &= 0xFFFFFFFF;
                }
            }
        } finally {
            fclose($f);
        }
        return sprintf('%08x%08x', $hi, $lo);
    }

    /**
     * The languages to search in of BCP 47 tags $tags (the system's, in
     * its order), as OpenSubtitles' codes $supported have them: Chinese
     * zh-cn of the simplified script (and China's, Singapore's), zh-tw of
     * the traditional; Portuguese pt-br of Brazil's, pt-pt else; the rest
     * by their language. Those it has none of left out, as IINA's
     * filterLanguageCodes; English where none is left.
     * @param list<string> $tags
     * @param list<string> $supported
     * @return list<string>
     */
    public static function languagesOf(array $tags, array $supported): array
    {
        $out = [];
        foreach ($tags as $tag) {
            $parts = explode('-', strtolower(str_replace('_', '-', $tag)));
            $code = match ($parts[0]) {
                'zh' => array_intersect($parts, ['hant', 'tw', 'hk', 'mo']) !== [] ? 'zh-tw' : 'zh-cn',
                'pt' => in_array('br', $parts, true) ? 'pt-br' : 'pt-pt',
                default => $parts[0],
            };
            if (in_array($code, $supported, true) && !in_array($code, $out, true)) { $out[] = $code; }
        }
        return $out === [] ? ['en'] : $out;
    }

    /** The codes of the languages it has subtitles in (infos/languages): en, zh-cn, pt-br… @return list<string> */
    public function languages(): array
    {
        if (self::$languages === null) {
            $codes = [];
            foreach ($this->json('GET', 'infos/languages')['data'] ?? [] as $l) {
                if (is_string($l['language_code'] ?? null)) { $codes[] = $l['language_code']; }
            }
            self::$languages = $codes;
        }
        return self::$languages;
    }

    /**
     * The subtitles in $languages of a film of hash $hash (null: a stream's)
     * matching $query (its file's name, or its title), as IINA lists them:
     * each one's file, its name, language, year, frames a second,
     * downloads and the day it was uploaded.
     * @param list<string> $languages
     * @return list<array{file: int, name: string, language: string, year: int, fps: float, downloads: int, uploaded: string}>
     */
    public function search(array $languages, ?string $hash, string $query): array
    {
        sort($languages);
        $params = ['languages' => implode(',', $languages)]; // in the order of their names, the API's best practice (others redirect)
        if ($hash !== null) { $params['moviehash'] = $hash; }
        $params['query'] = strtolower($query);
        return self::results($this->json('GET', 'subtitles?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986)));
    }

    /**
     * The subtitles in $languages of the film of TMDB id $tmdb, or of the
     * show's ($tv) season $season (null: any) and episode $episode (null:
     * any), as search() gives them.
     * @param list<string> $languages
     * @return list<array{file: int, name: string, language: string, year: int, fps: float, downloads: int, uploaded: string}>
     */
    public function searchTmdb(array $languages, int $tmdb, bool $tv, ?int $season, ?int $episode): array
    {
        sort($languages);
        $params = $tv
            ? array_filter(['episode_number' => $episode, 'languages' => implode(',', $languages), 'parent_tmdb_id' => $tmdb, 'season_number' => $season], fn($v) => $v !== null)
            : ['languages' => implode(',', $languages), 'tmdb_id' => $tmdb]; // in the order of their names, as search()'s
        return self::results($this->json('GET', 'subtitles?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986)));
    }

    /**
     * The subtitles of a search's response, as search() gives them.
     * @param array<mixed> $r
     * @return list<array{file: int, name: string, language: string, year: int, fps: float, downloads: int, uploaded: string}>
     */
    private static function results(array $r): array
    {
        $out = [];
        foreach ($r['data'] ?? [] as $s) {
            $a = $s['attributes'] ?? [];
            $file = $a['files'][0] ?? null;
            if (($s['type'] ?? '') !== 'subtitle' || !is_array($file) || !is_int($file['file_id'] ?? null)) {
                continue; // IINA's: what is no subtitle, or has no file, left out
            }
            $out[] = [
                'file' => $file['file_id'], 'name' => (string)($file['file_name'] ?? ''), 'language' => (string)($a['language'] ?? ''),
                'year' => (int)($a['feature_details']['year'] ?? 0), 'fps' => (float)($a['fps'] ?? 0), 'downloads' => (int)($a['download_count'] ?? 0),
                'uploaded' => (string)($a['upload_date'] ?? ''),
            ];
        }
        return $out;
    }

    /** Subtitle file $file (search()'s): its name and its bytes, by the download method's link. @return array{0: string, 1: string} */
    public function download(int $file): array
    {
        $r = $this->json('POST', 'download', json_encode(['file_id' => $file], JSON_THROW_ON_ERROR));
        $link = $r['link'] ?? null;
        if (!is_string($link) || $link === '') {
            throw new RuntimeException('OpenSubtitles: no link to download subtitle file ' . $file);
        }
        [$status, $data] = $this->request('GET', $link, null, false);
        if ($status !== 200 || $data === '') {
            throw new RuntimeException("OpenSubtitles: the subtitle file \"{$r['file_name']}\" " . ($status !== 200 ? "came with HTTP $status" : 'is empty'));
        }
        return [(string)($r['file_name'] ?? "$file.srt"), $data];
    }

    /**
     * The JSON of API method $method (relative to BASE), with $body; what is
     * no success throws: the downloads spent (406, or remaining -1) as
     * OpenSubtitlesQuota, else the server's message.
     * @return array<mixed>
     */
    private function json(string $verb, string $method, ?string $body = null): array
    {
        [$status, $data] = $this->request($verb, "https://{$this->host}/api/v1/$method", $body, true);
        $r = json_decode($data, true);
        if ($status === 406 || (is_array($r) && ($r['remaining'] ?? 0) === -1)) {
            $reset = is_array($r) && is_string($r['reset_time_utc'] ?? null) ? strtotime($r['reset_time_utc']) : false;
            throw new OpenSubtitlesQuota($reset === false ? null : $reset);
        }
        if ($status < 200 || $status >= 300 || !is_array($r)) {
            $why = is_array($r) ? (string)($r['message'] ?? $r['errors'][0] ?? '') : '';
            throw new RuntimeException("OpenSubtitles: HTTP $status" . ($why !== '' ? ": $why" : ''));
        }
        return $r;
    }

    /** $verb of $url, the API's headers with it ($api), $body as JSON: its status and body. @return array{0: int, 1: string} */
    private function request(string $verb, string $url, ?string $body, bool $api): array
    {
        $headers = $api ? ['Api-Key' => $this->key, 'Accept' => 'application/json', 'Content-Type' => 'application/json'] + ($this->token !== '' ? ['Authorization' => "Bearer {$this->token}"] : []) : [];
        return Https::request($verb, $url, $headers, $body, 'OpenSubtitles');
    }
}
