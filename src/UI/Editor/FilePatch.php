<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

use RuntimeException;

/** The change of one file in a unified diff, as git diff and diff -u print them (mygo's FilePatch). */
final class FilePatch
{
    /** The file's names before and after, without git's a/ and b/; $oldName is empty for a file added, $newName for one deleted. */
    public string $oldName = '', $newName = '';
    /** Git's file modes, as "100644", when given. */
    public string $oldMode = '', $newMode = '';
    /** A change git shows no lines of. */
    public bool $binary = false;
    /** @var list<PatchHunk> */
    public array $hunks = [];

    /**
     * The files a unified diff changes, in order (mygo's ParsePatch): those
     * of git diff and git format-patch, with renames, modes and new and
     * deleted files, and those of diff -u. Throws for a malformed hunk.
     * @return list<FilePatch>
     */
    public static function parse(string $text): array
    {
        // Lines split at \n alone: the lines of a file with \r\n endings keep their \r, as apply needs, and only the headers lose it.
        $lines = explode("\n", $text);
        $files = [];
        $cur = null;
        $start = function () use (&$files): FilePatch { return $files[] = new FilePatch; };
        $after = fn(string $line, string $prefix) => substr($line, strlen($prefix));
        for ($i = 0; $i < count($lines); $i++) {
            $line = self::trimCR($lines[$i]);
            if (str_starts_with($line, 'diff --git ')) {
                $cur = $start();
                if (($n = self::gitNames(substr($line, strlen('diff --git ')))) !== null) { [$cur->oldName, $cur->newName] = $n; }
            } elseif ($cur !== null && str_starts_with($line, 'new file mode ')) {
                $cur->oldName = ''; $cur->newMode = $after($line, 'new file mode ');
            } elseif ($cur !== null && str_starts_with($line, 'deleted file mode ')) {
                $cur->newName = ''; $cur->oldMode = $after($line, 'deleted file mode ');
            } elseif ($cur !== null && str_starts_with($line, 'old mode ')) {
                $cur->oldMode = $after($line, 'old mode ');
            } elseif ($cur !== null && str_starts_with($line, 'new mode ')) {
                $cur->newMode = $after($line, 'new mode ');
            } elseif ($cur !== null && str_starts_with($line, 'rename from ')) {
                $cur->oldName = self::unquoteName($after($line, 'rename from '));
            } elseif ($cur !== null && str_starts_with($line, 'rename to ')) {
                $cur->newName = self::unquoteName($after($line, 'rename to '));
            } elseif ($cur !== null && str_starts_with($line, 'copy from ')) {
                $cur->oldName = self::unquoteName($after($line, 'copy from '));
            } elseif ($cur !== null && str_starts_with($line, 'copy to ')) {
                $cur->newName = self::unquoteName($after($line, 'copy to '));
            } elseif ($cur !== null && (str_starts_with($line, 'Binary files ') || $line === 'GIT binary patch')) {
                $cur->binary = true;
            } elseif (str_starts_with($line, '--- ') && $i + 1 < count($lines) && str_starts_with($lines[$i + 1], '+++ ')) {
                if ($cur === null || $cur->hunks !== []) { $cur = $start(); }
                if (($a = self::patchName(substr($line, 4), 'a/')) !== '' || $cur->oldName === '') { $cur->oldName = $a; }
                if (($b = self::patchName(substr(self::trimCR($lines[$i + 1]), 4), 'b/')) !== '' || $cur->newName === '') { $cur->newName = $b; }
                $i++;
            } elseif (str_starts_with($line, '@@ ')) {
                $cur ??= $start();
                $h = self::parseHunkHeader($line, $i + 1);
                // The hunk's lines, as many as its header counts.
                $oldLeft = $h->oldLines; $newLeft = $h->newLines;
                while ($i + 1 < count($lines) && ($oldLeft > 0 || $newLeft > 0 || str_starts_with($lines[$i + 1], '\\'))) {
                    $l = $lines[$i + 1];
                    if (str_starts_with($l, '\\')) {
                    } elseif (str_starts_with($l, '+')) {
                        $newLeft--;
                    } elseif (str_starts_with($l, '-')) {
                        $oldLeft--;
                    } elseif (str_starts_with($l, ' ') || $l === '' || $l === "\r") {
                        // An empty line is a blank line of both, as some tools strip the space.
                        if (!str_starts_with($l, ' ')) { $l = ' ' . $l; }
                        $oldLeft--; $newLeft--;
                    } else {
                        throw new RuntimeException(sprintf('line %d: unexpected %s in a hunk', $i + 2, json_encode($l)));
                    }
                    if ($oldLeft < 0 || $newLeft < 0) { throw new RuntimeException(sprintf('line %d: hunk %s has more lines than it counts', $i + 2, json_encode($line))); }
                    $h->lines[] = $l;
                    $i++;
                }
                if ($oldLeft > 0 || $newLeft > 0) { throw new RuntimeException(sprintf('line %d: hunk %s ends early', $i + 1, json_encode($line))); }
                $cur->hunks[] = $h;
            }
        }
        return $files;
    }

    private static function trimCR(string $s): string { return str_ends_with($s, "\r") ? substr($s, 0, -1) : $s; }

    private static function trimPrefix(string $s, string $p): string { return str_starts_with($s, $p) ? substr($s, strlen($p)) : $s; }

