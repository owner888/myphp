<?php
declare(strict_types=1);

namespace MyPHP\Plugins;

use RuntimeException;
use MyPHP\Logger;

/**
 * Finds a plugin's native library (mygo's terminal/internal/library and
 * sqlite's library.go, one here): next to the app, in the user's cache, or
 * downloaded there, as the plugin's mygo-plugin.json names it, the same
 * builds as mygo's. $plugin names the plugin in errors ("terminal").
 *
 * @internal
 */
final class NativeLibrary
{
    /** The build of library $name for this platform that the manifest at $manifest names, checked as mygo checks it: a SHA-256 and a plain file name. @return array{name: string, url: string, sha256: string} */
    public static function file(string $manifest, string $name, string $plugin): array
    {
        $n = json_decode((string)file_get_contents($manifest), true, flags: JSON_THROW_ON_ERROR);
        $target = self::target();
        foreach ($n['libraries'] ?? [] as $l) {
            if (($l['name'] ?? '') === $name && isset($l['files'][$target])) {
                $f = $l['files'][$target];
                $f = ['name' => (string)($f['name'] ?? ''), 'url' => (string)($f['url'] ?? ''), 'sha256' => (string)($f['sha256'] ?? '')];
                if (preg_match('/^[0-9a-f]{64}$/', $f['sha256']) !== 1 || $f['url'] === '' || in_array($f['name'], ['', '.', '..'], true) || strpbrk($f['name'], '/\\') !== false) {
                    throw new RuntimeException("$plugin: no valid $name for $target in $manifest");
                }
                return $f;
            }
        }
        throw new RuntimeException("$plugin: $name has no build for $target");
    }

    /** The platform as mygo-plugin.json names it, Go's GOOS-GOARCH. */
    private static function target(): string
    {
        $os = match (PHP_OS_FAMILY) { 'Darwin' => 'darwin', 'Linux' => 'linux', 'Windows' => 'windows', default => strtolower(PHP_OS_FAMILY) };
        $arch = match (strtolower(php_uname('m'))) { 'x86_64', 'amd64' => 'amd64', 'arm64', 'aarch64' => 'arm64', default => strtolower(php_uname('m')) };
        return "$os-$arch";
    }

    /**
     * The library a plugin loads, the first of (mygo's Find and
     * LibraryPath): the file $env names; the one among the app's resources
     * (Contents/Resources of the .app the framework runs from); the one
     * next to the executable (Bundle::executable); the one in the user's cache,
     * where mygo's CLI downloads it (<cache>/mygo/natives/<sha256>/),
     * which programs that are not packaged apps download there when it
     * is missing, checking its SHA-256. Packaged apps never download it.
     */
    public static function find(string $manifest, string $name, string $env, string $plugin): string
    {
        $named = getenv($env);
        if (is_string($named) && $named !== '') {
            return $named;
        }
        $f = self::file($manifest, $name, $plugin);
        $resources = self::resources();
        $dirs = [];
        if ($resources !== null) { $dirs[] = $resources; }
        $dirs[] = dirname(\MyPHP\Native\Bundle::executable());
        $cached = self::cachePath($f);
        $dirs[] = dirname($cached);
        foreach ($dirs as $dir) {
            $p = $dir . '/' . $f['name'];
            if (is_file($p) && ($dir !== dirname($cached) || hash_file('sha256', $p) === $f['sha256'])) {
                return $p;
            }
        }
        if ($resources !== null) {
            throw new RuntimeException("$plugin: {$f['name']} is missing from the app: build it with myphp build");
        }
        self::download($f, $cached, $plugin);
        return $cached;
    }

    /** The Resources of the .app the app runs from (Native\Bundle), or null when it runs from no bundle. */
    private static function resources(): ?string
    {
        $app = \MyPHP\Native\Bundle::path();
        return $app === null ? null : "$app/Contents/Resources";
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
    private static function download(array $f, string $path, string $plugin): void
    {
        if ($f['url'] === '' || $f['sha256'] === '') {
            throw new RuntimeException("$plugin: no download of {$f['name']} for " . self::target());
        }
        Logger::debug("downloading {$f['url']} into " . dirname($path), $plugin);
        $ctx = stream_context_create(['http' => ['timeout' => 120, 'follow_location' => 1, 'max_redirects' => 10, 'ignore_errors' => true]]);
        $in = @fopen($f['url'], 'rb', false, $ctx);
        if ($in === false) {
            throw new RuntimeException("$plugin: downloading {$f['name']}: " . (error_get_last()['message'] ?? 'cannot open'));
        }
        $status = '';
        foreach (stream_get_meta_data($in)['wrapper_data'] ?? [] as $h) {
            if (is_string($h) && str_starts_with($h, 'HTTP/')) { $status = $h; } // the last, after redirects
        }
        if (preg_match('~^HTTP/\S+ 200~', $status) !== 1) {
            fclose($in);
            throw new RuntimeException("$plugin: downloading {$f['url']}: $status");
        }
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            fclose($in);
            throw new RuntimeException("$plugin: cannot make $dir");
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
                throw new RuntimeException("$plugin: {$f['url']} has SHA-256 $sum, not {$f['sha256']}");
            }
            chmod($tmp, 0755);
            if (!rename($tmp, $path)) {
                // Another process downloaded it meanwhile.
                if (is_file($path) && hash_file('sha256', $path) === $f['sha256']) { return; }
                throw new RuntimeException("$plugin: cannot move the download to $path");
            }
        } finally {
            if (is_file($tmp)) { unlink($tmp); }
        }
    }

    /** The handles of the libraries cdef() bound on Linux, kept open as the FFI that uses each is. @var list<\FFI\CData> */
    private static array $bound = [];

    /**
     * FFI::cdef($cdef, $path), the library's own symbols bound inside it.
     * On Linux PHP's CLI (8.5) opens a library RTLD_GLOBAL without
     * RTLD_DEEPBIND, so the calls a library makes to its own exported
     * functions go to the first library loaded that has them: mygo's
     * SQLite called PHP's libsqlite3 (pdo_sqlite's) and crashed. The
     * library is opened first with RTLD_DEEPBIND, which cdef's dlopen of the
     * same path then finds. macOS's two-level namespaces bind a library to
     * its own symbols already.
     */
    public static function cdef(string $cdef, string $path): \FFI
    {
        if (PHP_OS_FAMILY === 'Linux') {
            $dl = \FFI::cdef('void *dlopen(const char *, int); char *dlerror(void);');
            $h = $dl->dlopen($path, 0x2 | 0x8); // RTLD_NOW | RTLD_DEEPBIND (glibc)
            if ($h === null) {
                throw new \FFI\Exception((string)$dl->dlerror());
            }
            self::$bound[] = $h;
        }
        return \FFI::cdef($cdef, $path);
    }
}
