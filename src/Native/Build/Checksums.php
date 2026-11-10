<?php
declare(strict_types=1);

namespace MyPHP\Native\Build;

/**
 * SHA256SUMS, the SHA-256 of the files a build publishes in the format of
 * sha256sum (mygo's checksums.go), which install.ps1 checks the Windows
 * installer against and people their downloads with:
 *
 *     sha256sum -c --ignore-missing SHA256SUMS
 *
 * An upload merges it with the one the release or bucket holds already
 * (another architecture's build), its own lines first.
 *
 * @internal bin/myphp, Upload
 */
final class Checksums
{
    public const NAME = 'SHA256SUMS';

    /** Writes SHA256SUMS of $files into $dir, each name once, and returns its path; '' for no files. @param list<string> $files */
    public static function write(string $dir, array $files): string
    {
        $sums = [];
        foreach ($files as $f) {
            $name = basename($f);
            if ($name !== self::NAME && !isset($sums[$name]) && is_file($f)) {
                $sums[$name] = (string)hash_file('sha256', $f);
            }
        }
        if ($sums === []) {
            return '';
        }
        file_put_contents("$dir/" . self::NAME, self::format($sums));
        return "$dir/" . self::NAME;
    }

    /** The lines of $sums, by name. @param array<string, string> $sums */
    public static function format(array $sums): string
    {
        ksort($sums, SORT_STRING);
        $out = '';
        foreach ($sums as $name => $sum) {
            $out .= "$sum  $name\n";
        }
        return $out;
    }

    /** The sums of a SHA256SUMS, by name. @return array<string, string> */
    public static function parse(string $text): array
    {
        $sums = [];
        foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
            if (preg_match('/^([0-9a-f]{64}) [ *](.+)$/', rtrim($line), $m) === 1) {
                $sums[$m[2]] ??= $m[1];
            }
        }
        return $sums;
    }

    /** $mine with the lines of $earlier for the names it does not have (another build's of the same release). */
    public static function merge(string $mine, string $earlier): string
    {
        return self::format(self::parse($mine) + self::parse($earlier));
    }
}
