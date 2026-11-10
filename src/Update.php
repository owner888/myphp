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
                    self::stage($work, $unpacked, $target);
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
            self::stage($work, $unpacked, $target);
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
        if (self::flat()) { // the app's directory made anew, as its archive holds it
            Delta::apply($path, $d['size'], $d['from'], $this->version, $target, $dir);
            return;
        }
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

    /** The update staged (stageBundle, stageFlat): the directory it was made in, and the pid of the helper that installs it. @var ?array{0: string, 1: int} */
    private static ?array $staged = null;

    /** Whether the app is a directory of files the update replaces one by one (Windows's), rather than a bundle. */
    private static function flat(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }

    private static function stage(string $work, string $dir, string $target): void
    {
        self::flat() ? self::stageFlat($work, $dir, $target) : self::stageBundle($work, $dir, $target);
    }

    /**
     * Leaves the files in $dir to replace those of the app's directory
     * $target once the app has exited (mygo's swapFiles, after the exit as
     * Windows keeps a running program's files): a PowerShell helper,
     * detached (Native\Detached), waits for the app's process and the
     * others running from $target (the launcher), moves each file of the
     * update in, the old one aside and back if one cannot be moved, keeps
     * those the update does not hold (the uninstaller), removes $work and
     * opens the app again when App::relaunch() asked (relaunchStaged).
     */
    private static function stageFlat(string $work, string $dir, string $target): void
    {
        if ((array_diff(scandir($dir) ?: [], ['.', '..'])) === []) {
            throw new RuntimeException('the update is empty');
        }
        $exe = Native\Bundle::info('CFBundleExecutable') ?? throw new RuntimeException('the app names no executable (info.json)');
        $script = sys_get_temp_dir() . '/myphp-update-' . bin2hex(random_bytes(6)) . '.ps1';
        file_put_contents($script, self::SWAP_AFTER_EXIT);
        $ps = rtrim((string)getenv('SystemRoot'), '\\/') . '\\System32\\WindowsPowerShell\\v1.0\\powershell.exe';
        $args = [$ps, '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File', $script, '-AppPid', (string)(self::$appPid ?: getmypid()),
            '-Target', str_replace('/', '\\', $target), '-New', str_replace('/', '\\', $dir), '-Work', str_replace('/', '\\', $work), '-Exe', $exe, ...array_slice($_SERVER['argv'] ?? [], 1)];
        $pid = Native\Detached::start($args);
        if (self::$staged !== null) { // the update staged before gives way
            Native\Detached::kill(self::$staged[1]);
            self::removeAll(self::$staged[0]);
        }
        self::$staged = [$work, $pid];
    }

    /** The helper of stageFlat (mygo's swapFiles, run once the app exited); it removes its own script. */
    private const SWAP_AFTER_EXIT = <<<'PS'
        param([int]$AppPid, [string]$Target, [string]$New, [string]$Work, [string]$Exe)
        $ErrorActionPreference = 'SilentlyContinue'
        try { (Get-Process -Id $AppPid -ErrorAction Stop).WaitForExit() } catch {}
        # The launcher, and whatever else runs from the app's folder, exits too.
        $dir = $Target.TrimEnd('') + ''
        for ($i = 0; $i -lt 300; $i++) {
        	$left = @(Get-CimInstance Win32_Process | Where-Object { $_.ExecutablePath -and $_.ProcessId -ne $PID -and $_.ExecutablePath.StartsWith($dir, [StringComparison]::OrdinalIgnoreCase) })
        	if ($left.Count -eq 0) { break }
        	Start-Sleep -Milliseconds 100
        }
        $suffix = '.old-' + $PID
        $moved = New-Object System.Collections.ArrayList
        $ok = $true
        foreach ($e in @(Get-ChildItem -LiteralPath $New -Force)) {
        	$dst = Join-Path $Target $e.Name
        	if (Test-Path -LiteralPath $dst) {
        		$aside = Join-Path $Target ('.' + $e.Name + $suffix)
        		try { Move-Item -LiteralPath $dst -Destination $aside -ErrorAction Stop } catch { $ok = $false; break }
        		[void]$moved.Add(@($dst, $aside))
        	}
        	try { Move-Item -LiteralPath $e.FullName -Destination $dst -ErrorAction Stop } catch { $ok = $false; break }
        }
        if ($ok) {
        	foreach ($m in $moved) { Remove-Item -LiteralPath $m[1] -Recurse -Force }
        } else {
        	for ($i = $moved.Count - 1; $i -ge 0; $i--) {
        		$m = $moved[$i]
        		Remove-Item -LiteralPath $m[0] -Recurse -Force
        		Move-Item -LiteralPath $m[1] -Destination $m[0]
        	}
        }
        $relaunch = Test-Path -LiteralPath (Join-Path $Work 'relaunch')
        Remove-Item -LiteralPath $Work -Recurse -Force
        Remove-Item -LiteralPath $PSCommandPath -Force
        if ($relaunch) {
        	if ($args.Count -gt 0) { Start-Process -FilePath (Join-Path $Target $Exe) -ArgumentList $args } else { Start-Process -FilePath (Join-Path $Target $Exe) }
        }
        PS;

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
