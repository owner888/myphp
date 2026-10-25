<?php
declare(strict_types=1);

namespace MyPHP\Native\Build;

use RuntimeException;

/**
 * What an app ships with besides its code (mygo's resources.go): the
 * contents of the project's resources directory, installed at the same
 * paths in the bundle's Contents/Resources (App::path('resources')), with
 * those of its platform directories named after the system the app is for
 * (resources/darwin, resources/darwin-arm64), and the extra files and
 * directories myphp.json lists under "resources", under their base names.
 * A universal app combines darwin-arm64 with darwin-amd64: their Mach-O
 * files become universal binaries.
 *
 * A resource is ['name' => its path there, with slashes, 'src' => what is
 * copied there ('' makes a directory where directories of several sources
 * merge), 'nested' => whether src is inside a directory of resources,
 * whose links are copied as links, 'lipo' => the x86_64 half of a
 * universal binary whose arm64 half is src, or ''].
 *
 * @internal
 */
final class Resources
{
    public const DIR = 'resources';
    /** The file name of the icon in Contents/Resources (mygo's bundleIcon). */
    public const ICON = 'AppIcon.icns';
    /** The name of the app icon in the asset catalog, and its file name (mygo's bundleIconName, bundleCatalog). */
    public const ICON_NAME = 'AppIcon', CATALOG = 'Assets.car';

    // Platform directories are named after a system apps are built for, alone or with an architecture: darwin, linux-arm64, windows-amd64.
    private const OS = ['darwin', 'linux', 'windows'];
    private const ARCH = ['386', 'amd64', 'arm', 'arm64', 'loong64', 'mips', 'mips64', 'mips64le', 'mipsle', 'ppc64', 'ppc64le', 'riscv64', 's390x'];
    // What other tools call systems and architectures, to catch platform directories named like theirs, which would ship to every platform.
    private const OS_ALIASES = ['darwin' => 'darwin', 'macos' => 'darwin', 'mac' => 'darwin', 'osx' => 'darwin', 'linux' => 'linux', 'windows' => 'windows', 'win' => 'windows', 'win32' => 'windows', 'win64' => 'windows'];
    private const ARCH_ALIASES = ['x64' => 'amd64', 'x86_64' => 'amd64', 'aarch64' => 'arm64', 'x86' => '386', 'ia32' => '386', 'i386' => '386', 'i686' => '386'];

    /** @var list<array{name: string, src: string, nested: bool, lipo: string}> */
    private array $list = [];
    /** @var array<string, string> the packaging's own files, by lower-case name */
    private array $reserved = [];

    private function __construct(private readonly string $root, private readonly string $configName) {}

    /**
     * Whether $name, an entry of the resources directory, is a platform
     * directory, and for which system and architecture: the architecture is
     * '' for all, and 'universal' for darwin-universal, which only universal
     * macOS apps ship.
     *
     * @return ?array{string, string}
     */
    public static function platformDir(string $name): ?array
    {
        $parts = explode('-', $name, 2);
        $goos = $parts[0];
        if (!in_array($goos, self::OS, true)) {
            return null;
        }
        if (count($parts) === 1) {
            return [$goos, ''];
        }
        return in_array($parts[1], self::ARCH, true) || $name === 'darwin-universal' ? [$goos, $parts[1]] : null;
    }

    /** The platform directory that $name, an entry of the resources directory that is not one, looks meant to be, or ''. */
    public static function platformDirTypo(string $name): string
    {
        $lower = strtolower($name);
        if (self::platformDir($lower) !== null) {
            return $lower; // Linux, Darwin-ARM64
        }
        if (in_array($lower, ['macos', 'osx', 'win32', 'win64'], true)) {
            return self::OS_ALIASES[$lower];
        }
        $parts = explode('-', $lower, 2);
        $arch = $parts[1] ?? '';
        $arch = self::ARCH_ALIASES[$arch] ?? $arch;
        $want = (self::OS_ALIASES[$parts[0]] ?? '') . '-' . $arch;
        return self::platformDir($want) !== null ? $want : '';
    }

