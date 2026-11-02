<?php
declare(strict_types=1);

namespace MyPHP\Native\Build;

use MyPHP\Native\Update\Archive;
use MyPHP\Native\Zlib;
use RuntimeException;

/**
 * The Debian package of a Linux app (mygo's writeDeb): the app in
 * /opt/<slug>, a link in /usr/bin for each name of linux.command, its
 * desktop entry, its icons in the hicolor theme and its MIME package; an ar
 * archive of debian-binary, control.tar.gz and data.tar.gz, written here,
 * with entries under "./" owned by root.
 *
 * @internal
 */
final class Deb
{
    /** @var list<string> the directories the archive has */
    private array $dirs = [];
    private string $tar = '';

    private function __construct(private readonly int $mtime) {}

    /**
     * Writes the package of the app $app (the entries of $stage: the
     * launcher $name and the files next to it) into $stage and returns its
     * path. $icons are Linux::icons', by size; $langs the catalogs'
     * names, as Linux::desktopEntry takes them.
     *
     * @param list<string> $app
     * @param list<string> $depends
     * @param array<int, string> $icons
     * @param list<array{0: string, 1: string, 2: string}> $langs
     */
    public static function write(array $c, string $stage, string $name, string $goarch, array $app, array $depends, array $icons, array $langs): string
    {
        $arch = Linux::DEB_ARCH[$goarch] ?? throw new RuntimeException("no Debian architecture for $goarch");
        $data = new self(time());
        $opt = "opt/$name";
        foreach (['opt', $opt, 'usr', 'usr/share', 'usr/share/applications'] as $d) {
            $data->dir($d);
        }
        $size = 0;
        $md5sums = '';
        foreach (Linux::optEntries($app, $name) as $entry) {
            $data->walk($stage, $entry, $opt, $size, $md5sums);
        }
        $commands = $c['linux']['command'] ?? [];
        if ($commands !== []) {
            $data->dir('usr/bin');
        }
        foreach ($commands as $command) {
            $data->link("usr/bin/$command", "../../$opt/$name");
        }
        $add = function (string $path, string $bytes) use ($data, &$md5sums): void {
            $data->file($path, $bytes, 0o644);
            $md5sums .= md5($bytes) . "  $path\n";
        };
        if (($xml = Linux::mimePackage($c)) !== '') {
            // Types the app defines; the shared-mime-info trigger registers them.
            $data->dir('usr/share/mime');
            $data->dir('usr/share/mime/packages');
            $add("usr/share/mime/packages/$name.xml", $xml);
        }
        // The entry runs the app by its path, with or without a command.
        $add("usr/share/applications/$name.desktop", Linux::desktopEntry($c, "/$opt/$name", $name, $langs));
        foreach ($icons as $px => $png) {
            $dir = Linux::iconDir($px);
            foreach (['usr/share/icons', 'usr/share/icons/hicolor', dirname($dir), $dir] as $d) {
                $data->dir($d);
            }
            $add("$dir/$name.png", $png);
        }
        $ctl = new self($data->mtime);
        $ctl->file('control', self::control($c, $name, $arch, $size, $depends), 0o644);
        $ctl->file('md5sums', $md5sums, 0o644);
        $deb = "!<arch>\n";
        foreach (['debian-binary' => "2.0\n", 'control.tar.gz' => $ctl->gzip(), 'data.tar.gz' => $data->gzip()] as $member => $bytes) {
            $deb .= sprintf('%-16s%-12d%-6d%-6d%-8s%-10d`' . "\n", $member, $data->mtime, 0, 0, '100644', strlen($bytes)) . $bytes . (strlen($bytes) % 2 === 1 ? "\n" : '');
        }
        $out = "$stage/{$name}_" . Linux::debVersion($c['version']) . "_$arch.deb";
        file_put_contents($out, $deb) !== false || throw new RuntimeException("cannot write $out");
        return $out;
    }

    /** The control file of the package, $size the bytes of the app installed (mygo's). @param list<string> $depends */
    public static function control(array $c, string $name, string $arch, int $size, array $depends): string
    {
        $description = (string)($c['linux']['comment'] ?? '');
        if ($description === '') {
            $description = $c['name'];
        }
        return "Package: $name\nVersion: " . Linux::debVersion($c['version']) . "\nArchitecture: $arch\nMaintainer: {$c['linux']['maintainer']}\nInstalled-Size: " . intdiv($size + 1023, 1024)
            . "\nDepends: " . implode(', ', $depends) . "\nSection: utils\nPriority: optional\nDescription: $description\n {$c['name']} is a desktop application.\n";
    }

    /** Adds $rel of $stage, and what is in it, under $prefix: files 755 when executable, else 644; links as links. */
    private function walk(string $stage, string $rel, string $prefix, int &$size, string &$md5sums): void
    {
        $p = "$stage/$rel";
        $target = "$prefix/$rel";
        if (is_link($p)) {
            $this->link($target, readlink($p) ?: throw new RuntimeException("readlink $p failed"));
        } elseif (is_dir($p)) {
            $this->dir($target);
            $names = scandir($p) ?: throw new RuntimeException("cannot list $p");
            foreach ($names as $n) {
                if ($n !== '.' && $n !== '..') {
                    $this->walk($stage, "$rel/$n", $prefix, $size, $md5sums);
                }
            }
        } else {
            $b = file_get_contents($p);
            $b !== false || throw new RuntimeException("cannot read $p");
            $this->file($target, $b, (fileperms($p) & 0o111) !== 0 ? 0o755 : 0o644);
            $size += strlen($b);
            $md5sums .= md5($b) . "  $target\n";
        }
    }

    private function dir(string $name): void
    {
        if (!in_array($name, $this->dirs, true)) {
            $this->dirs[] = $name;
            $this->tar .= Archive::header("./$name/", '5', 0o755, 0, $this->mtime, '');
        }
    }

    private function file(string $name, string $data, int $mode): void
    {
        $this->tar .= Archive::header("./$name", '0', $mode, strlen($data), $this->mtime, '') . $data . str_repeat("\0", (512 - strlen($data) % 512) % 512);
    }

    private function link(string $name, string $target): void
    {
        $this->tar .= Archive::header("./$name", '2', 0o777, 0, $this->mtime, $target);
    }

    /** The archive, ended, gzip compressed. */
    private function gzip(): string
    {
        return Zlib::deflate($this->tar . str_repeat("\0", 1024), Zlib::GZIP, 9);
    }
}
