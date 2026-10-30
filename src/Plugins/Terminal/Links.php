<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Terminal;

/**
 * The URLs and file paths programs print as text, which a Command+click
 * opens (mygo's links.go).
 *
 * @internal
 */
final class Links
{
    private const URL = '~(?:https?|ftp|file)://[^\s"\'<>`\x00-\x1f]+|mailto:[^\s"\'<>`]+~u';
    /** Absolute, from ~, ./ or ../, with a slash, or a file name with a :line; each may end in :line or :line:col. */
    private const PATH = '~(?:(?:\~|\.{1,2})?/[^\s"\'<>`\x00-\x1f:]+|[\w.@+-]+(?:/[\w.@+-]+)+/?)(?::\d+(?::\d+)?)?|[\w@+-][\w.@+-]*\.\w+:\d+(?::\d+)?~u';

    /** The URL of a row, whose runes $text are in columns $cols, at column $col, and the columns it spans, x0 up to x1: ['', 0, 0] for none (mygo's urlAt). @param list<string> $text @param list<int> $cols @return array{0: string, 1: int, 2: int} */
    public static function urlAt(array $text, array $cols, int $col): array { return self::matchAt(self::URL, $text, $cols, $col); }

    /** The file path of a row at column $col, as urlAt() (mygo's pathAt). @param list<string> $text @param list<int> $cols @return array{0: string, 1: int, 2: int} */
    public static function pathAt(array $text, array $cols, int $col): array { return self::matchAt(self::PATH, $text, $cols, $col); }

    /** @param list<string> $text @param list<int> $cols @return array{0: string, 1: int, 2: int} */
    private static function matchAt(string $re, array $text, array $cols, int $col): array
    {
        $s = implode('', $text);
        if (preg_match_all($re, $s, $m, PREG_OFFSET_CAPTURE) === false) {
            return ['', 0, 0];
        }
        foreach ($m[0] as [$found, $at]) {
            $u = self::trimURL($found);
            if ($u === '') { continue; }
            $start = mb_strlen(substr($s, 0, $at), 'UTF-8');
            $end = $start + mb_strlen($u, 'UTF-8');
            if ($start < count($cols) && $cols[$start] <= $col && $col <= $cols[$end - 1]) {
                return [$u, $cols[$start], $cols[$end - 1] + 1];
            }
        }
        return ['', 0, 0];
    }

    /** Trims what likely ends the sentence around a URL rather than the URL: punctuation, and closing brackets that the URL did not open (mygo's trimURL). */
    public static function trimURL(string $u): string
    {
        while ($u !== '') {
            $last = $u[-1];
            if (str_contains('.,;:!?', $last)) {
                $u = substr($u, 0, -1);
                continue;
            }
            $open = ['(' => ')', '[' => ']', '{' => '}'];
            if (($o = array_search($last, $open, true)) !== false && substr_count($u, $o) < substr_count($u, $last)) {
                $u = substr($u, 0, -1);
                continue;
            }
            return $u;
        }
        return $u;
    }
}
