<?php
declare(strict_types=1);

namespace MyPHP\Native\Build;

use MyPHP\I18n;
use RuntimeException;

/**
 * A Linux app as `myphp build` lays it out (mygo's build.go for linux and
 * the Linux half of deb.go): the launcher named after the slug, with
 * app/ (the app's PHP and the framework's src), frontend/, info.json, the
 * desktop entry, the icon and the MIME package next to it, and the
 * resources; the packages' dependencies; the icons of the hicolor theme.
 *
 * The configuration is myphp.json's, with linux as project() fills it:
 * maintainer, comment, categories, depends, webview (?bool) and command
 * (a list of names).
 *
 * @internal
 */
final class Linux
{
    /** What the runtime reads of the app (Native\Bundle::plist), as macOS's Info.plist: the keys it reads. */
    public const INFO = 'info.json';

    /** The Debian architectures of the targets (mygo's debArch, those myphp builds). */
    public const DEB_ARCH = ['amd64' => 'amd64', 'arm64' => 'arm64'];

    /** The GNU triples of the targets, whose cross compilers build a launcher for another architecture. */
    public const TRIPLE = ['amd64' => 'x86_64-linux-gnu', 'arm64' => 'aarch64-linux-gnu'];

    /** What linux.command names may be (mygo's commandRe). */
    public const COMMAND = '/^[A-Za-z0-9][A-Za-z0-9._+-]*$/';

    /** The PHP extensions the framework needs beyond php-cli's own (FFI is php-common's), as Debian packages them. */
    public const PHP_PACKAGES = ['cli', 'intl', 'mbstring', 'xml', 'curl'];

    /** The names in the app's directory that resources must not take (mygo's reservedNames for linux, with myphp's app/, frontend/ and info.json). @return list<string> */
    public static function reserved(string $slug): array
    {
        return [$slug, "$slug.desktop", "$slug.png", "$slug.xml", 'app', 'frontend', self::INFO];
    }

    /**
     * The Debian packages the app depends on (mygo's debDepends): GTK, the
     * Secret Service's library (App's Secrets), WebKitGTK for an app that
     * shows pages, the app indicator for one with a tray icon, the PHP the
     * exec launcher runs ($php: "8.3", '' for none, the static launcher's),
     * then linux.depends.
     *
     * @return list<string>
     */
    public static function debDepends(array $linux, bool $pages, bool $tray, string $php): array
    {
        $depends = ['libgtk-3-0 | libgtk-3-0t64', 'libsecret-1-0'];
        if ($pages) {
            $depends[] = 'libwebkit2gtk-4.1-0';
        }
        if ($tray) {
            $depends[] = 'libayatana-appindicator3-1';
        }
        if ($php !== '') {
            foreach (self::PHP_PACKAGES as $p) {
                $depends[] = "php$php-$p";
            }
        }
        foreach ($linux['depends'] ?? [] as $d) {
            if (!in_array($d, $depends, true)) {
                $depends[] = $d;
            }
        }
        return $depends;
    }

    /**
     * Whether the app shows web pages, which need WebKitGTK: linux.webview
     * when it is set (mygo's), else whether it has a frontend or its PHP
     * ($sources) makes a window of a page, a page or a web view.
     */
    public static function usesPages(array $c, string $sources): bool
    {
        if (is_bool($c['linux']['webview'] ?? null)) {
            return $c['linux']['webview'];
        }
        return ($c['frontendDist'] ?? '') !== ''
            || preg_match('/\b(html|url)\s*:|\bPageOptions\b|->(newWebView|webView|loadURL|loadHTML)\s*\(/', $sources) === 1;
    }

    /** Whether the app's PHP ($sources) puts an icon in the tray, which needs the app indicator library. */
    public static function usesTray(string $sources): bool
    {
        return preg_match('/\bnew\s+(\\\\?MyPHP\\\\)?Tray\s*\(|\bTrayOptions\b/', $sources) === 1;
    }

    /** The MIME type of a file association, and whether the app defines it: an unregistered one (x-) (mygo's mimeType). @return array{string, bool} */
    public static function mimeType(array $c, array $fa): array
    {
        if (($fa['mimeType'] ?? '') !== '') {
            $sub = explode('/', $fa['mimeType'], 2)[1] ?? '';
            return [$fa['mimeType'], str_starts_with($sub, 'x-')];
        }
        return ['application/x-' . Slug::ofConfig($c) . '-' . strtolower($fa['ext'][0]), true];
    }

    /** The shared-mime-info package of the MIME types the app defines, or '' (mygo's mimePackage). */
    public static function mimePackage(array $c): string
    {
        $b = '';
        foreach ($c['fileAssociations'] ?? [] as $fa) {
            [$t, $defined] = self::mimeType($c, $fa);
            if (!$defined) {
                continue;
            }
            $b .= "  <mime-type type=\"$t\">\n";
            if (($fa['name'] ?? '') !== '') {
                $b .= '    <comment>' . htmlspecialchars($fa['name'], ENT_XML1 | ENT_QUOTES) . "</comment>\n";
            }
            foreach ($fa['ext'] as $ext) {
                $b .= "    <glob pattern=\"*.$ext\"/>\n";
            }
            $b .= "  </mime-type>\n";
        }
        return $b === '' ? '' : "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<mime-info xmlns=\"http://www.freedesktop.org/standards/shared-mime-info\">\n$b</mime-info>\n";
    }