    /**
     * What the app for $goos/$goarch ships with: the entries of the
     * project's resources directory and of its platform directories named
     * $goos and $goos-$goarch, whose directories merge, then $listed, the
     * resources of the configuration, under their base names, and
     * $natives, the native libraries of the plugins (installed at the top
     * like listed resources). Names starting with a dot are left out of
     * directories. Installed paths must be unique, ignoring case as macOS
     * does, and top-level names must differ from $reserved, the files the
     * packaging adds itself.
     *
     * @param list<string> $listed paths, relative to $root or absolute
     * @param list<array{0: string, 1: string, 2?: string}> $natives [file name, path, and for a universal app the darwin-amd64 build's]
     * @param list<string> $reserved
     * @return list<array{name: string, src: string, nested: bool, lipo: string}>
     */
    public static function collect(string $root, string $configName, array $listed, string $goos, string $goarch, array $natives = [], array $reserved = []): array
    {
        $m = new self($root, $configName);
        foreach ($reserved as $name) {
            $m->reserved[strtolower($name)] = $name;
        }
        $dir = "$root/" . self::DIR;
        $entries = [];
        if (file_exists($dir)) {
            if (!is_dir($dir)) {
                throw new RuntimeException("$dir is not a directory");
            }
            $entries = self::entryNames($dir, false);
        }
        $top = [];
        $platforms = [];
        foreach ($entries as $name) {
            $path = "$dir/$name";
            if (self::hidden($name)) {
                continue;
            }
            if (self::platformDir($name) !== null) {
                if (!is_dir($path)) {
                    throw new RuntimeException($m->rel($path) . ' is not a directory, but named like the resources of a platform');
                }
                $platforms[$name] = true;
                continue;
            }
            if (($want = self::platformDirTypo($name)) !== '') {
                throw new RuntimeException($m->rel($path) . ' would ship to every platform: name the resources of one platform after its system and architecture, as in ' . $m->rel("$dir/$want"));
            }
            $top[] = self::source($name, $path);
        }
        foreach ([$goos, "$goos-$goarch"] as $name) {
            if (isset($platforms[$name])) {
                array_push($top, ...$m->children(self::source('', "$dir/$name"), false));
            }
        }
        if ($goos === 'darwin' && $goarch === 'universal') {
            $arm = "$dir/darwin-arm64"; $amd = "$dir/darwin-amd64";
            if (isset($platforms['darwin-arm64'], $platforms['darwin-amd64'])) {
                array_push($top, ...$m->children(self::source('', $arm, $amd), false));
            } elseif (isset($platforms['darwin-arm64']) || isset($platforms['darwin-amd64'])) {
                if (isset($platforms['darwin-amd64'])) {
                    [$arm, $amd] = [$amd, $arm];
                }
                throw new RuntimeException('a universal app combines ' . $m->rel($arm) . ' with ' . $m->rel($amd) . ', which does not exist (files for both architectures go in ' . self::DIR . '/darwin)');
            }
        }
        foreach ($listed as $p) {
            $src = str_starts_with($p, '/') ? $p : "$root/$p";
            if (rtrim(self::clean($src), '/') === self::clean($dir)) {
                throw new RuntimeException("$configName: resources lists $p, whose contents are always included; list only extra files");
            }
            if (!file_exists($src)) {
                throw new RuntimeException("$configName: resource $p: no such file or directory");
            }
            $top[] = ['listed' => true] + self::source(basename($src), $src);
        }
        foreach ($natives as $n) {
            $top[] = ['listed' => true] + self::source($n[0], $n[1], $n[2] ?? '');
        }
        $m->merge($top);
        return $m->list;
    }

    /** The name of a platform directory for $goos and another architecture when there is none for $goarch: the app likely lacks what the other holds; '' otherwise. */
    public static function otherArch(string $root, string $goos, string $goarch): string
    {
        if ($goarch === 'universal') {
            return ''; // combines darwin-arm64 and darwin-amd64
        }
        $dir = "$root/" . self::DIR;
        $other = '';
        foreach (is_dir($dir) ? self::entryNames($dir, false) : [] as $name) {
            $p = self::platformDir($name);
            if ($p === null || $p[0] !== $goos || $p[1] === '') {
                continue;
            }
            if ($p[1] === $goarch) {
                return '';
            }
            if ($other === '') {
                $other = $name;
            }
        }
        return $other;
    }

    /** @return array{name: string, path: string, amd64: string, nested: bool, listed: bool} */
    private static function source(string $name, string $path, string $amd64 = '', bool $nested = false): array
    {
        return ['name' => $name, 'path' => $path, 'amd64' => $amd64, 'nested' => $nested, 'listed' => false];
    }

    private static function clean(string $p): string
    {
        $out = [];
        foreach (explode('/', $p) as $part) {
            if ($part === '' || $part === '.') { continue; }
            if ($part === '..') { array_pop($out); continue; }
            $out[] = $part;
        }
        return '/' . implode('/', $out);
    }

    /** @param array{path: string, nested: bool} $s */
    private static function stat(array $s, string $path): ?array
    {
        $st = $s['nested'] ? @lstat($path) : @stat($path);
        return $st === false ? null : $st;
    }

