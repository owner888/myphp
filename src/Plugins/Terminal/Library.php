<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Terminal;

use RuntimeException;

/**
 * Finds libghostty-vt for the terminal (mygo's terminal/internal/library):
 * next to the app, in the user's cache, or downloaded there, as
 * mygo-plugin.json names it, the same builds as mygo's.
 *
 * @internal
 */
final class Library
{
    /** The build of libghostty-vt for this platform that mygo-plugin.json names. @return array{name: string, url: string, sha256: string} */
    public static function file(): array
    {
        $n = json_decode((string)file_get_contents(__DIR__ . '/mygo-plugin.json'), true, flags: JSON_THROW_ON_ERROR);
        $target = self::target();
        foreach ($n['libraries'] ?? [] as $l) {
            if (($l['name'] ?? '') === 'libghostty-vt' && isset($l['files'][$target])) {
                $f = $l['files'][$target];
                return ['name' => (string)$f['name'], 'url' => (string)($f['url'] ?? ''), 'sha256' => (string)($f['sha256'] ?? '')];
            }
        }
        throw new RuntimeException("terminal: libghostty-vt has no build for $target");
    }

    /** The platform as mygo-plugin.json names it, Go's GOOS-GOARCH. */
    private static function target(): string
    {
        $os = match (PHP_OS_FAMILY) { 'Darwin' => 'darwin', 'Linux' => 'linux', 'Windows' => 'windows', default => strtolower(PHP_OS_FAMILY) };
        $arch = match (strtolower(php_uname('m'))) { 'x86_64', 'amd64' => 'amd64', 'arm64', 'aarch64' => 'arm64', default => strtolower(php_uname('m')) };
        return "$os-$arch";
    }

    /**
     * The libghostty-vt the terminal loads, the first of (mygo's Find):
     * the file $MYGO_GHOSTTY_VT names; the one among the app's resources
     * (Contents/Resources of the .app the framework runs from); the one
     * next to the executable (PHP_BINARY); the one in the user's cache,
     * where mygo's CLI downloads it (<cache>/mygo/natives/<sha256>/),
     * which programs that are not packaged apps download there when it
     * is missing, checking its SHA-256. Packaged apps never download it.
     */
    public static function find(): string
    {
        $env = getenv('MYGO_GHOSTTY_VT');
        if (is_string($env) && $env !== '') {
            return $env;
        }
        $f = self::file();
        $resources = self::resources();
        $dirs = [];
        if ($resources !== null) { $dirs[] = $resources; }
        $dirs[] = dirname(PHP_BINARY);
        $cached = self::cachePath($f);
        $dirs[] = dirname($cached);
        foreach ($dirs as $dir) {
            $p = $dir . '/' . $f['name'];
            if (is_file($p)) {
                return $p;
            }
        }
        if ($resources !== null) {
            throw new RuntimeException("terminal: {$f['name']} is missing from the app: build it with myphp build");
        }
        self::download($f, $cached);
        return $cached;
    }

    /** The Resources of the .app the framework runs from, as `myphp build` lays it out (Contents/Resources/app/myphp/src, or the phar of a static app's Contents/MacOS/<Name>), or null when it runs from no bundle. */
    private static function resources(): ?string
    {
        $phar = class_exists(\Phar::class) ? \Phar::running(false) : '';
        if ($phar !== '' && preg_match('~^(.*\.app/Contents)/MacOS/[^/]+$~', $phar, $m) === 1) {
            return $m[1] . '/Resources';
        }
        $src = realpath(__DIR__ . '/../..');
        if ($src !== false && preg_match('~^(.*\.app/Contents/Resources)/app/~', $src . '/', $m) === 1) {
            return $m[1];
        }
        return null;
    }

    /** Where mygo's CLI and this keep a downloaded library (Go's os.UserCacheDir). @param array{name: string, sha256: string} $f */
    private static function cachePath(array $f): string
    {
        if (PHP_OS_FAMILY === 'Darwin') {
            $dir = getenv('HOME') . '/Library/Caches';
        } else {
            $xdg = getenv('XDG_CACHE_HOME');
            $dir = is_string($xdg) && $xdg !== '' ? $xdg : getenv('HOME') . '/.cache';
        }
        return "$dir/mygo/natives/{$f['sha256']}/{$f['name']}";
    }

    /** Downloads $f to $path, checking its SHA-256. @param array{name: string, url: string, sha256: string} $f */
    private static function download(array $f, string $path): void
    {
        if ($f['url'] === '' || $f['sha256'] === '') {
            throw new RuntimeException("terminal: no download of {$f['name']} for " . self::target());
        }
        error_log("terminal: downloading {$f['url']} into " . dirname($path));
        $ctx = stream_context_create(['http' => ['timeout' => 120, 'follow_location' => 1, 'max_redirects' => 10, 'ignore_errors' => true]]);
        $in = @fopen($f['url'], 'rb', false, $ctx);
        if ($in === false) {
            throw new RuntimeException("terminal: downloading {$f['name']}: " . (error_get_last()['message'] ?? 'cannot open'));
        }
        $status = '';
        foreach (stream_get_meta_data($in)['wrapper_data'] ?? [] as $h) {
            if (is_string($h) && str_starts_with($h, 'HTTP/')) { $status = $h; } // the last, after redirects
        }
        if (preg_match('~^HTTP/\S+ 200~', $status) !== 1) {
            fclose($in);
            throw new RuntimeException("terminal: downloading {$f['url']}: $status");
        }
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            fclose($in);
            throw new RuntimeException("terminal: cannot make $dir");
        }
        $tmp = tempnam($dir, '.download-');
        $out = fopen($tmp, 'wb');
        $h = hash_init('sha256');
        $n = 0;
        while (!feof($in)) {
            $b = fread($in, 1 << 16);
            if ($b === false) { break; }
            $n += strlen($b);
            if ($n > 64 << 20) { break; }
            hash_update($h, $b);
            fwrite($out, $b);
        }
        fclose($in);
        fclose($out);
        try {
            if (($sum = hash_final($h)) !== $f['sha256']) {
                throw new RuntimeException("terminal: {$f['url']} has SHA-256 $sum, not {$f['sha256']}");
            }
            chmod($tmp, 0755);
            if (!rename($tmp, $path)) {
                // Another process downloaded it meanwhile.
                if (is_file($path) && hash_file('sha256', $path) === $f['sha256']) { return; }
                throw new RuntimeException("terminal: cannot move the download to $path");
            }
        } finally {
            if (is_file($tmp)) { unlink($tmp); }
        }
    }
}
