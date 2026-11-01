<?php
declare(strict_types=1);

namespace MyPHP\Media;

use RuntimeException;

/**
 * assrt.net's API (api.assrt.net/v1), as IINA's Assrt.Fetcher uses it: the
 * subtitles of a film by its name (sub/search), a subtitle's files
 * (sub/detail: its archive's files, else its own) and their bytes. Each
 * request carries a token: the user's (IINA's Assrt API token setting, 32
 * characters), else IINA's own for trying it, of a small quota. It goes as
 * Https::request does and throws what fails: assrt.net's status by IINA's
 * name of it, else the HTTP status.
 *
 * @internal UI\MediaPlayerState
 */
final class Assrt
{
    private const API = 'https://api.assrt.net/v1/sub/';
    /** IINA's token for those of none of their own (Assrt.Fetcher's), reversed as OpenSubtitles' key is kept. */
    private const DEMO = 'vUBodx9eSRdwXQCE69Amv990J2brWzI5';
    /** Where a token is had (IINA's AppData.assrtRegisterLink), and IINA's wiki of it (its help button). */
    public const REGISTER = 'https://secure.assrt.net/user/register.xml?redir=http%3A%2F%2Fassrt.net%2Fusercp.php';
    public const HELP = 'https://github.com/iina/iina/wiki/Download-Online-Subtitles#assrt';
    /** assrt.net's statuses that are errors, by IINA's names (Assrt.Error). */
    private const ERRORS = [1 => 'noSuchUser', 101 => 'queryTooShort', 20000 => 'missingArg', 20001 => 'invalidToken', 20400 => 'endPointNotFound',
        20900 => 'subNotFound', 30000 => 'serverError', 30001 => 'databaseError', 30002 => 'searchEngineError', 30300 => 'tempUnavailable', 30900 => 'exceedLimit'];

    private readonly string $token;

    /** assrt.net of the user's token $token, IINA's own unless it is one (32 characters). */
    public function __construct(string $token = '')
    {
        $this->token = self::valid($token) ? $token : strrev(self::DEMO);
    }

    /** Whether $token may be a user's (IINA's check: 32 characters). */
    public static function valid(string $token): bool
    {
        return strlen($token) === 32;
    }

    /**
     * The subtitles of $query (a film's file's name), as IINA lists them:
     * their id, native name ("[No title]" for none), kind and language
     * (those of none left out), and when they were uploaded.
     * @return list<array{id: int, name: string, about: string, uploaded: string}>
     */
    public function search(string $query): array
    {
        return self::results($this->json('search', ['q' => $query, 'is_file' => 1, 'no_muxer' => 1]));
    }

    /**
     * The subtitles of a search's response, as search() gives them: none of
     * `sub: {subs: {}}`, assrt.net's way of saying so. assrt.net answers a
     * search in either of two shapes, the same query now one, now the
     * other: its API's documented fields, which IINA reads (id,
     * native_name, subtype, lang's desc, upload_time), or its site's
     * (fileid, video_chinese_name, which the detail calls native_name,
     * m_subtype, m_lang, uploadtime); each row read by the fields it has,
     * HTML's entities undone (m_lang's &nbsp;).
     * @param array<mixed> $r
     * @return list<array{id: int, name: string, about: string, uploaded: string}>
     */
    public static function results(array $r): array
    {
        $subs = $r['sub']['subs'] ?? null;
        if (!is_array($subs) || ($subs !== [] && !array_is_list($subs))) {
            return [];
        }
        $text = fn(mixed $v): string => trim(str_replace("\u{a0}", ' ', html_entity_decode((string)$v, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $out = [];
        foreach ($subs as $s) {
            [$id, $name, $kind, $lang, $uploaded] = match (true) {
                is_int($s['id'] ?? null) => [$s['id'], $s['native_name'] ?? '', $s['subtype'] ?? '', $s['lang']['desc'] ?? '', $s['upload_time'] ?? ''],
                is_numeric($s['fileid'] ?? null) => [(int)$s['fileid'], $s['video_chinese_name'] ?? '', $s['m_subtype'] ?? '', $s['m_lang'] ?? '', $s['uploadtime'] ?? ''],
                default => throw new RuntimeException('assrt.net: wrongResponseFormat'),
            };
            [$name, $kind] = [$text($name), $text($kind)];
            $out[] = ['id' => $id, 'name' => $name !== '' ? $name : '[No title]', 'about' => ($kind !== '' ? $kind : 'Unknown') . ' ' . $text($lang), 'uploaded' => (string)$uploaded];
        }
        return $out;
    }

    /**
     * Subtitle $id's files (sub/detail): those in its archive (its file
     * list), else its own; each its link and name.
     * @return list<array{0: string, 1: string}>
     */
    public function files(int $id): array
    {
        return self::filesOf($this->json('detail', ['id' => $id]));
    }

    /**
     * The files of a detail's response, as files() gives them.
     * @param array<mixed> $r
     * @return list<array{0: string, 1: string}>
     */
    public static function filesOf(array $r): array
    {
        $subs = $r['sub']['subs'] ?? null;
        if (!is_array($subs) || count($subs) !== 1 || !is_array($sub = $subs[0] ?? null)) {
            throw new RuntimeException('assrt.net: wrongResponseFormat');
        }
        if (is_array($sub['filelist'] ?? null) && $sub['filelist'] !== []) {
            return array_map(fn(array $f): array => [(string)$f['url'], (string)$f['f']], $sub['filelist']);
        }
        if (!is_string($sub['url'] ?? null) || !is_string($sub['filename'] ?? null)) {
            throw new RuntimeException('assrt.net: networkError');
        }
        return [[$sub['url'], $sub['filename']]];
    }

    /** The bytes of a file at $url (files()'s, of HTTP). */
    public function download(string $url): string
    {
        [$status, $data] = Https::request('GET', $url, [], null, 'assrt.net', plain: true); // its files' server answers HTTPS with an error
        if ($status !== 200) {
            throw new RuntimeException("assrt.net: HTTP $status");
        }
        return $data;
    }

    /**
     * Method $method's JSON, of $params posted as a form; a status of
     * assrt.net's errors throws, by its name.
     * @param array<string, int|string> $params
     * @return array<mixed>
     */
    private function json(string $method, array $params): array
    {
        [$status, $data] = Https::request('POST', self::API . $method, ['Authorization' => "Bearer {$this->token}"], http_build_query($params), 'assrt.net');
        $r = json_decode($data, true);
        if (!is_array($r)) {
            throw new RuntimeException("assrt.net: HTTP $status");
        }
        if (!is_int($r['status'] ?? null)) {
            throw new RuntimeException('assrt.net: wrongResponseFormat');
        }
        if (isset(self::ERRORS[$r['status']])) {
            throw new RuntimeException('assrt.net: ' . self::ERRORS[$r['status']] . " ({$r['status']})");
        }
        return $r;
    }
}
