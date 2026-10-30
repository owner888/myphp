<?php
declare(strict_types=1);

namespace MyPHP\Native\Build;

use Closure;
use RuntimeException;

/**
 * The disk image of a macOS app (mygo's dmg.go): compressed and read-only,
 * opening to the app next to a link to /Applications, the Finder layout
 * from a generated .DS_Store (DsStore), the app's icon as the volume's;
 * signed with a real identity, notarized and stapled when the
 * configuration asks. Only tools that ship with macOS are needed.
 *
 * @internal
 */
final class Dmg
{
    /** The Finder flag of folders with a custom icon. */
    private const HAS_CUSTOM_ICON = 0x0400;

    /** $s usable as a file name on every platform (mygo's fsName). */
    public static function fsName(string $s): string
    {
        return (string)preg_replace('/[\x00-\x1f\/\\\\:*?"<>|]/u', '-', trim($s));
    }

    /**
     * "<Name> <Version> <arch>.dmg", as "My App 1.2.0 arm64.dmg", or
     * "<Name> <Version>.dmg" for a universal app, which is for every Mac:
     * each architecture's disk image is named apart, so that the builds of
     * two, uploaded to one release or bucket, keep both.
     */
    public static function fileName(string $name, string $version, string $arch): string
    {
        return self::fsName($name) . ' ' . self::fsName($version) . ($arch === 'universal' ? '' : " $arch") . '.dmg';
    }

    /**
     * Packages $app, built for $arch, into a disk image in $dir and returns
     * its path. $sign is the signing identity ("-" leaves the image
     * unsigned), $notarize the keychain profile of notarytool and its
     * keychain, or null; $log reports the steps.
     *
     * @param ?array{keychainProfile: string, keychain: string} $notarize
     * @param Closure(string): void $log
     */
    public static function build(string $app, string $dir, string $volume, string $file, string $sign, ?array $notarize, Closure $log): string
    {
        $work = "$dir/.dmg-" . getmypid();
        mkdir($work, 0755);
        $disk = null;
        try {
            // The image starts as a copy of a folder holding all it shows, which hdiutil sizes. ditto keeps the bundle exactly as signed, and copies it without cloning: hdiutil counts the blocks a file takes, fewer than its size in a sparse file, which HFS+ cannot keep sparse.
            $src = "$work/src";
            mkdir($src, 0755);
            $appName = basename($app);
            Cmd::run(['ditto', '--noclone', $app, "$src/$appName"]);
            symlink('/Applications', "$src/Applications") || throw new RuntimeException('cannot link /Applications');
            // Finder hides the .app extension of bundles by default; hiding it with the Finder flag, like create-dmg does, would make the signature fail strict verification.
            file_put_contents("$src/.DS_Store", DsStore::encodeFile(DsStore::dmgRecords($appName)));
            $icon = "$app/Contents/Resources/" . Resources::ICON;
            $hasIcon = file_exists($icon);
            if ($hasIcon) {
                copy($icon, "$src/.VolumeIcon.icns");
            }
            $rw = "$work/rw.dmg";
            self::hdiutilRetrying(self::createArgs($volume, $src, $rw));
            $mnt = "$work/mnt";
            mkdir($mnt, 0755);
            $disk = self::attach(['-readwrite', '-noverify', '-noautoopen', '-nobrowse', '-mountpoint', $mnt, $rw]);
            if ($hasIcon) {
                self::setFinderFlags($mnt, self::HAS_CUSTOM_ICON);
            }
            // Created by macOS while the image is mounted.
            Cmd::output(['rm', '-rf', "$mnt/.fseventsd", "$mnt/.Trashes"]);
            self::hdiutilRetrying(['detach', $disk]);
            $disk = null;
            // LZMA gives the smallest images; it needs macOS 10.15, below the oldest MyPHP supports.
            $dmg = "$dir/$file";
            Cmd::run(self::convertArgs($rw, $dmg));
            if ($sign !== '-') {
                Cmd::run(['codesign', '--force', '--timestamp', '--sign', $sign, $dmg]);
            }
            $log("Created $file");
            if ($notarize !== null) {
                self::notarize($notarize, $dmg, $log);
                // The ticket covers the app too: staple it for updates, which install the bare app. The image's copy stays without it, which deltas allow for.
                Cmd::run(['xcrun', 'stapler', 'staple', $app]);
            }
            return $dmg;
        } finally {
            if ($disk !== null) {
                Cmd::output(['hdiutil', 'detach', '-force', $disk]);
            }
            Cmd::output(['rm', '-rf', $work]);
        }
    }

    /**
     * The command that writes the writable image $rw as the compressed,
     * read-only $dmg (mygo's convertDMG): macOS 27 fails "hdiutil convert"
     * with "Resource temporarily unavailable" and asks for "diskutil image
     * create from", which overwrites $dmg as -ov does; older systems, whose
     * diskutil has no image verb, use hdiutil.
     * @return list<string>
     */
    public static function convertArgs(string $rw, string $dmg): array
    {
        if (Cmd::look('diskutil') !== '' && Cmd::output(['diskutil', 'image', 'create', 'from', '-h'])[0] === 0) {
            return ['diskutil', 'image', 'create', 'from', '--format', 'ULMO', $rw, $dmg];
        }
        return ['hdiutil', 'convert', $rw, '-format', 'ULMO', '-ov', '-o', $dmg];
    }

