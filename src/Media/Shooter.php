<?php
declare(strict_types=1);

namespace MyPHP\Media;

use RuntimeException;

/**
 * shooter.cn's API (www.shooter.cn/api/subapi.php), as IINA's
 * Shooter.Fetcher uses it: the subtitles of a film by its file's hash
 * (four MD5s of 4 KB at its quarters) and path, each its description,
 * delay and files; a file's bytes and name. It goes as Https::request
 * does and throws what fails.
 *
 * @internal UI\MediaPlayerState
 */
final class Shooter
{
    private const API = 'https://www.shooter.cn/api/subapi.php';
    /** The least size of a file shooter.cn hashes (Shooter.Fetcher's minimumFileSize), and a chunk hashed. */
    public const LEAST = 12288;
    private const CHUNK = 4096;

    /**
     * Shooter's hash of the file at $path: the MD5s of 4 KB at 4096, two
     * thirds, a third and 8 KB before its end, joined by ";"; null of a
     * file smaller than LEAST. One that cannot be read throws.
     */
    public static function hash(string $path): ?string
    {
        $f = @fopen($path, 'rb');
        if ($f === false) {
            throw new RuntimeException("shooter.cn: cannot read $path");
        }
        try {
            $size = fstat($f)['size'];
            if ($size < self::LEAST) {
                return null;
            }
            return implode(';', array_map(function (int $at) use ($f): string {
                fseek($f, $at);
                return md5((string)fread($f, self::CHUNK));
            }, [4096, intdiv($size, 3) * 2, intdiv($size, 3), $size - 8192]));
        } finally {
            fclose($f);
        }
    }

    /**
     * The subtitles of the file at $path, of hash $hash (hash()'s), as
     * IINA reads them: none where the answer is no JSON (shooter.cn's way
     * of saying none).
     * @return list<array{desc: string, delay: int, files: list<array{ext: string, link: string}>}>
     */
    public function search(string $hash, string $path): array
    {
        [$status, $data] = Https::request('POST', self::API, [], http_build_query(['filehash' => $hash, 'pathinfo' => $path, 'format' => 'json']), 'shooter.cn');
        if ($status !== 200) {
            throw new RuntimeException("shooter.cn: HTTP $status");
        }
        return self::results(json_decode($data, true));
    }

    /**
     * The subtitles of a search's response, as search() gives them; those
     * of no file left out.
     * @return list<array{desc: string, delay: int, files: list<array{ext: string, link: string}>}>
     */
    public static function results(mixed $r): array
    {
        if (!is_array($r) || !array_is_list($r)) {
            return [];
        }
        $out = [];
        foreach ($r as $sub) {
            $files = array_map(fn(array $f): array => ['ext' => (string)($f['Ext'] ?? ''), 'link' => (string)($f['Link'] ?? '')], $sub['Files'] ?? []);
            if ($files === []) { continue; }
            $out[] = ['desc' => (string)($sub['Desc'] ?? ''), 'delay' => (int)($sub['Delay'] ?? 0), 'files' => $files];
        }
        return $out;
    }

    /** The file at $link (a subtitle's): its name, the server's (Content-Disposition) else the link's, and its bytes. @return array{0: string, 1: string} */
    public function download(string $link): array
    {
        [$status, $data, $headers] = Https::request('GET', $link, [], null, 'shooter.cn');
        if ($status !== 200) {
            throw new RuntimeException("shooter.cn: HTTP $status");
        }
        return [self::fileName($headers['content-disposition'] ?? '', $link), $data];
    }

    /** A file's name, as a response tells it: Content-Disposition's filename* (UTF-8) or filename, else the last part of the link's path. */
    public static function fileName(string $disposition, string $link): string
    {
        if (preg_match("~filename\\*\\s*=\\s*UTF-8''([^;]+)~i", $disposition, $m) === 1) {
            return basename(rawurldecode(trim($m[1])));
        }
        if (preg_match('~filename\s*=\s*"?([^";]+)"?~i', $disposition, $m) === 1) {
            return basename(trim($m[1]));
        }
        return basename(rawurldecode((string)parse_url($link, PHP_URL_PATH)));
    }
}
