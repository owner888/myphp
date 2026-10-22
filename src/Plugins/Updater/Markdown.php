<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Updater;

/**
 * The release notes of the update window (mygo's updater/internal/markdown):
 * what changelogs use, headings, paragraphs, nested lists, block quotes,
 * fenced code, rules, emphasis, code spans and links. Everything else is
 * text: raw HTML stays text, and links only go to http, https and mailto
 * URLs, so notes cannot run code in the window.
 *
 * A block is ['kind' => paragraph|heading|list|quote|code|rule, 'level',
 * 'inlines', 'text', 'ordered', 'items' (lists of blocks), 'blocks'], an
 * inline ['kind' => text|code|em|strong|link, 'text', 'children', 'url'].
 *
 * @internal
 */
final class Markdown
{
    /** @return list<array<string, mixed>> */
    public static function parse(string $src): array
    {
        $src = str_replace(["\r\n", "\t"], ["\n", '    '], $src);
        return self::blocks(explode("\n", $src));
    }

    /** @param list<string> $lines @return list<array<string, mixed>> */
    private static function blocks(array $lines): array
    {
        $blocks = [];
        $para = [];
        $flush = function () use (&$blocks, &$para): void {
            if ($para !== []) {
                $blocks[] = ['kind' => 'paragraph', 'inlines' => self::inline(implode("\n", $para))];
                $para = [];
            }
        };
        $n = count($lines);
        for ($i = 0; $i < $n;) {
            $line = $lines[$i];
            $t = trim($line);
            if ($t === '') {
                $flush();
                $i++;
            } elseif (self::isFence($t)) {
                $flush();
                $fence = substr($t, 0, 3);
                $code = [];
                for ($i++; $i < $n && !str_starts_with(trim($lines[$i]), $fence); $i++) {
                    $code[] = $lines[$i];
                }
                $i++;
                $blocks[] = ['kind' => 'code', 'text' => implode("\n", $code)];
            } elseif (($h = self::heading($t)) > 0) {
                $flush();
                $text = rtrim(trim(substr($t, $h)), '#');
                $blocks[] = ['kind' => 'heading', 'level' => $h, 'inlines' => self::inline(trim($text))];
                $i++;
            } elseif (self::isRule($t)) {
                $flush();
                $blocks[] = ['kind' => 'rule'];
                $i++;
            } elseif (str_starts_with($t, '>')) {
                $flush();
                $quote = [];
                for (; $i < $n && str_starts_with(trim($lines[$i]), '>'); $i++) {
                    $q = substr(trim($lines[$i]), 1);
                    $quote[] = str_starts_with($q, ' ') ? substr($q, 1) : $q;
                }
                $blocks[] = ['kind' => 'quote', 'blocks' => self::blocks($quote)];
            } elseif (self::listMarker($line)[2] && ($para === [] || self::startsList($line))) {
                $flush();
                [$list, $i] = self::parseList($lines, $i);
                $blocks[] = $list;
            } else {
                $para[] = $t;
                $i++;
            }
        }
        $flush();
        return $blocks;
    }

    /** The list starting at $lines[$i], and the index of the line after it. @return array{0: array<string, mixed>, 1: int} */
    private static function parseList(array $lines, int $i): array
    {
        [$indent, $ordered] = self::listMarker($lines[$i]);
        $list = ['kind' => 'list', 'ordered' => $ordered, 'items' => []];
        $n = count($lines);
        while ($i < $n) {
            [$ind, $ord, $ok] = self::listMarker($lines[$i]);
            if (!$ok || $ind !== $indent || $ord !== $ordered) {
                break;
            }
            // The item holds the rest of its first line, the lines indented to its content, lists nested less deep than that, and the lines that continue its paragraph.
            $width = self::markerWidth($lines[$i]);
            $item = [(string)substr($lines[$i], $width)];
            $blank = false;
            for ($i++; $i < $n; $i++) {
                $line = $lines[$i];
                if (trim($line) === '') {
                    $blank = true;
                    $item[] = '';
                    continue;
                }
                $ind = self::leadingSpaces($line);
                $marker = self::listMarker($line)[2];
                if ($ind >= $width) {
                    $item[] = (string)substr($line, $width);
                } elseif ($ind > $indent && $marker) {
                    $item[] = (string)substr($line, $ind);
                } elseif (!$blank && !$marker && !self::isBlockStart($line)) {
                    $item[] = trim($line);
                } else {
                    break;
                }
                $blank = false;
            }
            while ($item !== [] && end($item) === '') {
                array_pop($item);
            }
            $list['items'][] = self::blocks($item);
        }
        return [$list, $i];
    }

