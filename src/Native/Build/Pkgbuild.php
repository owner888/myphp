<?php
declare(strict_types=1);

namespace MyPHP\Native\Build;

use RuntimeException;

/**
 * The PKGBUILD that Linux builds get next to the app's archive (mygo's
 * pkgbuild.go): makepkg -si packages the archive for Arch Linux, checked
 * against its SHA-256, with the files of the Debian package (Deb) — the app
 * in /opt/<slug>, its commands in /usr/bin, its desktop entry, icons and
 * MIME package — and installs it. pacman's hooks register them, as the
 * triggers of Debian's packages do, so it needs no install script.
 *
 * @internal
 */
final class Pkgbuild
{
    public const NAME = 'PKGBUILD';

    /** The architectures of Arch Linux (mygo's archArch, those myphp builds). */
    public const ARCH = ['amd64' => 'x86_64', 'arm64' => 'aarch64'];

    /** The Arch Linux packages of the Debian ones apps commonly depend on, by the Debian name without its "t64" suffix (mygo's archPackages). */
    public const PACKAGES = [
        'libgtk-3-0' => 'gtk3',
        'libwebkit2gtk-4.1-0' => 'webkit2gtk-4.1',
        'libayatana-appindicator3-1' => 'libayatana-appindicator',
        'libnotify4' => 'libnotify',
        'libsecret-1-0' => 'libsecret',
        'adwaita-icon-theme' => 'adwaita-icon-theme',
        'librsvg2-common' => 'librsvg',
        'libsoup-3.0-0' => 'libsoup3',
        'libglib2.0-0' => 'glib2',
        'libasound2' => 'alsa-lib',
        'libpulse0' => 'libpulse',
        'libnss3' => 'nss',
        'libssl3' => 'openssl',
        'libx11-6' => 'libx11',
        'libxtst6' => 'libxtst',
        'libvulkan1' => 'vulkan-icd-loader',
        'libgl1' => 'libglvnd',
        'libegl1' => 'libglvnd',
        'gstreamer1.0-plugins-base' => 'gst-plugins-base',
        'gstreamer1.0-plugins-good' => 'gst-plugins-good',
        'gstreamer1.0-plugins-bad' => 'gst-plugins-bad',
        'gstreamer1.0-plugins-ugly' => 'gst-plugins-ugly',
        'gstreamer1.0-libav' => 'gst-libav',
        'ffmpeg' => 'ffmpeg',
        'xdg-utils' => 'xdg-utils',
        'zenity' => 'zenity',
        'curl' => 'curl',
        'git' => 'git',
    ];

    /**
     * The Arch Linux packages of the Debian dependencies, and the Debian
     * ones it knows none for (mygo's archDepends): of alternatives, the
     * first that has one counts. Debian's PHP packages (php8.3-cli,
     * php8.3-intl…) are all Arch Linux's php, which has those extensions.
     *
     * @param list<string> $debDepends
     * @return array{0: list<string>, 1: list<string>}
     */
    public static function depends(array $debDepends): array
    {
        $depends = []; $unknown = [];
        foreach ($debDepends as $dep) {
            $found = '';
            foreach (explode('|', $dep) as $alt) {
                $deb = explode(' ', trim($alt))[0];
                $deb = explode(':', explode('(', $deb)[0])[0];
                $deb = str_ends_with($deb, 't64') ? substr($deb, 0, -3) : $deb;
                $found = preg_match('/^php\d+\.\d+-[a-z]+$/', $deb) === 1 ? 'php' : (self::PACKAGES[$deb] ?? '');
                if ($found !== '') {
                    break;
                }
            }
            if ($found === '') {
                $unknown[] = $dep;
            } elseif (!in_array($found, $depends, true)) {
                $depends[] = $found;
            }
        }
        return [$depends, $unknown];
    }

    /** The slug as an Arch Linux package name (mygo's archPkgname). */
    public static function pkgname(string $slug): string
    {
        $name = ltrim((string)preg_replace('/[^a-z0-9@._+-]+/', '-', strtolower($slug)), '-.');
        return $name === '' ? 'app' : $name;
    }

