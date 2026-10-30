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
        /** Where the manifest came from, which serves its files (Updater::setSource); null for the feed. */
        private readonly ?UpdateSource $source = null,
    ) {}

    /**
     * Downloads the update, checks that it is signed with the app's key and
     * replaces the app with it; $progress, when set, gets the bytes
     * downloaded so far and the total. When the update has a delta for the
     * running version, only the delta is downloaded, unless it fails to
     * make the new version: the whole update is downloaded then, and the
     * progress starts over. The running app is not affected: the new
     * bundle replaces the app's once the app has exited, so that the Dock
     * keeps its tile, and runs after App::relaunch(), or at the next
     * launch.
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
        $staged = false;
        try {
            $unpacked = "$work/app";
            $m = $this->manifest;
            $d = Format::delta($m, $this->current);
            if ($d !== null) {
                try {
                    $this->applyDelta($d, $pub, $target, $work, $unpacked, $progress, $canceled);
                    self::stageBundle($work, $unpacked, $target);
                    $staged = true;
                    return;
                } catch (Native\Update\Canceled $e) {
                    throw $e;
                } catch (Throwable $e) {
                    Logger::warn("downloading the whole update, as its delta update failed: {$e->getMessage()}", 'update');
                    self::removeAll($unpacked);
                }
            }
            $size = (int)($m['size'] ?? 0);
            if ($size <= 0 || $size > Format::MAX_ARCHIVE_SIZE) {
                throw new RuntimeException("invalid size $size");
            }
            $archive = "$work/update.tar.gz";
            $this->download((string)$m['url'], $size, (string)$m['signature'], $pub, $archive, $progress, $canceled);
            mkdir($unpacked, 0o755);
            Archive::extract($archive, $unpacked);
            self::stageBundle($work, $unpacked, $target);
            $staged = true;
        } finally {
            if (!$staged) { self::removeAll($work); } // a staged update's goes as the helper installs it
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
        $this->download($d['url'], $d['size'], $d['signature'], $pub, $path, $progress, $canceled);
        if (!@mkdir($dir, 0o755)) {
            throw new RuntimeException("cannot make $dir");
        }
        Delta::apply($path, $d['size'], $d['from'], $this->version, $target, $dir . '/' . basename($target));
    }

    /** Fetches $url into $path, from the feed or the source the update came from, and checks its size and signature. */
    private function download(string $url, int $size, string $signature, string $pub, string $path, ?Closure $progress, ?Closure $canceled): void
    {
        $digest = $this->source === null ? Http::download($url, $size, $path, $progress, $canceled)
            : Http::downloadStream($this->source->open($url, $canceled), $size, $path, $progress, $canceled);
        Format::verify($pub, $digest, $signature);
    }

    /**
     * @internal tests: the process whose exit a staged update waits for, the
     * app's (0); tests give one of their own.
     */
    public static int $appPid = 0;

    /** The update staged (stageBundle): the directory it was made in, and the pid of the helper that installs it. @var ?array{0: string, 1: int} */
    private static ?array $staged = null;

    /**
     * Leaves the bundle in $dir to replace the one at $target once the app
     * has exited, however it exits, in place of an update staged before
     * (mygo's stageBundle): replacing it while the app ran moved the
     * running app's bundle away, the Dock's tile followed it, and the new
     * version, opened where the app was, got a second tile. A helper of
     * /bin/sh, detached, waits for the app's process to end, swaps the
     * bundles (the old one back if the new one cannot be moved in), removes
     * $work and opens the app again when App::relaunch() asked
     * (relaunchStaged).
     */
    private static function stageBundle(string $work, string $dir, string $target): void
    {
        $entries = array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
        if (count($entries) !== 1 || !str_ends_with($entries[0], '.app')) {
            throw new RuntimeException('the update holds no app bundle');
        }
        $args = [(string)(self::$appPid ?: getmypid()), $target, "$dir/{$entries[0]}", $work, $target];
        $rest = array_slice($_SERVER['argv'] ?? [], 1);
        if ($rest !== []) { array_push($args, '--args', ...$rest); }
        $pid = (int)exec('nohup /bin/sh -c ' . escapeshellarg(self::INSTALL_AFTER_EXIT) . ' myphp-update ' . implode(' ', array_map('escapeshellarg', $args)) . ' > /dev/null 2>&1 & echo $!');
        if ($pid <= 0) {
            throw new RuntimeException('cannot start the helper that installs the update');
        }
        if (self::$staged !== null) { // the update staged before gives way
            exec('kill ' . self::$staged[1] . ' 2> /dev/null');
            self::removeAll(self::$staged[0]);
        }
        self::$staged = [$work, $pid];
    }

    /**
     * The helper's script (mygo's installAfterExit): with the app's pid,
     * the target, the new bundle and the work directory, then open's
     * arguments, it waits for the app to exit, swaps the bundles, and opens
     * the app through Launch Services when it was asked to, without -n: the
     * app has exited, and the Dock gives the new version the app's tile.
     */
    private const INSTALL_AFTER_EXIT = <<<'SH'
        pid=$1 target=$2 new=$3 work=$4
        shift 4
        trap '' HUP
        while kill -0 "$pid" 2>/dev/null; do sleep 0.05; done
        if mv "$target" "$work/old"; then
            mv "$new" "$target" || mv "$work/old" "$target"
        fi
        relaunch=
        if [ -e "$work/relaunch" ]; then relaunch=1; fi
        rm -rf "$work"
        if [ -n "$relaunch" ]; then exec /usr/bin/open "$@"; fi
        SH;

    /** @internal App::relaunch(): has the helper of the update staged open the app once it installed it; whether one is staged. */
    public static function relaunchStaged(): bool
    {
        return self::$staged !== null && @file_put_contents(self::$staged[0] . '/relaunch', '') !== false;
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