    /** Whether $line starts a list item, with the indentation of its marker and whether the list is ordered. @return array{0: int, 1: bool, 2: bool} */
    private static function listMarker(string $line): array
    {
        $indent = self::leadingSpaces($line);
        $rest = substr($line, $indent);
        if (strlen($rest) >= 2 && str_contains('-*+', $rest[0]) && $rest[1] === ' ') {
            return [$indent, false, !self::isRule(trim($rest))];
        }
        $n = 0;
        while ($n < strlen($rest) && $n < 9 && ctype_digit($rest[$n])) {
            $n++;
        }
        if ($n > 0 && strlen($rest) > $n + 1 && ($rest[$n] === '.' || $rest[$n] === ')') && $rest[$n + 1] === ' ') {
            return [$indent, true, true];
        }
        return [0, false, false];
    }

    /** The column where the content of the item $line starts begins: after its marker and the space after it. */
    private static function markerWidth(string $line): int
    {
        $w = self::leadingSpaces($line);
        while ($w < strlen($line) && $line[$w] !== ' ') {
            $w++;
        }
        $start = $w;
        while ($w < strlen($line) && $line[$w] === ' ' && $w - $start < 4) {
            $w++;
        }
        return $w;
    }

    /** Whether $line starts an item that may interrupt a paragraph: a bullet, or an ordered item numbered 1. */
    private static function startsList(string $line): bool
    {
        [, $ordered, $ok] = self::listMarker($line);
        return $ok && (!$ordered || str_starts_with(trim($line), '1'));
    }

    private static function isBlockStart(string $line): bool
    {
        $t = trim($line);
        return self::heading($t) > 0 || self::isFence($t) || self::isRule($t) || str_starts_with($t, '>');
    }

    private static function leadingSpaces(string $s): int
    {
        return strspn($s, ' ');
    }

    private static function heading(string $t): int
    {
        $n = 0;
        while ($n < strlen($t) && $n < 7 && $t[$n] === '#') {
            $n++;
        }
        if ($n === 0 || $n > 6 || strlen($t) > $n && $t[$n] !== ' ') {
            return 0;
        }
        return $n;
    }

    private static function isFence(string $t): bool
    {
        return str_starts_with($t, '```') || str_starts_with($t, '~~~');
    }

    private static function isRule(string $t): bool
    {
        if (strlen($t) < 3 || !str_contains('-*_', $t[0])) {
            return false;
        }
        $n = 0;
        foreach (mb_str_split($t) as $c) {
            if ($c === $t[0]) {
                $n++;
            } elseif ($c !== ' ') {
                return false;
            }
        }
        return $n >= 3;
    }

    /** Adds text to $in, merging runs. @param list<array<string, mixed>> $in */
    private static function text(array &$in, string $s): void
    {
        $n = count($in);
        if ($n > 0 && $in[$n - 1]['kind'] === 'text') {
            $in[$n - 1]['text'] .= $s;
            return;
        }
        $in[] = ['kind' => 'text', 'text' => $s];
    }

    /** Adds $children linked to $href, or the children alone when the URL is not one the window opens. */
    private static function link(array &$in, string $href, array $children): void
    {
        if (!self::safeURL($href)) {
            foreach ($children as $c) {
                if ($c['kind'] === 'text') {
                    self::text($in, $c['text']);
                } else {
                    $in[] = $c;
                }
            }
            return;
        }
        $in[] = ['kind' => 'link', 'url' => $href, 'children' => $children];
    }