    /** A semantic version as a pkgver (mygo's archVersion): 1.2.0beta.1 of 1.2.0-beta.1, 1.2.0pre1 of 1.2.0-1, 1.2.0_build.5 of 1.2.0+build.5. */
    public static function version(string $v): string
    {
        [$v, $build] = array_pad(explode('+', ltrim($v, 'v'), 2), 2, '');
        [$v, $pre] = array_pad(explode('-', $v, 2), 2, '');
        if ($pre !== '' && ctype_digit($pre[0])) {
            $pre = "pre$pre";
        }
        $v .= $pre;
        if ($build !== '') {
            $v .= "_$build";
        }
        $v = (string)preg_replace('/[^A-Za-z0-9._]+/', '_', $v);
        return $v === '' ? '0' : $v;
    }

    /** The string $key of the project's package.json, or '' (mygo's packageJSONString). */
    public static function packageJson(string $root, string $key): string
    {
        $data = @file_get_contents("$root/package.json");
        $pkg = $data === false ? null : json_decode($data, true);
        return is_array($pkg) && is_string($pkg[$key] ?? null) ? trim($pkg[$key]) : '';
    }

    /** The app's license: package.json's SPDX expression, else LicenseRef-custom (mygo's archLicense). */
    public static function license(string $root): string
    {
        $l = self::packageJson($root, 'license');
        if (str_starts_with($l, '(')) { $l = substr($l, 1); }
        if (str_ends_with($l, ')')) { $l = substr($l, 0, -1); }
        return $l === '' || $l === 'UNLICENSED' || str_starts_with($l, 'SEE LICENSE IN') ? 'LicenseRef-custom' : $l;
    }

    /** The app's home page: package.json's homepage, else updates.github's repository, else '' (mygo's archURL). */
    public static function url(string $root, array $c): string
    {
        $u = self::packageJson($root, 'homepage');
        if ($u !== '') {
            return $u;
        }
        return ($c['updates']['github'] ?? '') !== '' ? 'https://github.com/' . $c['updates']['github'] : '';
    }

    /** $s quoted for a POSIX shell (mygo's shQuote). */
    public static function quote(string $s): string
    {
        return "'" . str_replace("'", "'\\''", $s) . "'";
    }

    /** $s as a shell word, quoted unless the shell reads it as it is (mygo's shWord). */
    public static function word(string $s): string
    {
        return preg_match('~^[A-Za-z0-9@%+=:,./_-]+$~', $s) === 1 ? $s : self::quote($s);
    }

    /** @param list<string> $words */
    private static function shArray(array $words): string
    {
        return '(' . implode(' ', array_map(self::quote(...), $words)) . ')';
    }

    /**
     * Writes into $stage the PKGBUILD of the app $app (the launcher $name
     * and the files next to it), packaged from $archive, its archive in
     * $stage, and its icons next to it as sources, and returns the files
     * written; $warn hears of Debian dependencies it leaves out (mygo's
     * writePKGBUILD).
     *
     * @param list<string> $app
     * @param list<string> $debDepends
     * @param array<int, string> $icons
     * @param list<array{0: string, 1: string, 2: string}> $langs
     * @param \Closure(string): void $warn
     * @return list<string>
     */
    public static function write(string $root, array $c, string $stage, string $name, string $goarch, array $app, string $archive, array $debDepends, array $icons, array $langs, \Closure $warn): array
    {
        $written = [];
        $sources = [basename($archive)];
        foreach ($icons as $px => $png) {
            $source = "$name-{$px}x$px.png";
            file_put_contents("$stage/$source", $png) !== false || throw new RuntimeException("cannot write $stage/$source");
            $written[] = "$stage/$source";
            $sources[] = $source;
        }
        $sums = array_map(fn(string $s) => hash_file('sha256', "$stage/$s"), $sources);
        [$depends, $unknown] = self::depends($debDepends);
        foreach ($unknown as $dep) {
            $warn("Leaving $dep out of the PKGBUILD's dependencies: it is a Debian package with no Arch Linux package known for it");
        }
        $path = "$stage/" . self::NAME;
        file_put_contents($path, self::text($root, $c, $name, $goarch, $app, $sources, $sums, $depends, array_keys($icons), $langs)) !== false || throw new RuntimeException("cannot write $path");
        $written[] = $path;
        return $written;
    }

