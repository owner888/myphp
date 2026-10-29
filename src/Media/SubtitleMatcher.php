<?php
declare(strict_types=1);

namespace MyPHP\Media;

/**
 * IINA's matching of subtitle files to the films of a folder
 * (AutoFileMatcher, FileGroup): the films and the subtitles beside them
 * and in the settings' search paths grouped into series by their names'
 * common beginnings (a series ends its group where its names differ by a
 * digit); a film's subtitles those of its series' subtitles of the same
 * number, and those whose name contains the film's; those of more of the
 * priority words first; the films matched to none given the subtitles of
 * the least edit distance, where the folder's films are a playlist.
 *
 * @internal Player
 */
final class SubtitleMatcher
{
    private const CHINESE_NUMBERS = ['零', '一', '二', '三', '四', '五', '六', '七', '八', '九', '十'];

    /**
     * The subtitle files of film $film, the first first: $mode 0 none, 1
     * those whose names contain the film's (mpv's fuzzy), 2 those of its
     * series too (IINA's); $priority words by commas, $searchPath folders
     * by colons (relative to the film's, "~" home, "/*" their folders),
     * $all where the folder's films are its playlist (playlistAutoAdd: the
     * films matched to none matched by edit distance).
     * @return list<string>
     */
    public static function match(string $film, int $mode, string $priority, string $searchPath, bool $all): array
    {
        if ($mode === 0) { return []; }
        $folder = dirname($film);
        $media = []; $subtitles = [];
        foreach (self::listed($folder) as $name) {
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (in_array($ext, Playlist::FILMS, true) || in_array($ext, Playlist::AUDIO, true)) { $media[] = self::info("$folder/$name"); }
            elseif (isset(Subtitles::FILES[$ext])) { $subtitles[] = self::info("$folder/$name"); }
        }
        usort($media, fn(object $a, object $b) => strnatcasecmp($a->filename, $b->filename));
        foreach (self::searchFolders($folder, $searchPath) as $dir) {
            foreach (self::listed($dir) as $name) {
                if (isset(Subtitles::FILES[strtolower(pathinfo($name, PATHINFO_EXTENSION))])) { $subtitles[] = self::info("$dir/$name"); }
            }
        }
        $real = realpath($film) ?: $film;
        $self = array_values(array_filter($media, fn(object $f) => (realpath($f->path) ?: $f->path) === $real))[0] ?? null;
        if ($self === null) { $media[] = $self = self::info($film); } // one of no film's extension, opened all the same

        $videoSeries = self::flatten(self::group('', $media));
        $subSeries = self::flatten(self::group('', $subtitles));
        $matchedPrefixes = $mode === 2 ? self::matchSeries($videoSeries, $subSeries) : [];
        $matched = []; // by film path
        $unmatched = [];
        $words = array_values(array_filter(array_map('trim', explode(',', $priority)), fn(string $w) => $w !== ''));
        foreach ($media as $video) {
            $found = [];
            if ($mode === 2 && $video->prefix !== '' && isset($matchedPrefixes[$video->prefix])) { // its series': the subtitles of its number
                foreach ($subtitles as $sub) {
                    if ($video->nameInSeries === null || $sub->nameInSeries === null) { continue; }
                    $same = ctype_digit($video->nameInSeries) && ctype_digit($sub->nameInSeries) ? (int)$video->nameInSeries === (int)$sub->nameInSeries : $video->nameInSeries === $sub->nameInSeries;
                    if ($same && $sub->prefix === $matchedPrefixes[$video->prefix]) { $found[] = $sub; $sub->matched = true; }
                }
            }
            foreach ($subtitles as $sub) { // those containing its name
                if (!$sub->matched && str_contains($sub->filename, $video->filename)) { $found[] = $sub; $sub->matched = true; }
            }
            if ($found === []) { $unmatched[] = $video; }
            elseif ($words !== []) { // those of more of the priority words than the least first
                $count = fn(object $s) => array_sum(array_map(fn(string $w) => substr_count($s->filename, $w), $words));
                $least = min(array_map($count, $found));
                $found = [...array_filter($found, fn(object $s) => $count($s) > $least), ...array_filter($found, fn(object $s) => $count($s) <= $least)];
            }
            $matched[$video->path] = array_values($found);
        }
        if ($all) { self::forceMatch($media, $unmatched, $subtitles, $matched); }
        $found = array_values(array_unique(array_map(fn(object $s) => $s->path, $matched[$self->path] ?? [])));
        \MyPHP\Logger::debug(sprintf('Matched %d of %d subtitles for %s (%d media files)', count($found), count($subtitles), basename($film), count($media)), 'fmatcher');
        return $found;
    }

