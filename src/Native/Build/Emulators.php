<?php
declare(strict_types=1);

namespace MyPHP\Native\Build;

use RuntimeException;

/** An emulator `myphp emulators` lists and launches: an Android virtual device, or the iOS Simulator. */
final class Emulator
{
    public function __construct(
        public readonly string $id,       // the AVD's name, or 'apple_ios_simulator'
        public readonly string $name,     // as a person reads it: the AVD's display name, its device profile's
        public readonly string $platform, // 'android' or 'ios'
    ) {
    }
}

/**
 * The emulators of the machine, as Flutter's `flutter emulators`: the Android SDK's virtual devices (AVDs) and the
 * iOS Simulator; launched, they are devices `myphp devices` lists and `myphp run -d` runs on. An AVD is made from the
 * newest system image for this machine's architecture and the newest Pixel profile, unless told others, the SDK's
 * emulator and the image downloaded first when missing.
 */
final class Emulators
{
    public const IOS = 'apple_ios_simulator';

    /** The Android SDK: ANDROID_HOME, ANDROID_SDK_ROOT, Android Studio's (~/Library/Android/sdk, ~/Android/Sdk), Homebrew's; '' for none. */
    public static function androidSdk(): string
    {
        $home = (string)getenv('HOME');
        foreach ([getenv('ANDROID_HOME') ?: '', getenv('ANDROID_SDK_ROOT') ?: '', "$home/Library/Android/sdk", "$home/Android/Sdk", '/opt/homebrew/share/android-commandlinetools'] as $dir) {
            if ($dir !== '' && is_dir($dir)) {
                return rtrim($dir, '/');
            }
        }
        return '';
    }

    /** A tool of the SDK: emulator, avdmanager, adb; on PATH else; '' for none. */
    public static function tool(string $name): string
    {
        $sdk = self::androidSdk();
        $in = match ($name) {
            'emulator' => ["$sdk/emulator/emulator"],
            'adb' => ["$sdk/platform-tools/adb"],
            default => ["$sdk/cmdline-tools/latest/bin/$name", ...array_reverse(glob("$sdk/cmdline-tools/*/bin/$name") ?: [])],
        };
        foreach ($in as $path) {
            if ($sdk !== '' && is_file($path) && is_executable($path)) {
                return $path;
            }
        }
        return Cmd::look($name);
    }

    /** @return list<Emulator> the AVDs, sorted by id, then the iOS Simulator on a Mac with Xcode */
    public static function list(): array
    {
        $out = [];
        $emulator = self::tool('emulator');
        if ($emulator !== '') {
            [, $avds] = Cmd::output([$emulator, '-list-avds']);
            $ids = array_values(array_filter(array_map('trim', explode("\n", $avds)), fn(string $l) => $l !== '' && !str_contains($l, ' ')));
            sort($ids);
            foreach ($ids as $id) {
                $out[] = new Emulator($id, self::avdName($id), 'android');
            }
        }
        if (PHP_OS_FAMILY === 'Darwin' && Cmd::look('xcrun') !== '') {
            $out[] = new Emulator(self::IOS, 'iOS Simulator', 'ios');
        }
        return $out;
    }

    /** An AVD's display name from its config: avd.ini.displayname, else its device profile's name, else the id. */
    private static function avdName(string $id): string
    {
        $cfg = @parse_ini_file(self::avdDir($id) . '/config.ini', false, INI_SCANNER_RAW) ?: [];
        return (string)($cfg['avd.ini.displayname'] ?? $cfg['hw.device.name'] ?? $id);
    }