    /**
     * Adds sources to the list. Sources installed at the same place, or at
     * names that differ only in case, must be directories of the same name,
     * which merge, and not listed in the configuration.
     *
     * @param list<array{name: string, path: string, amd64: string, nested: bool, listed: bool}> $sources
     */
    private function merge(array $sources): void
    {
        $groups = [];
        foreach ($sources as $s) {
            $groups[strtolower($s['name'])][] = $s;
        }
        foreach ($groups as $key => $group) {
            if (isset($this->reserved[$key])) {
                throw new RuntimeException('resource ' . $this->rel($group[0]['path']) . " would replace the app's own {$this->reserved[$key]}");
            }
            $this->place($group);
        }
    }

    /** Adds the sources installed at one place: a source, a universal pair, which merges when it is two directories and combines when it is not, or directories that merge. @param non-empty-list<array{name: string, path: string, amd64: string, nested: bool, listed: bool}> $group */
    private function place(array $group): void
    {
        $first = $group[0];
        if (count($group) === 1 && $first['amd64'] === '') {
            $this->list[] = ['name' => $first['name'], 'src' => $first['path'], 'nested' => $first['nested'], 'lipo' => ''];
            return;
        }
        $children = [];
        foreach ($group as $i => $s) {
            $dir = $this->isDir($s);
            if (!$dir && count($group) === 1) {
                $this->combine($s);
                return;
            }
            if (!$dir || $s['name'] !== $first['name'] || $s['listed'] || $first['listed']) {
                $other = $i > 0 ? $s : $group[1];
                throw new RuntimeException('resources ' . $this->rel($first['path']) . ' and ' . $this->rel($other['path']) . " would both be installed as {$other['name']}");
            }
            array_push($children, ...$this->children($s, true));
        }
        $this->list[] = ['name' => $first['name'], 'src' => '', 'nested' => false, 'lipo' => ''];
        $this->merge($children);
    }

    /** Whether $s is a directory: for a universal pair, both halves or neither must be. */
    private function isDir(array $s): bool
    {
        $st = self::stat($s, $s['path']) ?? throw new RuntimeException("{$s['path']}: no such file or directory");
        $dir = ($st['mode'] & 0170000) === 0040000;
        if ($s['amd64'] !== '') {
            $other = self::stat($s, $s['amd64']) ?? throw new RuntimeException("{$s['amd64']}: no such file or directory");
            if ((($other['mode'] & 0170000) === 0040000) !== $dir) {
                throw new RuntimeException($this->rel($s['path']) . ' and ' . $this->rel($s['amd64']) . ' differ: a universal app holds one of them');
            }
        }
        return $dir;
    }

    /**
     * The entries of the directory $s, installed under its name, and for a
     * universal pair, the pairs of entries of its directories, which must
     * have the same names.
     *
     * @return list<array{name: string, path: string, amd64: string, nested: bool, listed: bool}>
     */
    private function children(array $s, bool $nested): array
    {
        $names = self::entryNames($s['path'], true);
        if ($s['amd64'] !== '') {
            $amd64 = self::entryNames($s['amd64'], true);
            foreach (array_diff($names, $amd64) as $n) {
                throw $this->unpaired("{$s['path']}/$n", $s['amd64']);
            }
            foreach (array_diff($amd64, $names) as $n) {
                throw $this->unpaired("{$s['amd64']}/$n", $s['path']);
            }
        }
        $list = [];
        foreach ($names as $n) {
            $list[] = self::source($s['name'] !== '' ? "{$s['name']}/$n" : $n, "{$s['path']}/$n", $s['amd64'] !== '' ? "{$s['amd64']}/$n" : '', $nested);
        }
        return $list;
    }

    /** @return list<string> the names in $dir, sorted, but (with $visible) those starting with a dot */
    private static function entryNames(string $dir, bool $visible): array
    {
        $names = @scandir($dir);
        if ($names === false) {
            throw new RuntimeException("cannot read $dir");
        }
        return array_values(array_filter($names, fn(string $n) => $n !== '.' && $n !== '..' && (!$visible || !self::hidden($n))));
    }

