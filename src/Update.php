<?php
declare(strict_types=1);

namespace MyPHP;

use Closure;
use DateTimeImmutable;
use MyPHP\Native\Update\{Archive, Delta, Format, Http};
use RuntimeException;
use Throwable;

/** A newer version of the app, found by Updater::check() (mygo's Update). */
final class Update
{
    /**
     * @internal Updater::check() makes it
     * @param array<string, mixed> $manifest
     */
    public function __construct(
        /** The version of the update. */
        public readonly string $version,
        /** Its release notes, in Markdown. */
        public readonly string $notes,
        /** When it was built, null when the manifest doesn't say. */
        public readonly ?DateTimeImmutable $date,
        private readonly array $manifest,
        private readonly string $key,
        /** The version check compared with, which delta updates update. */
        private readonly string $current,
    ) {}

    /**
     * Downloads the update, checks that it is signed with the app's key and
     * replaces the app with it; $progress, when set, gets the bytes
     * downloaded so far and the total. When the update has a delta for the
     * running version, only the delta is downloaded, unless it fails to
     * make the new version: the whole update is downloaded then, and the
     * progress starts over. The running app is not affected: the new
     * version runs after App::relaunch(), or at the next launch.
     *
     * The app must be able to write where it is installed: a bundle in
     * /Applications of an administrator, or in ~/Applications. It waits as
     * App::go()'s work does (see Updater); $canceled, when set, stops it
     * once it returns true (mygo's context).
     *
     * @param ?Closure(int, int): void $progress
     * @param ?Closure(): bool $canceled
     */
    public function install(?Closure $progress = null, ?Closure $canceled = null): void
    {
        $target = Updater::installTarget();
        try {
            $this->installTo($target, $progress, $canceled);
        } catch (Throwable $e) {
            throw new RuntimeException("myphp: installing the update: {$e->getMessage()}", 0, $e);
        }
    }

    /** @param ?Closure(int, int): void $progress @param ?Closure(): bool $canceled */
    private function installTo(string $target, ?Closure $progress, ?Closure $canceled): void
    {
        $pub = Format::publicKey($this->key);
        // Next to the app, so that it can be renamed into place.
        $work = dirname($target) . '/.' . basename($target) . '.update-' . bin2hex(random_bytes(6));
        if (!@mkdir($work, 0o700)) {
            throw new RuntimeException('cannot write next to the app: ' . (error_get_last()['message'] ?? $work));
        }
        try {
            $unpacked = "$work/app";
            $m = $this->manifest;
            $d = Format::delta($m, $this->current);
            if ($d !== null) {
                try {
                    $this->applyDelta($d, $pub, $target, $work, $unpacked, $progress, $canceled);
                    self::swapBundle($unpacked, $target);
                    return;
                } catch (Native\Update\Canceled $e) {
                    throw $e;
                } catch (Throwable $e) {
                    error_log("myphp: downloading the whole update, as its delta update failed: {$e->getMessage()}");
                    self::removeAll($unpacked);
                }
            }
            $size = (int)($m['size'] ?? 0);
            if ($size <= 0 || $size > Format::MAX_ARCHIVE_SIZE) {
                throw new RuntimeException("invalid size $size");
            }
            $archive = "$work/update.tar.gz";
            self::download((string)$m['url'], $size, (string)$m['signature'], $pub, $archive, $progress, $canceled);
            mkdir($unpacked, 0o755);
            Archive::extract($archive, $unpacked);
            self::swapBundle($unpacked, $target);
        } finally {
            self::removeAll($work);
        }
    }

    /**
     * Downloads the delta $d into $work and makes the new version of the
     * app at $target from it, in $dir/<its bundle's name>, as the bundle of
     * an archive is.
     *
     * @param array{from: string, url: string, size: int, signature: string} $d
     */
    private function applyDelta(array $d, string $pub, string $target, string $work, string $dir, ?Closure $progress, ?Closure $canceled): void
    {
        if ($d['size'] <= 0 || $d['size'] > Format::MAX_ARCHIVE_SIZE) {
            throw new RuntimeException("invalid size {$d['size']}");
        }
        $path = "$work/update.delta";
        self::download($d['url'], $d['size'], $d['signature'], $pub, $path, $progress, $canceled);
        if (!@mkdir($dir, 0o755)) {
            throw new RuntimeException("cannot make $dir");
        }
        Delta::apply($path, $d['size'], $d['from'], $this->version, $target, $dir . '/' . basename($target));
    }

    /** Fetches $url into $path and checks its size and signature. */
    private static function download(string $url, int $size, string $signature, string $pub, string $path, ?Closure $progress, ?Closure $canceled): void
    {
        $digest = Http::download($url, $size, $path, $progress, $canceled);
        Format::verify($pub, $digest, $signature);
    }

    /** Replaces the bundle at $target with the one in $dir. */
    private static function swapBundle(string $dir, string $target): void
    {
        $entries = array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
        if (count($entries) !== 1 || !str_ends_with($entries[0], '.app')) {
            throw new RuntimeException('the update holds no app bundle');
        }
        $old = dirname($target) . '/.' . basename($target) . '.old-' . getmypid();
        if (!@rename($target, $old)) {
            throw new RuntimeException("cannot move $target aside");
        }
        if (!@rename("$dir/{$entries[0]}", $target)) {
            @rename($old, $target);
            throw new RuntimeException("cannot move the update to $target");
        }
        // The running app keeps its open files.
        self::removeAll($old);
    }

    /** Removes $path and what is in it, not following links. */
    public static function removeAll(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $n) {
            self::removeAll("$path/$n");
        }
        @rmdir($path);
    }
}