    /**
     * The desktop entry of the app, which runs $exec and shows $icon, with
     * its name and comment in the languages of its catalogs ($langs: [tag,
     * myphp.appName, myphp.appDescription]) where they differ (mygo's
     * linuxDesktopEntry).
     *
     * @param list<array{0: string, 1: string, 2: string}> $langs
     */
    public static function desktopEntry(array $c, string $exec, string $icon, array $langs = []): string
    {
        $categories = ($c['linux']['categories'] ?? []) ?: ['Utility'];
        $comment = (string)($c['linux']['comment'] ?? '');
        $localized = function (string $key, string $value, int $field) use ($langs): string {
            $out = ''; $seen = [];
            foreach ($langs as $l) {
                $v = $l[$field]; $locale = self::desktopTag($l[0]);
                if ($v === '' || $v === $value || isset($seen[$locale])) {
                    continue;
                }
                $seen[$locale] = true;
                $out .= "{$key}[$locale]=$v\n";
            }
            return $out;
        };
        $b = "[Desktop Entry]\nType=Application\nName={$c['name']}\n" . $localized('Name', $c['name'], 1);
        if ($comment !== '') {
            $b .= "Comment=$comment\n";
        }
        $b .= $localized('Comment', $comment, 2);
        $mime = [];
        foreach ($c['fileAssociations'] ?? [] as $fa) {
            $mime[] = self::mimeType($c, $fa)[0];
        }
        foreach ($c['urlSchemes'] ?? [] as $s) {
            $mime[] = "x-scheme-handler/$s";
        }
        if ($mime !== []) {
            $exec .= ' %U'; // files and deep links, which reach App::onOpenFile and onOpenURL
        }
        $b .= "Exec=$exec\nIcon=$icon\nCategories=" . implode(';', $categories) . ";\nTerminal=false\n";
        if ($mime !== []) {
            $b .= 'MimeType=' . implode(';', $mime) . ";\n";
        }
        return $b;
    }

    /**
     * The locale of the localized keys of a desktop entry (mygo's
     * desktopTag): pt-BR is pt_BR, sr-Latn sr@latin, zh-Hans zh_CN and
     * zh-Hant zh_TW; a region of digits, as es-419's, is left out.
     */
    public static function desktopTag(string $tag): string
    {
        $parts = explode('-', I18n::normalize($tag));
        [$lang, $script, $region] = [$parts[0], '', ''];
        foreach (array_slice($parts, 1) as $p) {
            if (strlen($p) === 4 && $script === '' && $region === '') {
                $script = $p;
            } elseif ($region === '' && (strlen($p) === 2 || strlen($p) === 3 && ctype_digit($p[0]))) {
                $region = $p;
            }
        }
        if ($lang === 'zh' && $script === '') {
            $script = in_array($region, ['TW', 'HK', 'MO'], true) ? 'Hant' : 'Hans';
        }
        $modifier = '';
        if ($lang === 'zh') {
            if ($region === '') {
                $region = $script === 'Hant' ? 'TW' : 'CN';
            }
        } elseif ($script !== '' && $script !== self::likelyScript($lang)) {
            $modifier = ['Latn' => 'latin', 'Cyrl' => 'cyrillic', 'Arab' => 'arabic', 'Deva' => 'devanagari'][$script] ?? '';
        }
        return $lang . (strlen($region) === 2 ? "_$region" : '') . ($modifier !== '' ? "@$modifier" : '');
    }

    /** The script a language is written in where its POSIX locale names no modifier (mygo's likelyScript). */
    private static function likelyScript(string $lang): string
    {
        return match ($lang) {
            'az', 'bs', 'uz' => 'Latn',
            'be', 'kk', 'mn', 'sr', 'tg' => 'Cyrl',
            default => '',
        };
    }

    /**
     * The icon of the app (a PNG) at 256 and 512 pixels, the sizes of the
     * hicolor theme the packages install it in, by size (mygo's
     * linuxIcons): scaled as Icns scales the macOS icon.
     *
     * @return array<int, string>
     */
    public static function icons(string $png): array
    {
        [$w, $h, $px] = Png::decode($png);
        $icons = [];
        foreach ([256, 512] as $size) {
            $icons[$size] = Icns::resize($w, $h, $px, $size);
        }
        return $icons;
    }

    /** Where an icon of $size goes in the hicolor theme, relative to the root. */
    public static function iconDir(int $size): string
    {
        return "usr/share/icons/hicolor/{$size}x$size/apps";
    }