    /** The files of $dir, hidden ones not, sorted. @return list<string> */
    private static function listed(string $dir): array
    {
        $out = [];
        foreach (is_dir($dir) ? (scandir($dir) ?: []) : [] as $name) {
            if ($name[0] !== '.' && is_file("$dir/$name")) { $out[] = $name; }
        }
        return $out;
    }

    /** The folders the search paths name: relative to $folder, "~" home, "/*" the folders in it. @return list<string> */
    private static function searchFolders(string $folder, string $paths): array
    {
        $out = [];
        foreach (array_filter(explode(':', $paths), fn(string $p) => $p !== '') as $path) {
            $p = str_starts_with($path, '~') ? (getenv('HOME') ?: '') . substr($path, 1) : $path;
            $p = rtrim($p, '/');
            $wild = str_ends_with($path, '/*');
            if ($wild) { $p = substr($p, 0, -2); }
            $dir = str_starts_with($path, '/') || str_starts_with($path, '~') ? $p : "$folder/$p";
            if ($wild) {
                foreach (is_dir($dir) ? (scandir($dir) ?: []) : [] as $name) {
                    if ($name[0] !== '.' && is_dir("$dir/$name")) { $out[] = "$dir/$name"; }
                }
            } else {
                $out[] = $dir;
            }
        }
        return $out;
    }

    /** IINA's FileInfo of $path: its name without its extension, its runes; its series' prefix '' until grouped. */
    private static function info(string $path): object
    {
        $name = pathinfo($path, PATHINFO_FILENAME);
        return (object)['path' => $path, 'filename' => $name, 'chars' => mb_str_split($name, 1, 'UTF-8'), 'prefix' => '', 'suffix' => $name, 'nameInSeries' => null, 'matched' => false, 'dist' => [], 'minDist' => []];
    }

    /** FileInfo's prefix set: the rest its suffix, its number in the series from it ("ch01_x" → "ch01"); one as long as its name none. */
    private static function setPrefix(object $f, string $prefix): void
    {
        $f->prefix = mb_strlen($prefix) < count($f->chars) ? $prefix : '';
        $f->suffix = implode('', array_slice($f->chars, mb_strlen($f->prefix)));
        if ($f->chars === []) { return; }
        $name = ''; $digits = false;
        foreach (mb_str_split($f->suffix, 1, 'UTF-8') as $ch) {
            if (preg_match('/^\p{Nd}$/u', $ch) === 1) { $digits = true; } elseif ($digits) { break; }
            $name .= $ch;
        }
        $f->nameInSeries = $name;
    }

    /**
     * FileGroup: $files of the common beginning $prefix split by the rune
     * after it, again in each part of three or more, until the runes there
     * are digits (or three Chinese numbers), or no part has three.
     * @param list<object> $files
     * @return array{prefix: string, files: list<object>, groups: list<array<string, mixed>>}
     */
    private static function group(string $prefix, array $files): array
    {
        if (count($files) < 3) {
            return ['prefix' => $prefix, 'files' => $files, 'groups' => []];
        }
        $temp = []; $chars = [];
        $i = mb_strlen($prefix);
        while (count($temp) < 2) {
            $last = $prefix; $any = false;
            foreach ($files as $f) {
                if ($i >= count($f->chars)) { // its name ended
                    $temp[$prefix][] = $f;
                    $chars[] = ['/', $prefix];
                    continue;
                }
                $c = $f->chars[$i];
                $p = $prefix . $c;
                $last = $p;
                if (!isset($temp[$p])) { $temp[$p] = []; $chars[] = [$c, $p]; }
                $temp[$p][] = $f;
                $any = true;
            }
            if (count($temp) === 1) { // all of one beginning still
                $prefix = $last;
                $temp = []; $chars = [];
            }
            $i++;
            if (!$any) { break; }
        }
        $most = $temp === [] ? 0 : max(array_map('count', $temp));
        if (self::stop($chars) || $most < 3) {
            foreach ($files as $f) { self::setPrefix($f, $prefix); }
            return ['prefix' => $prefix, 'files' => $files, 'groups' => []];
        }
        $groups = [];
        foreach ($temp as $p => $members) { $groups[] = self::group((string)$p, $members); }
        return ['prefix' => $prefix, 'files' => $files, 'groups' => $groups];
    }