    /** The names of "diff --git a/x b/x", or null. @return ?array{0: string, 1: string} */
    private static function gitNames(string $s): ?array
    {
        // Names git quotes, as C strings, when they hold spaces, quotes or characters past ASCII.
        if (str_starts_with($s, '"')) {
            if (preg_match('/^"(?:[^"\\\\]|\\\\.)*"/', $s, $m) !== 1) { return null; }
            $a = self::unquoteName($m[0]);
            $b = self::unquoteName(ltrim(substr($s, strlen($m[0])), ' '));
            return [self::trimPrefix($a, 'a/'), self::trimPrefix($b, 'b/')];
        }
        if (($i = strpos($s, ' "b/')) !== false) {
            return [self::trimPrefix(substr($s, 0, $i), 'a/'), self::trimPrefix(self::unquoteName(substr($s, $i + 1)), 'b/')];
        }
        // The names are equal but for their prefixes unless renamed, which rename lines then tell.
        if (($n = strlen($s)) % 2 === 1) {
            $a = substr($s, 0, intdiv($n, 2)); $b = substr($s, intdiv($n, 2) + 1);
            if (self::trimPrefix($a, 'a/') === self::trimPrefix($b, 'b/')) { return [self::trimPrefix($a, 'a/'), self::trimPrefix($b, 'b/')]; }
        }
        if (($i = strpos($s, ' b/')) !== false) { return [self::trimPrefix(substr($s, 0, $i), 'a/'), substr($s, $i + 3)]; }
        return null;
    }

    /** A name git quoted unquoted, as "a/\346\226\207.txt". */
    private static function unquoteName(string $s): string
    {
        return strlen($s) >= 2 && $s[0] === '"' && $s[strlen($s) - 1] === '"' ? stripcslashes(substr($s, 1, -1)) : $s;
    }

    /** The name of a --- or +++ line, '' for /dev/null. */
    private static function patchName(string $s, string $prefix): string
    {
        if (($i = strpos($s, "\t")) !== false) { $s = substr($s, 0, $i); }
        $s = trim($s);
        if ($s === '/dev/null') { return ''; }
        return self::trimPrefix(self::unquoteName($s), $prefix);
    }

    private static function parseHunkHeader(string $line, int $at): PatchHunk
    {
        $end = strpos(substr($line, 3), ' @@');
        $ranges = $end === false ? [] : preg_split('/\s+/', trim(substr($line, 3, $end)));
        if (count($ranges) !== 2 || !str_starts_with($ranges[0], '-') || !str_starts_with($ranges[1], '+')) { throw new RuntimeException("line $at: malformed hunk header"); }
        $h = new PatchHunk;
        [$h->oldStart, $h->oldLines] = self::hunkRange(substr($ranges[0], 1), $at);
        [$h->newStart, $h->newLines] = self::hunkRange(substr($ranges[1], 1), $at);
        $h->section = trim(substr($line, 3 + $end + 3));
        return $h;
    }

    /** @return array{0: int, 1: int} */
    private static function hunkRange(string $s, int $at): array
    {
        $parts = explode(',', $s, 2);
        foreach ($parts as $p) { if (preg_match('/^[+-]?\d+$/', $p) !== 1) { throw new RuntimeException("line $at: malformed hunk range $s"); } }
        return [(int)$parts[0], count($parts) === 2 ? (int)$parts[1] : 1];
    }

    /** The new file's text, the patch applied to the old file's $old, checking that the lines of both and those deleted are there; throws when they are not. */
    public function apply(string $old): string
    {
        if ($this->binary) { throw new RuntimeException('ui: binary patch'); }
        $lines = [];
        $eol = true;
        if ($old !== '') {
            $lines = explode("\n", $old);
            if ($lines[count($lines) - 1] === '') { array_pop($lines); } else { $eol = false; }
        }
        $out = [];
        $at = 0;
        // Whether the new text ends with a newline: as the old does, unless a hunk reaching the end says.
        $newEOL = $eol; $touchedEnd = false;
        foreach ($this->hunks as $h) {
            $start = $h->oldLines === 0 ? $h->oldStart : $h->oldStart - 1;
            if ($start < $at || $start > count($lines)) { throw new RuntimeException("ui: hunk at line {$h->oldStart} out of place"); }
            array_push($out, ...array_slice($lines, $at, $start - $at));
            $at = $start;
            $lastNew = -1; // the index in $h->lines of the last line of the new text
            foreach ($h->lines as $k => $l) {
                if ($l === '' || $l[0] === '\\') { continue; }
                if ($l[0] !== '+') {
                    if ($at >= count($lines) || $lines[$at] !== substr($l, 1)) { throw new RuntimeException(sprintf('ui: hunk at line %d does not match line %d', $h->oldStart, $at + 1)); }
                    $at++;
                }
                if ($l[0] !== '-') { $out[] = substr($l, 1); $lastNew = $k; }
            }
            if ($at === count($lines)) {
                $touchedEnd = true;
                $newEOL = !($lastNew >= 0 && $lastNew + 1 < count($h->lines) && str_starts_with($h->lines[$lastNew + 1], '\\'));
                if ($lastNew < 0) { $newEOL = true; }
            }
        }
        array_push($out, ...array_slice($lines, $at));
        if (!$touchedEnd) { $newEOL = $eol; }
        $s = implode("\n", $out);
        if ($newEOL && $out !== []) { $s .= "\n"; }
        return $s;
    }
}