    /**
     * Adds the files or links of a universal pair: an arm64 and an x86_64
     * Mach-O file become a universal binary, and identical links and files,
     * but code for one architecture, are installed once. Anything else
     * cannot be one file for both architectures.
     */
    private function combine(array $s): void
    {
        $arm = self::stat($s, $s['path']);
        $amd = self::stat($s, $s['amd64']);
        $type = fn(array $st): int => $st['mode'] & 0170000;
        if ($type($arm) === 0100000 && $type($amd) === 0100000) {
            $armCpu = MachO::cpu($s['path']);
            $amdCpu = MachO::cpu($s['amd64']);
            if ($armCpu === MachO::CPU_ARM64 && $amdCpu === MachO::CPU_AMD64) {
                $this->list[] = ['name' => $s['name'], 'src' => $s['path'], 'nested' => $s['nested'], 'lipo' => $s['amd64']];
                return;
            }
            if ($armCpu === null && filesize($s['path']) === filesize($s['amd64']) && sha1_file($s['path']) === sha1_file($s['amd64'])) { // the code of one architecture would not run on the other
                $this->list[] = ['name' => $s['name'], 'src' => $s['path'], 'nested' => $s['nested'], 'lipo' => ''];
                return;
            }
            $a = MachO::kind($s['path']); $b = MachO::kind($s['amd64']);
            if ($a !== '' || $b !== '') {
                throw new RuntimeException('cannot make a universal binary of ' . $this->rel($s['path']) . ' (' . ($a ?: 'not code') . ') and ' . $this->rel($s['amd64']) . ' (' . ($b ?: 'not code') . '): it takes arm64 and x86_64 code');
            }
        } elseif ($type($arm) === 0120000 && $type($amd) === 0120000 && readlink($s['path']) === readlink($s['amd64'])) {
            $this->list[] = ['name' => $s['name'], 'src' => $s['path'], 'nested' => $s['nested'], 'lipo' => ''];
            return;
        }
        throw new RuntimeException($this->rel($s['path']) . ' and ' . $this->rel($s['amd64']) . ' differ: a universal app holds one of them (files for both architectures go in ' . self::DIR . '/darwin)');
    }

    private function unpaired(string $path, string $dir): RuntimeException
    {
        return new RuntimeException($this->rel($path) . ' has no counterpart in ' . $this->rel($dir) . ' to make a universal app with (files for both architectures go in ' . self::DIR . '/darwin)');
    }

    /** $path relative to the project, for messages. */
    private function rel(string $path): string
    {
        return str_starts_with($path, $this->root . '/') ? substr($path, strlen($this->root) + 1) : $path;
    }

    private static function hidden(string $name): bool
    {
        return str_starts_with($name, '.');
    }

    /** The files the packaging puts in Contents/Resources, which resources must not replace (mygo's reservedNames for darwin). @return list<string> */
    public static function reserved(bool $catalog): array
    {
        return $catalog ? [self::ICON, self::CATALOG] : [self::ICON];
    }

    /**
     * Copies the resources into $dir, like `cp -RH`: a source is followed
     * when it is a link, links inside it are copied as links; permissions
     * are kept, so executables stay executable. The halves of a universal
     * binary are combined.
     *
     * @param list<array{name: string, src: string, nested: bool, lipo: string}> $list
     */
    public static function copy(array $list, string $dir): void
    {
        foreach ($list as $r) {
            $dst = "$dir/{$r['name']}";
            if ($r['src'] === '') {
                @mkdir($dst, 0755, true);
                continue;
            }
            if (self::within($dir, $r['src'])) {
                throw new RuntimeException("resource {$r['src']} contains the directory it is copied to");
            }
            if ($r['lipo'] !== '') {
                MachO::writeUniversal($dst, $r['src'], $r['lipo']);
                chmod($dst, fileperms($r['src']) & 0777);
                continue;
            }
            self::walk($r['src'], $r['nested'], function (string $path, array $st) use ($r, $dst): void {
                $target = $dst . substr($path, strlen($r['src']));
                $type = $st['mode'] & 0170000;
                if ($type === 0120000) {
                    symlink((string)readlink($path), $target) || throw new RuntimeException("cannot link $target");
                } elseif ($type === 0040000) {
                    is_dir($target) || mkdir($target, 0755, true) || throw new RuntimeException("cannot create $target");
                } elseif ($type === 0100000) {
                    copy($path, $target) || throw new RuntimeException("cannot copy $path");
                    chmod($target, $st['mode'] & 0777);
                } else {
                    throw new RuntimeException("resource $path is not a regular file");
                }
            });
        }
    }

    /**
     * Calls $fn for $src and, when it is a directory, everything in it, in
     * the order copy() copies them (names starting with a dot left out).
     * Only links inside it are not followed, or with $nested, the source
     * itself too.
     *
     * @param \Closure(string, array): void $fn
     */
    public static function walk(string $src, bool $nested, \Closure $fn): void
    {
        $st = $nested ? @lstat($src) : @stat($src);
        if ($st === false) {
            throw new RuntimeException("$src: no such file or directory");
        }
        $walk = function (string $path, array $st) use (&$walk, $fn): void {
            $fn($path, $st);
            if (($st['mode'] & 0170000) !== 0040000) {
                return;
            }
            foreach (self::entryNames($path, true) as $n) {
                $walk("$path/$n", lstat("$path/$n")); // links are not followed
            }
        };
        $walk($src, $st);
    }

    /** Whether $path is $dir or inside it, links resolved. */
    private static function within(string $path, string $dir): bool
    {
        $p = realpath($path) ?: $path;
        $d = realpath($dir) ?: $dir;
        return $p === $d || str_starts_with($p, rtrim($d, '/') . '/');
    }
}