    /**
     * What the runtime reads of a Linux app, as of the Info.plist of a
     * macOS one, by the same keys (Native\Bundle::plist): its name,
     * identifier, version, executable, documents and URL schemes, whether it
     * is a production build (App::isDev).
     *
     * @return array<string, mixed>
     */
    public static function info(array $c, string $executable, bool $production): array
    {
        $d = ['CFBundleName' => $c['name'], 'CFBundleDisplayName' => $c['name'], 'CFBundleIdentifier' => $c['identifier'],
            'CFBundleVersion' => $c['version'], 'CFBundleShortVersionString' => $c['version'], 'CFBundleExecutable' => $executable];
        $docs = [];
        foreach ($c['fileAssociations'] ?? [] as $fa) {
            $docs[] = ['CFBundleTypeName' => ($fa['name'] ?? '') !== '' ? $fa['name'] : strtoupper($fa['ext'][0]) . ' file', 'CFBundleTypeRole' => ($fa['role'] ?? '') !== '' ? $fa['role'] : 'Editor',
                'CFBundleTypeExtensions' => array_values($fa['ext']), 'CFBundleTypeMIMETypes' => [self::mimeType($c, $fa)[0]]];
        }
        if ($docs !== []) {
            $d['CFBundleDocumentTypes'] = $docs;
        }
        if (($c['urlSchemes'] ?? []) !== []) {
            $d['CFBundleURLTypes'] = [['CFBundleURLName' => $c['identifier'], 'CFBundleURLSchemes' => array_values($c['urlSchemes'])]];
        }
        if ($production) {
            $d['MyPHPProduction'] = true;
        }
        return $d;
    }

    /**
     * The C source of the exec launcher of a Linux app, as the macOS one:
     * it execs $php (or $MYPHP_PHP) with $flags on <its directory>/app/
     * $entry, in that directory, with the arguments it was given, naming its
     * directory in MYPHP_BUNDLE (Native\Bundle::path). Its directory is that
     * of /proc/self/exe, so the link of linux.command in /usr/bin finds it.
     *
     * @param list<string> $flags
     */
    public static function launcherSource(string $php, array $flags, string $entry): string
    {
        $q = fn(string $s): string => '"' . addcslashes($s, "\"\\") . '"';
        $list = implode(', ', array_map($q, $flags));
        $n = count($flags);
        $file = $q($entry);
        $bin = $q($php);
        return <<<C
        #include <limits.h>
        #include <stdio.h>
        #include <stdlib.h>
        #include <string.h>
        #include <unistd.h>
        int main(int argc, char **argv) {
            char dir[PATH_MAX], app[PATH_MAX], entry[PATH_MAX];
            ssize_t n = readlink("/proc/self/exe", dir, sizeof dir - 1);
            if (n <= 0) { perror("launcher: /proc/self/exe"); return 1; }
            dir[n] = 0;
            *strrchr(dir, '/') = 0;
            if (snprintf(app, sizeof app, "%s/app", dir) >= (int)sizeof app || snprintf(entry, sizeof entry, "%s/%s", app, $file) >= (int)sizeof entry) {
                fprintf(stderr, "launcher: %s: path too long\\n", dir); return 1;
            }
            setenv("MYPHP_BUNDLE", dir, 1);
            const char *php = getenv("MYPHP_PHP"); if (php == NULL || *php == 0) php = $bin;
            const char *flags[] = {{$list}};
            char **args = calloc(argc + $n + 2, sizeof *args);
            int i = 0;
            args[i++] = (char *)php;
            for (int f = 0; f < $n; f++) args[i++] = (char *)flags[f];
            args[i++] = entry;
            for (int a = 1; a < argc; a++) args[i++] = argv[a];
            if (chdir(app) != 0) { perror("launcher: chdir"); return 1; }
            execv(php, args);
            perror("launcher: exec php");
            return 1;
        }

        C;
    }

    /** The Debian version of a semantic one: a pre-release sorts before its release with "~" (mygo's debVersion). */
    public static function debVersion(string $v): string
    {
        $v = ltrim($v, 'v');
        if (($i = strpos($v, '-')) !== false) {
            $v = substr($v, 0, $i) . '~' . str_replace('-', '.', substr($v, $i + 1));
        }
        return str_replace('+', '~', $v);
    }

    /** The entries of the app that packages install in /opt/<slug>: all but the desktop entry, the icon and the MIME package (mygo's optEntries). @param list<string> $app @return list<string> */
    public static function optEntries(array $app, string $name): array
    {
        return array_values(array_filter($app, fn(string $e) => !str_ends_with($e, '.desktop') && $e !== "$name.png" && $e !== "$name.xml"));
    }

    /** linux.command's names checked, as mygo's loadConfig checks them. @return list<string> */
    public static function commands(mixed $command): array
    {
        $names = match (true) {
            $command === null, $command === '' => [],
            is_string($command) => [$command],
            is_array($command) && array_is_list($command) => $command,
            default => throw new RuntimeException('linux.command is a command name, or a list of them, not ' . json_encode($command)),
        };
        foreach ($names as $i => $n) {
            if (!is_string($n) || preg_match(self::COMMAND, $n) !== 1) {
                throw new RuntimeException('linux.command ' . json_encode($n) . ' is not a command name (a letter or digit, then letters, digits, ., _, + or -)');
            }
            if (in_array($n, array_slice($names, 0, $i), true)) {
                throw new RuntimeException("linux.command names \"$n\" twice");
            }
        }
        return $names;
    }
}