    /**
     * The PKGBUILD's text: $sources the archive then the icons of $sizes,
     * with their SHA-256 $sums.
     *
     * @param list<string> $app
     * @param list<string> $sources
     * @param list<string> $sums
     * @param list<string> $depends
     * @param list<int> $sizes
     * @param list<array{0: string, 1: string, 2: string}> $langs
     */
    public static function text(string $root, array $c, string $name, string $goarch, array $app, array $sources, array $sums, array $depends, array $sizes, array $langs = []): string
    {
        $arch = self::ARCH[$goarch] ?? throw new RuntimeException("no Arch Linux architecture for $goarch");
        $description = (string)($c['linux']['comment'] ?? '');
        if ($description === '') {
            $description = $c['name'];
        }
        $b = '';
        if (($m = implode(' ', preg_split('/\s+/', trim((string)($c['linux']['maintainer'] ?? '')), -1, PREG_SPLIT_NO_EMPTY))) !== '') {
            $b .= "# Maintainer: $m\n";
        }
        $b .= "# Written by myphp build next to the app's archive: makepkg -si packages\n# the app for Arch Linux and installs it.\n";
        $b .= 'pkgname=' . self::quote(self::pkgname($name)) . "\npkgver=" . self::quote(self::version($c['version'])) . "\npkgrel=1\npkgdesc=" . self::quote($description) . "\narch=" . self::shArray([$arch]) . "\n";
        if (($u = self::url($root, $c)) !== '') {
            $b .= 'url=' . self::quote($u) . "\n";
        }
        $b .= 'license=' . self::shArray([self::license($root)]) . "\n";
        $b .= 'depends=' . self::shArray($depends) . "\n";
        // The launcher as built, as the Debian package has it.
        $b .= "options=('!strip' '!debug')\n";
        $b .= 'source=' . self::shArray($sources) . "\nsha256sums=" . self::shArray($sums) . "\n";
        $opt = '"$pkgdir"/' . self::word("opt/$name");
        $b .= "\npackage() {\n\tcd \"\$srcdir\"\n\tinstall -d $opt\n";
        $b .= "\tcp -dR -- " . implode(' ', array_map(self::word(...), Linux::optEntries($app, $name))) . " $opt/\n";
        $b .= "\t# The modes of the Debian package: 755 for directories and programs, 644\n\t# for the other files.\n";
        $b .= "\tfind $opt -type d -exec chmod 755 {} +\n";
        $b .= "\tfind $opt -type f -perm /111 -exec chmod 755 {} +\n";
        $b .= "\tfind $opt -type f ! -perm /111 -exec chmod 644 {} +\n";
        $commands = $c['linux']['command'] ?? [];
        if ($commands !== []) {
            $b .= "\tinstall -d \"\$pkgdir\"/usr/bin\n";
        }
        foreach ($commands as $command) {
            $b .= "\tln -s " . self::word("../../opt/$name/$name") . ' "$pkgdir"/' . self::word("usr/bin/$command") . "\n";
        }
        if (Linux::mimePackage($c) !== '') {
            // Types the app defines, as the archive has them.
            $b .= "\tinstall -Dm644 " . self::word("$name.xml") . ' "$pkgdir"/' . self::word("usr/share/mime/packages/$name.xml") . "\n";
        }
        // The entry runs the app by its path, as the Debian package's does.
        $desktop = Linux::desktopEntry($c, "/opt/$name/$name", $name, $langs);
        $b .= "\tprintf '%s' " . self::quote($desktop) . " |\n\t\tinstall -Dm644 /dev/stdin \"\$pkgdir\"/" . self::word("usr/share/applications/$name.desktop") . "\n";
        foreach ($sizes as $i => $px) {
            $b .= "\tinstall -Dm644 " . self::word($sources[$i + 1]) . ' "$pkgdir"/' . self::word(Linux::iconDir($px) . "/$name.png") . "\n";
        }
        return "$b}\n";
    }
}