    /** The hdiutil arguments that create a writable HFS+ image of $src, as large as its contents need; without -quiet, which would leave out of the error why it failed. @return list<string> */
    public static function createArgs(string $volume, string $src, string $out): array
    {
        return ['create', '-ov', '-volname', $volume, '-srcfolder', $src, '-fs', 'HFS+', '-fsargs', '-c c=64,a=16,e=16', '-format', 'UDRW', $out];
    }

    /** Attaches a disk image and returns the device of its whole disk, as /dev/disk4, which hdiutil prints first: detaching it works even after an earlier attempt unmounted the volume but could not eject the disk. @param list<string> $args */
    private static function attach(array $args): string
    {
        $p = proc_open(['hdiutil', 'attach', ...$args], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $out = (string)stream_get_contents($pipes[1]);
        $err = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $code = proc_close($p);
        if ($code !== 0) {
            throw new RuntimeException('hdiutil attach ' . implode(' ', $args) . ": exit status $code\n" . trim($err));
        }
        return self::attachedDisk($out) ?? throw new RuntimeException('hdiutil attach ' . implode(' ', $args) . ": no device attached\n" . trim($out));
    }

    /** The first device that "hdiutil attach" prints, or null. */
    public static function attachedDisk(string $out): ?string
    {
        foreach (explode("\n", $out) as $line) {
            $f = preg_split('/\s+/', trim($line));
            if ($f !== false && str_starts_with($f[0], '/dev/disk')) {
                return $f[0];
            }
        }
        return null;
    }

    /**
     * Runs hdiutil, again while it fails with "Resource busy", which it
     * reports when a service such as Spotlight or an antivirus holds an
     * image, a file it reads or a volume it detaches: it waits 2, 4, 8 then
     * 16 seconds before trying again, as create-dmg does. An error after
     * retries also says how the first attempt failed.
     *
     * @param list<string> $args
     */
    private static function hdiutilRetrying(array $args): void
    {
        $first = null;
        for ($i = 0; $i < 5; $i++) {
            try {
                Cmd::run(['hdiutil', ...$args]);
                return;
            } catch (RuntimeException $e) {
                $first ??= $e;
                if (!self::busy($e->getMessage())) {
                    break;
                }
                if ($i < 4) {
                    sleep(2 << $i);
                }
            }
        }
        throw $e === $first ? $e : new RuntimeException($e->getMessage() . "\nfirst attempt: " . $first->getMessage());
    }

    /** Whether hdiutil failed because a resource was busy. */
    public static function busy(string $error): bool
    {
        return str_contains($error, 'Resource busy');
    }

    /** Sets the Finder flags of a file or folder, which are stored at offset 8 of its com.apple.FinderInfo attribute. */
    private static function setFinderFlags(string $path, int $flags): void
    {
        $info = str_repeat("\0", 8) . pack('n', $flags) . str_repeat("\0", 22);
        Cmd::run(['/usr/bin/xattr', '-wx', 'com.apple.FinderInfo', bin2hex($info), $path]);
    }

    /**
     * Submits a disk image to Apple's notary service, waits for the verdict
     * and staples the ticket to the image.
     *
     * @param array{keychainProfile: string, keychain: string} $n
     * @param Closure(string): void $log
     */
    private static function notarize(array $n, string $dmg, Closure $log): void
    {
        $log('Notarizing ' . basename($dmg) . ' (usually takes a few minutes)');
        $args = ['xcrun', 'notarytool', 'submit', $dmg, '--keychain-profile', $n['keychainProfile'], '--wait', '--output-format', 'json'];
        if ($n['keychain'] !== '') {
            array_push($args, '--keychain', $n['keychain']);
        }
        $p = proc_open($args, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', 'php://stderr', 'w']], $pipes);
        $out = (string)stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $code = proc_close($p);
        $result = [];
        if (($i = strrpos($out, '{')) !== false) {
            $result = json_decode(substr($out, $i), true) ?: [];
        }
        $status = (string)($result['status'] ?? '');
        if ($code !== 0 || $status !== 'Accepted') {
            $msg = 'notarization failed (' . trim($status . ' ' . ($result['message'] ?? '')) . ($code !== 0 ? ": exit status $code" : '') . ')';
            if (($result['id'] ?? '') !== '') {
                $msg .= "; see xcrun notarytool log {$result['id']} --keychain-profile \"{$n['keychainProfile']}\"";
            }
            throw new RuntimeException($msg . "\n" . trim($out));
        }
        Cmd::run(['xcrun', 'stapler', 'staple', $dmg]);
        $log('Notarized ' . basename($dmg));
    }
}