    /** @param list<array{0: string, 1: string}> $chars */
    private static function stop(array $chars): bool
    {
        $chinese = 0;
        foreach ($chars as [$c]) {
            if ($c >= '0' && $c <= '9' && strlen($c) === 1) { return true; }
            if (in_array($c, self::CHINESE_NUMBERS, true) && ++$chinese >= 3) { return true; }
        }
        return false;
    }

    /** The groups' files by their prefixes, of the groups not split. @return array<string, list<object>> */
    private static function flatten(array $g): array
    {
        if ($g['groups'] === []) { return [$g['prefix'] => $g['files']]; }
        $out = [];
        foreach ($g['groups'] as $sub) { $out = self::flatten($sub) + $out; }
        return $out;
    }

    /**
     * The series of films matched to series of subtitles: each the closest
     * of the other by edit distance, nearer than 60% of their lengths.
     * @param array<string, list<object>> $videos @param array<string, list<object>> $subs
     * @return array<string, string> the subtitles' prefix by the films'
     */
    private static function matchSeries(array $videos, array $subs): array
    {
        $dist = []; $closest = [];
        foreach ($subs as $sp => $_) {
            $min = PHP_INT_MAX; $minVideo = '';
            foreach ($videos as $vp => $vl) {
                if (count($vl) <= 2) { continue; }
                $d = $dist[$sp][$vp] = self::distance((string)$vp, (string)$sp);
                if ($d < $min) { [$min, $minVideo] = [$d, (string)$vp]; }
            }
            $closest[$sp] = $minVideo;
        }
        $out = [];
        foreach ($videos as $vp => $vl) {
            if (count($vl) <= 2) { continue; }
            $min = PHP_INT_MAX; $minSub = '';
            foreach ($subs as $sp => $_) {
                $d = $dist[$sp][$vp];
                if ($d < $min) { [$min, $minSub] = [$d, (string)$sp]; }
            }
            if (($closest[$minSub] ?? null) === (string)$vp && $min < (int)((mb_strlen((string)$vp) + mb_strlen($minSub)) * 0.6)) { $out[(string)$vp] = $minSub; }
        }
        return $out;
    }

    /**
     * The films matched to none given the subtitles matched to none closest
     * to them (their beginnings' and ends' edit distances), each to the
     * films it is closest to; not of more than 100 by 100.
     * @param list<object> $media @param list<object> $unmatched @param list<object> $subtitles @param array<string, list<object>> $matched
     */
    private static function forceMatch(array $media, array $unmatched, array $subtitles, array &$matched): void
    {
        $subs = array_values(array_filter($subtitles, fn(object $s) => !$s->matched));
        if ($subs === [] || $unmatched === [] || count($unmatched) * count($subs) >= 100 * 100) { return; }
        foreach ($subs as $si => $sub) {
            $min = PHP_INT_MAX;
            foreach ($unmatched as $vi => $video) {
                $threshold = (int)((count($video->chars) + count($sub->chars)) * 0.6);
                $raw = self::distance($video->prefix, $sub->prefix) + self::distance($video->suffix, $sub->suffix);
                $d = $raw < $threshold ? $raw : PHP_INT_MAX;
                $sub->dist[$vi] = $d; $video->dist[$si] = $d;
                $min = min($min, $d);
            }
            if ($min === PHP_INT_MAX) { continue; }
            $sub->minDist = array_keys(array_filter($sub->dist, fn(int $d) => $d === $min));
        }
        foreach ($unmatched as $vi => $video) {
            $min = $video->dist === [] ? PHP_INT_MAX : min($video->dist);
            if ($min === PHP_INT_MAX) { continue; }
            foreach ($subs as $si => $sub) {
                if ($video->dist[$si] === $min && in_array($vi, $sub->minDist, true)) { $matched[$video->path][] = $sub; }
            }
        }
    }

    /** The edit distance of $a and $b, by runes. */
    private static function distance(string $a, string $b): int
    {
        $x = mb_str_split($a, 1, 'UTF-8'); $y = mb_str_split($b, 1, 'UTF-8');
        $prev = range(0, count($y));
        foreach ($x as $i => $cx) {
            $cur = [$i + 1];
            foreach ($y as $j => $cy) {
                $cur[] = min($prev[$j + 1] + 1, $cur[$j] + 1, $prev[$j] + ($cx === $cy ? 0 : 1));
            }
            $prev = $cur;
        }
        return $prev[count($y)];
    }
}