    /** The inlines of the text of a paragraph or heading. @return list<array<string, mixed>> */
    private static function inline(string $s): array
    {
        $in = [];
        $len = strlen($s);
        for ($i = 0; $i < $len;) {
            $c = $s[$i];
            if ($c === '\\' && $i + 1 < $len && self::isPunct($s[$i + 1])) {
                self::text($in, $s[$i + 1]);
                $i += 2;
                continue;
            }
            if ($c === "\n") {
                self::text($in, ' ');
                $i++;
                continue;
            }
            if ($c === '`') {
                $n = strspn($s, '`', $i);
                $end = strpos($s, substr($s, $i, $n), $i + $n);
                if ($end !== false) {
                    $in[] = ['kind' => 'code', 'text' => trim(str_replace("\n", ' ', substr($s, $i + $n, $end - $i - $n)))];
                    $i = $end + $n;
                    continue;
                }
                self::text($in, substr($s, $i, $n));
                $i += $n;
                continue;
            }
            if ($c === '[' || $c === '!' && substr($s, $i, 2) === '![') {
                $image = $c === '!';
                $start = $image ? $i + 1 : $i;
                $l = self::parseLink(substr($s, $start));
                if ($l !== null) {
                    [$text, $href, $n] = $l;
                    self::link($in, $href, $image ? [['kind' => 'text', 'text' => $text]] : self::inline($text));
                    $i = $start + $n;
                    continue;
                }
            } elseif ($c === '<') {
                $end = strpos($s, '>', $i);
                if ($end !== false && $end > $i) {
                    $u = substr($s, $i + 1, $end - $i - 1);
                    if (strpbrk($u, ' <') === false && self::safeURL($u)) {
                        self::link($in, $u, [['kind' => 'text', 'text' => $u]]);
                        $i = $end + 1;
                        continue;
                    }
                }
            } elseif ($c === '*' || $c === '_') {
                if (!($c === '_' && $i > 0 && self::isWord($s[$i - 1]))) {
                    $n = min(strspn($s, $c, $i), 2);
                    $delim = substr($s, $i, $n);
                    $rest = (string)substr($s, $i + $n);
                    if ($rest !== '' && $rest[0] !== ' ' && $rest[0] !== "\n") {
                        $end = self::closing($rest, $delim);
                        if ($end > 0) {
                            $in[] = ['kind' => $n === 2 ? 'strong' : 'em', 'children' => self::inline(substr($rest, 0, $end))];
                            $i += $n + $end + $n;
                            continue;
                        }
                    }
                    self::text($in, $delim);
                    $i += $n;
                    continue;
                }
            } elseif ($c === 'h' && ($i === 0 || !self::isWord($s[$i - 1])) && (str_starts_with(substr($s, $i, 8), 'https://') || str_starts_with(substr($s, $i, 7), 'http://'))) {
                $end = $i;
                while ($end < $len && ord($s[$end]) > 32 && $s[$end] !== '<') {
                    $end++;
                }
                $u = rtrim(substr($s, $i, $end - $i), ".,:;!?\"')");
                if (substr_count($u, '(') > substr_count($u, ')') && $end > $i + strlen($u) && $s[$i + strlen($u)] === ')') {
                    $u .= ')';
                }
                self::link($in, $u, [['kind' => 'text', 'text' => $u]]);
                $i += strlen($u);
                continue;
            }
            $size = self::runeSize($s, $i);
            self::text($in, substr($s, $i, $size));
            $i += $size;
        }
        return $in;
    }

    /** The byte length of the UTF-8 sequence at $i, 1 for an invalid one (Go's DecodeRuneInString). */
    private static function runeSize(string $s, int $i): int
    {
        $b = ord($s[$i]);
        $n = match (true) { $b < 0x80 => 1, $b >= 0xf0 && $b < 0xf8 => 4, $b >= 0xe0 => 3, $b >= 0xc2 => 2, default => 1 };
        return $n > 1 && mb_check_encoding(substr($s, $i, $n), 'UTF-8') ? $n : 1;
    }

    /** "[text](url)" or `[text](url "title")` at the start of $s: the text, the URL and the length of the link. @return ?array{0: string, 1: string, 2: int} */
    private static function parseLink(string $s): ?array
    {
        $depth = 0;
        $end = -1;
        for ($i = 0; $i < strlen($s) && $end < 0; $i++) {
            if ($s[$i] === '\\') {
                $i++;
            } elseif ($s[$i] === '[') {
                $depth++;
            } elseif ($s[$i] === ']' && --$depth === 0) {
                $end = $i;
            }
        }
        if ($end < 0 || substr($s, $end + 1, 1) !== '(') {
            return null;
        }
        $close = strpos($s, ')', $end + 2);
        if ($close === false) {
            return null;
        }
        $dest = trim(substr($s, $end + 2, $close - $end - 2));
        $sp = strcspn($dest, " \n");
        $dest = substr($dest, 0, $sp); // drop the title
        if (str_starts_with($dest, '<')) {
            $dest = substr($dest, 1);
        }
        if (str_ends_with($dest, '>')) {
            $dest = substr($dest, 0, -1);
        }
        return [substr($s, 1, $end - 1), $dest, $close + 1];
    }