    /**
     * Starts emulator $id in the background and waits until it has booted, so that `myphp run` finds it: an AVD's
     * emulator, `adb wait-for-device` and sys.boot_completed, at most $timeout seconds; the iOS Simulator app.
     * Throws when there is none, or when it did not boot in time.
     */
    public static function launch(string $id, bool $cold = false, int $timeout = 180): void
    {
        if ($id === self::IOS) {
            Cmd::run(['open', '-a', 'Simulator']);
            return;
        }
        $emulator = self::tool('emulator');
        if ($emulator === '') {
            throw new RuntimeException('no Android emulator: install it with the SDK Manager (the "Android Emulator" package)');
        }
        $known = array_map(fn(Emulator $e) => $e->id, self::list());
        if (!in_array($id, $known, true)) {
            throw new RuntimeException("no emulator \"$id\"; myphp emulators lists them");
        }
        self::keyboard($id); // an AVD made before --create set it
        $args = [$emulator, '-avd', $id, ...($cold ? ['-no-snapshot-load'] : [])];
        exec('nohup ' . implode(' ', array_map('escapeshellarg', $args)) . ' >/dev/null 2>&1 &'); // it outlives this command, as the device it is
        $adb = self::tool('adb');
        if ($adb === '') {
            return; // started; no adb to wait with
        }
        $until = time() + $timeout;
        while (time() < $until) {
            foreach (self::serials($adb) as $serial) {
                [, $name] = Cmd::output([$adb, '-s', $serial, 'emu', 'avd', 'name']);
                if (trim(explode("\n", $name)[0]) !== $id) { continue; }
                [, $done] = Cmd::output([$adb, '-s', $serial, 'shell', 'getprop', 'sys.boot_completed']);
                if (trim($done) === '1') { return; }
            }
            sleep(2);
        }
        throw new RuntimeException("$id did not boot in {$timeout}s");
    }

    /** @return list<string> the serials of the emulators adb sees */
    private static function serials(string $adb): array
    {
        [, $out] = Cmd::output([$adb, 'devices']);
        preg_match_all('/^(emulator-\d+)\s+device$/m', $out, $m);
        return $m[1];
    }

    /**
     * Makes an AVD, as `flutter emulators --create`, and whatever it needs of the SDK first: the emulator and the
     * platform tools when missing, and its system image when not installed, downloaded with sdkmanager (its licenses
     * accepted); $say tells of each download. Of system image $image (when '': the newest installed for this machine's
     * architecture, else the newest the SDK offers), on device profile $device (the newest Pixel when ''), named $name
     * (the profile's id when ''). Returns its id. Throws without the SDK's command-line tools, or when the name is taken.
     * @param ?\Closure(string): void $say
     */
    public static function create(string $name = '', string $device = '', string $image = '', ?\Closure $say = null): string
    {
        $say ??= static function (string $m): void {};
        $avdmanager = self::tool('avdmanager');
        if ($avdmanager === '' || self::tool('sdkmanager') === '') {
            throw new RuntimeException('no Android SDK Command-line Tools (sdkmanager, avdmanager): install them with Android Studio\'s SDK Manager, or brew install --cask android-commandlinetools');
        }
        $missing = array_values(array_filter(['emulator', 'platform-tools'], fn(string $p) => self::tool($p === 'emulator' ? 'emulator' : 'adb') === ''));
        $installed = self::installedImages();
        if ($image === '') {
            $image = self::newestImage($installed, self::abi()) ?? self::newestImage(self::availableImages(), self::abi())
                ?? throw new RuntimeException('the SDK offers no ' . self::abi() . ' system image of API 31 or over');
        }
        if (!in_array($image, $installed, true)) { $missing[] = $image; }
        if ($missing !== []) {
            $say('installing ' . implode(', ', $missing) . ' (a download of 1-2 GB for a system image)');
            self::install($missing);
        }
        if ($device === '') {
            $device = self::newestPixel(self::profiles()) ?? throw new RuntimeException('no Pixel device profile: update the Android SDK Command-line Tools');
        }
        $name = $name !== '' ? $name : $device;
        if (in_array($name, array_map(fn(Emulator $e) => $e->id, self::list()), true)) {
            throw new RuntimeException("an emulator \"$name\" exists already: myphp emulators --launch $name");
        }
        $say("making $name: $device, $image");
        $p = proc_open([$avdmanager, 'create', 'avd', '-n', $name, '-k', $image, '-d', $device], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
        fwrite($pipes[0], "no\n"); // no custom hardware profile
        fclose($pipes[0]);
        $out = (string)stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        if (proc_close($p) !== 0) {
            throw new RuntimeException("avdmanager create avd: " . trim($out));
        }
        self::keyboard($name);
        return $name;
    }

    /**
     * Sets AVD $id's hw.keyboard to yes, as Android Studio's Device Manager makes them: avdmanager leaves it no, and
     * the emulator's Back, Home and Overview buttons and its shortcuts, which it sends as key presses, do nothing then.
     * Returns whether it changed the config.
     */
    public static function keyboard(string $id): bool
    {
        $file = self::avdDir($id) . '/config.ini';
        $text = @file_get_contents($file);
        if ($text === false || preg_match('/^hw\.keyboard\s*=\s*yes\s*$/mi', $text)) {
            return false;
        }
        $text = preg_match('/^hw\.keyboard\s*=.*$/mi', $text)
            ? preg_replace('/^hw\.keyboard\s*=.*$/mi', 'hw.keyboard=yes', $text)
            : rtrim($text, "\n") . "\nhw.keyboard=yes\n";
        $f = fopen($file, 'r+') ?: throw new RuntimeException("cannot write $file");
        ftruncate($f, 0); fwrite($f, $text); fclose($f); // in place: the file's permissions as they were
        return true;
    }

    /** The folder of AVD $id: ANDROID_AVD_HOME's, else ANDROID_USER_HOME's avd, else ~/.android/avd. */
    private static function avdDir(string $id): string
    {
        return (getenv('ANDROID_AVD_HOME') ?: ((getenv('ANDROID_USER_HOME') ?: getenv('HOME') . '/.android') . '/avd')) . "/$id.avd";
    }

    /** Deletes AVD $id, its disk and snapshots with it; throws when there is none of that id, or it is running. */
    public static function delete(string $id): void
    {
        if (!in_array($id, array_map(fn(Emulator $e) => $e->id, self::list()), true) || $id === self::IOS) {
            throw new RuntimeException("no Android emulator \"$id\"; myphp emulators lists them");
        }
        $adb = self::tool('adb');
        foreach ($adb === '' ? [] : self::serials($adb) as $serial) {
            [, $name] = Cmd::output([$adb, '-s', $serial, 'emu', 'avd', 'name']);
            if (trim(explode("\n", $name)[0]) === $id) {
                throw new RuntimeException("$id is running ($serial): close it first");
            }
        }
        Cmd::run([self::tool('avdmanager'), 'delete', 'avd', '-n', $id]);
    }

    /**
     * Uninstalls system image $image (sdkmanager's name); throws when it is not installed, or when an AVD still uses
     * it (which names them).
     */
    public static function uninstallImage(string $image): void
    {
        if (!in_array($image, self::installedImages(), true)) {
            throw new RuntimeException("$image is not installed; myphp emulators --images lists them");
        }
        $users = [];
        foreach (self::list() as $e) {
            if ($e->platform === 'android' && self::avdImage($e->id) === $image) { $users[] = $e->id; }
        }
        if ($users !== []) {
            throw new RuntimeException("$image is the image of " . implode(', ', $users) . ': delete ' . (count($users) === 1 ? 'it' : 'them') . ' first (myphp emulators --delete <id>)');
        }
        Cmd::run([self::tool('sdkmanager'), '--uninstall', $image]);
    }

    /** The system image AVD $id is of, as sdkmanager names it, from its config's image.sysdir.1; '' when unknown. */
    private static function avdImage(string $id): string
    {
        $cfg = @parse_ini_file(self::avdDir($id) . '/config.ini', false, INI_SCANNER_RAW) ?: [];
        $sys = trim(str_replace('\\', '/', (string)($cfg['image.sysdir.1'] ?? '')), '/'); // system-images/android-36/google_apis/arm64-v8a
        return $sys === '' ? '' : str_replace('/', ';', $sys);
    }

    /** @return list<string> the device profiles avdmanager knows (pixel_9_pro, medium_phone…), for --device */
    public static function profiles(): array
    {
        $avdmanager = self::tool('avdmanager');
        if ($avdmanager === '') {
            return [];
        }
        [, $out] = Cmd::output([$avdmanager, 'list', 'device', '-c']);
        return array_values(array_filter(array_map('trim', explode("\n", $out)), fn(string $l) => $l !== '' && !str_contains($l, ' ')));
    }

    /** @return list<string> the system images the SDK offers to download, as sdkmanager names them */
    public static function availableImages(): array
    {
        $sdkmanager = self::tool('sdkmanager');
        if ($sdkmanager === '') {
            return [];
        }
        [, $out] = Cmd::output([$sdkmanager, '--list']);
        return self::imagesOf($out);
    }

    /** @return list<string> the system images an `sdkmanager --list` names, in any of its sections, each once */
    public static function imagesOf(string $list): array
    {
        preg_match_all('/^\s*(system-images;android-[^;\s|]+;[^;\s|]+;[^;\s|]+)/m', $list, $m);
        return array_values(array_unique($m[1]));
    }

    /** Installs SDK packages with sdkmanager, accepting their licenses; throws with its output when it fails. @param list<string> $packages */
    private static function install(array $packages): void
    {
        $p = proc_open([self::tool('sdkmanager'), '--install', ...$packages], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
        fwrite($pipes[0], str_repeat("y\n", 50)); // the licenses' questions
        fclose($pipes[0]);
        $out = (string)stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        if (proc_close($p) !== 0) {
            throw new RuntimeException("sdkmanager --install " . implode(' ', $packages) . ":\n" . implode("\n", array_slice(explode("\n", trim($out)), -20)));
        }
    }

    /** The system image ABI this machine runs natively: arm64-v8a on Apple Silicon and ARM Linux, x86_64 else. */
    public static function abi(): string
    {
        return in_array(strtolower(php_uname('m')), ['arm64', 'aarch64'], true) ? 'arm64-v8a' : 'x86_64';
    }

    /** @return list<string> the system images installed, as sdkmanager names them: system-images;android-36;google_apis;arm64-v8a */
    public static function installedImages(): array
    {
        $sdk = self::androidSdk();
        $out = [];
        foreach (glob("$sdk/system-images/*/*/*", GLOB_ONLYDIR) ?: [] as $dir) {
            [$api, $tag, $abi] = array_slice(explode('/', $dir), -3);
            $out[] = "system-images;$api;$tag;$abi";
        }
        return $out;
    }

    /**
     * The newest of $images for $abi, minSdk 31 and over (myphp's): the highest API level, then Google Play's over
     * Google APIs' over the plain one. Null for none.
     * @param list<string> $images
     */
    public static function newestImage(array $images, string $abi): ?string
    {
        $rank = ['google_apis_playstore' => 3, 'google_apis' => 2, 'default' => 1];
        $best = null; $key = null;
        foreach ($images as $img) {
            $p = explode(';', $img);
            if (count($p) !== 4 || $p[3] !== $abi || !preg_match('/^android-(\d+)/', $p[1], $m) || (int)$m[1] < 31) { continue; }
            $k = [(int)$m[1], $rank[$p[2]] ?? 0];
            if ($key === null || $k > $key) { [$best, $key] = [$img, $k]; }
        }
        return $best;
    }

    /**
     * The newest Pixel of $profiles (avdmanager's device ids): the highest number, a Pro over the plain one over the
     * others (a, Fold, XL). Null for none.
     * @param list<string> $profiles
     */
    public static function newestPixel(array $profiles): ?string
    {
        $best = null; $key = null;
        foreach ($profiles as $id) {
            if (!preg_match('/^pixel_(\d+)(?:_(.+))?$/', $id, $m)) { continue; }
            $k = [(int)$m[1], match ($m[2] ?? '') { 'pro' => 3, '' => 2, default => 1 }];
            if ($key === null || $k > $key) { [$best, $key] = [$id, $k]; }
        }
        return $best;
    }
}