    /** Whether $u is a URL the window opens: http, https or mailto. */
    public static function safeURL(string $u): bool
    {
        $l = strtolower($u);
        return str_starts_with($l, 'https://') || str_starts_with($l, 'http://') || str_starts_with($l, 'mailto:');
    }

    /** The index of the delimiter closing an emphasis in $s, or -1. */
    private static function closing(string $s, string $delim): int
    {
        $d = strlen($delim);
        for ($i = 1; $i + $d <= strlen($s); $i++) {
            if ($s[$i] === '`') {
                // Code spans take precedence.
                $n = strspn($s, '`', $i);
                $end = strpos($s, substr($s, $i, $n), $i + $n);
                if ($end !== false) {
                    $i = $end + $n - 1;
                }
            } elseif ($s[$i] === '\\') {
                $i++;
            } elseif (substr($s, $i, $d) === $delim && $s[$i - 1] !== ' ' && $s[$i - 1] !== "\n") {
                if ($d === 1 && $i + 1 < strlen($s) && $s[$i + 1] === $delim) {
                    $i++; // part of a strong delimiter
                    continue;
                }
                if ($delim[0] === '_' && $i + $d < strlen($s) && self::isWord($s[$i + $d])) {
                    continue;
                }
                return $i;
            }
        }
        return -1;
    }

    private static function isPunct(string $c): bool
    {
        return str_contains("!\"#$%&'()*+,-./:;<=>?@[\\]^_`{|}~", $c);
    }

    private static function isWord(string $c): bool
    {
        return ctype_alnum($c) || ord($c) >= 0x80;
    }

    /** The blocks as HTML, all their text escaped. @param list<array<string, mixed>> $blocks */
    public static function html(array $blocks): string
    {
        return self::writeBlocks($blocks, false);
    }

    /** Blocks; $tight renders paragraphs without <p>, apart by <br>, as in the items of a list. */
    private static function writeBlocks(array $blocks, bool $tight): string
    {
        $b = '';
        $paras = 0;
        foreach ($blocks as $bl) {
            switch ($bl['kind']) {
                case 'paragraph':
                    if (!$tight) {
                        $b .= '<p>' . self::writeInlines($bl['inlines']) . '</p>';
                    } else {
                        $b .= ($paras > 0 ? '<br>' : '') . self::writeInlines($bl['inlines']);
                    }
                    $paras++;
                    break;
                case 'heading':
                    $b .= "<h{$bl['level']}>" . self::writeInlines($bl['inlines']) . "</h{$bl['level']}>";
                    break;
                case 'list':
                    $tag = $bl['ordered'] ? 'ol' : 'ul';
                    $b .= "<$tag>";
                    foreach ($bl['items'] as $item) {
                        $b .= '<li>' . self::writeBlocks($item, true) . '</li>';
                    }
                    $b .= "</$tag>";
                    break;
                case 'quote':
                    $b .= '<blockquote>' . self::writeBlocks($bl['blocks'], false) . '</blockquote>';
                    break;
                case 'code':
                    $b .= '<pre><code>' . self::escape($bl['text']) . '</code></pre>';
                    break;
                case 'rule':
                    $b .= '<hr>';
                    break;
            }
        }
        return $b;
    }

    private static function writeInlines(array $inlines): string
    {
        $b = '';
        foreach ($inlines as $in) {
            $b .= match ($in['kind']) {
                'text' => self::escape($in['text']),
                'code' => '<code>' . self::escape($in['text']) . '</code>',
                'em' => '<em>' . self::writeInlines($in['children']) . '</em>',
                'strong' => '<strong>' . self::writeInlines($in['children']) . '</strong>',
                'link' => '<a href="' . self::escape($in['url']) . '">' . self::writeInlines($in['children']) . '</a>',
            };
        }
        return $b;
    }

    /** Go's html.EscapeString: <, >, &, ' and ". */
    private static function escape(string $s): string
    {
        return strtr($s, ['<' => '&lt;', '>' => '&gt;', '&' => '&amp;', "'" => '&#39;', '"' => '&#34;']);
    }
}
