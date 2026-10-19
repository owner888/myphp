<?php
declare(strict_types=1);

namespace MyPHP\UI\Markdown\Parser;

/**
 * The scanners of Markdown's syntax (mygo's parser/scan.go), and the
 * character classes the parsers share: offsets are bytes, as the source's.
 */
final class Scan
{
    public static function isSpaceOrTab(string $c): bool { return $c === ' ' || $c === "\t"; }
    public static function isLineEnd(string $c): bool { return $c === "\n" || $c === "\r"; }
    public static function isDigit(string $c): bool { return $c >= '0' && $c <= '9' && $c !== ''; }
    public static function isLetter(string $c): bool { return $c !== '' && (($o = ord($c) | 0x20) >= 0x61 && $o <= 0x7a); }

    /** Whether a backslash escapes $c: ASCII punctuation. */
    public static function isEscapable(string $c): bool
    {
        if ($c === '') {
            return false;
        }
        $o = ord($c);
        return $o >= 0x21 && $o <= 0x2f || $o >= 0x3a && $o <= 0x40 || $o >= 0x5b && $o <= 0x60 || $o >= 0x7b && $o <= 0x7e;
    }

    /** The rune at byte $i of $s and its length, U+FFFD and 1 for a byte that starts none (Go's utf8.DecodeRuneInString); [0, 0] past the end. */
    public static function rune(string $s, int $i): array
    {
        $n = strlen($s);
        if ($i >= $n) {
            return [0xFFFD, 0];
        }
        $c = ord($s[$i]);
        if ($c < 0x80) {
            return [$c, 1];
        }
        [$len, $min, $v] = $c >= 0xC2 && $c <= 0xDF ? [2, 0x80, $c & 0x1F] : ($c >= 0xE0 && $c <= 0xEF ? [3, 0x800, $c & 0x0F] : ($c >= 0xF0 && $c <= 0xF4 ? [4, 0x10000, $c & 0x07] : [0, 0, 0]));
        if ($len === 0 || $i + $len > $n) {
            return [0xFFFD, 1];
        }
        for ($k = 1; $k < $len; $k++) {
            $b = ord($s[$i + $k]);
            if (($b & 0xC0) !== 0x80) {
                return [0xFFFD, 1];
            }
            $v = ($v << 6) | ($b & 0x3F);
        }
        if ($v < $min || $v > 0x10FFFF || $v >= 0xD800 && $v <= 0xDFFF) {
            return [0xFFFD, 1];
        }
        return [$v, $len];
    }

    /** The rune ending at byte $i of $s (Go's utf8.DecodeLastRuneInString of $s[:$i]). */
    public static function lastRune(string $s, int $i): int
    {
        $start = $i - 1;
        for ($lim = max(0, $i - 4); $start > $lim && (ord($s[$start]) & 0xC0) === 0x80; $start--) {}
        [$r, $n] = self::rune($s, $start);
        return $start + $n === $i ? $r : 0xFFFD;
    }

    /** Whether byte $c starts a rune (Go's utf8.RuneStart). */
    public static function runeStart(string $c): bool { return (ord($c) & 0xC0) !== 0x80; }

    /** Whether $s begins with a full rune (Go's utf8.FullRuneInString). */
    public static function fullRune(string $s): bool
    {
        if ($s === '') {
            return false;
        }
        $c = ord($s[0]);
        $len = $c < 0x80 ? 1 : ($c >= 0xC2 && $c <= 0xDF ? 2 : ($c >= 0xE0 && $c <= 0xEF ? 3 : ($c >= 0xF0 && $c <= 0xF4 ? 4 : 1)));
        if (strlen($s) >= $len) {
            return true;
        }
        for ($k = 1; $k < strlen($s); $k++) {
            if ((ord($s[$k]) & 0xC0) !== 0x80) {
                return true; // invalid: a rune of its own
            }
        }
        return false;
    }

    private static function is(int $r, string $class): bool
    {
        return preg_match('/^\p{' . $class . '}$/u', mb_chr($r, 'UTF-8')) === 1;
    }

    public static function isUnicodeSpace(int $r): bool
    {
        return $r === 0x20 || $r === 0x09 || $r === 0x0A || $r === 0x0D || $r === 0x0C || $r >= 0x80 && self::is($r, 'Zs');
    }

    /** Whether $r is punctuation or a symbol, as CommonMark counts them for flanking. */
    public static function isPunct(int $r): bool
    {
        if ($r < 0x80) {
            return self::isEscapable(chr($r));
        }
        return self::is($r, 'P') || self::is($r, 'S');
    }

    /** Unicode punctuation alone (Go's unicode.IsPunct). */
    public static function isUnicodePunct(int $r): bool { return self::is($r, 'P'); }

    public static function isAlnum(int $r): bool { return self::is($r, 'L') || self::is($r, 'Nd'); }

    /** The length of an ATX heading's opening sequence at $i with the spaces after it, or 0. */
    public static function atxStart(string $ln, int $i): int
    {
        $j = $i; $n = strlen($ln);
        while ($j < $n && $ln[$j] === '#' && $j - $i < 7) { $j++; }
        $k = $j - $i;
        if ($k === 0 || $k > 6) {
            return 0;
        }
        if ($j === $n) {
            return $k;
        }
        if (!self::isSpaceOrTab($ln[$j])) {
            return 0;
        }
        while ($j < $n && self::isSpaceOrTab($ln[$j])) { $j++; }
        return $j - $i;
    }

    /** The length of an opening code fence at $i, or 0. */
    public static function openFence(string $ln, int $i): int
    {
        $c = $ln[$i]; $j = $i; $n = strlen($ln);
        while ($j < $n && $ln[$j] === $c) { $j++; }
        if ($j - $i < 3) {
            return 0;
        }
        if ($c === '`' && strpos($ln, '`', $j) !== false) {
            return 0;
        }
        return $j - $i;
    }

    /** The length of a closing code fence at $i, or 0. */
    public static function closeFence(string $ln, int $i): int
    {
        $c = $ln[$i];
        if ($c !== '`' && $c !== '~') {
            return 0;
        }
        $j = $i; $n = strlen($ln);
        while ($j < $n && $ln[$j] === $c) { $j++; }
        $k = $j - $i;
        if ($k < 3) {
            return 0;
        }
        for (; $j < $n; $j++) {
            if (!self::isSpaceOrTab($ln[$j])) {
                return 0;
            }
        }
        return $k;
    }

    /** 1 for a line of =, 2 for one of -, or 0. */
    public static function setextLine(string $ln, int $i): int
    {
        $c = $ln[$i]; $j = $i; $n = strlen($ln);
        while ($j < $n && $ln[$j] === $c) { $j++; }
        for (; $j < $n; $j++) {
            if (!self::isSpaceOrTab($ln[$j])) {
                return 0;
            }
        }
        return $c === '=' ? 1 : 2;
    }

    /** The length of a footnote definition's start at $i, [^label]: and the spaces after it, or 0. */
    public static function footnoteDef(string $ln, int $i): int
    {
        if (substr($ln, $i, 2) !== '[^') {
            return 0;
        }
        $j = $i + 2; $n = strlen($ln);
        while ($j < $n && $ln[$j] !== ']') {
            if (str_contains(" \t\r\n\0", $ln[$j])) {
                return 0;
            }
            $j++;
        }
        if ($j === $i + 2 || $j + 1 >= $n || $ln[$j + 1] !== ':') {
            return 0;
        }
        $j += 2;
        while ($j < $n && self::isSpaceOrTab($ln[$j])) { $j++; }
        return $j - $i;
    }

    private const HTML_BLOCK_TAGS = ['address', 'article', 'aside', 'base', 'basefont', 'blockquote', 'body', 'caption', 'center', 'col', 'colgroup', 'dd', 'details', 'dialog', 'dir',
        'div', 'dl', 'dt', 'fieldset', 'figcaption', 'figure', 'footer', 'form', 'frame', 'frameset', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'head', 'header', 'hr', 'html', 'iframe', 'legend', 'li', 'link', 'main',
        'menu', 'menuitem', 'nav', 'noframes', 'ol', 'optgroup', 'option', 'p', 'param', 'search', 'section', 'summary', 'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'title', 'tr', 'track', 'ul'];

    private static function hasPrefixFold(string $s, string $prefix): bool
    {
        return strlen($s) >= strlen($prefix) && strcasecmp(substr($s, 0, strlen($prefix)), $prefix) === 0;
    }

    private static function isTagChar(string $c): bool { return self::isLetter($c) || self::isDigit($c); }

    /** The type (1 to 6) of an HTML block starting at $i, or 0. */
    public static function htmlBlockStart(string $ln, int $i): int
    {
        $s = substr($ln, $i); $n = strlen($s);
        if ($n < 2 || $s[0] !== '<') {
            return 0;
        }
        foreach (['<script', '<pre', '<style', '<textarea'] as $tag) {
            if (self::hasPrefixFold($s, $tag)) {
                $l = strlen($tag);
                if ($n === $l || $s[$l] === ' ' || $s[$l] === "\t" || $s[$l] === '>') {
                    return 1;
                }
            }
        }
        if (str_starts_with($s, '<!--')) { return 2; }
        if (str_starts_with($s, '<?')) { return 3; }
        if (str_starts_with($s, '<![CDATA[')) { return 5; }
        if ($n > 2 && $s[1] === '!' && self::isLetter($s[2])) { return 4; }
        $j = 1;
        if ($s[$j] === '/') {
            $j++;
        }
        $k = $j;
        while ($k < $n && self::isTagChar($s[$k])) { $k++; }
        if ($k === $j || !in_array(strtolower(substr($s, $j, $k - $j)), self::HTML_BLOCK_TAGS, true)) {
            return 0;
        }
        if ($k === $n || $s[$k] === ' ' || $s[$k] === "\t" || $s[$k] === '>' || substr($s, $k, 2) === '/>') {
            return 6;
        }
        return 0;
    }

    /** 7 for a line that is a complete open or closing tag, other than script, style, pre and textarea, followed only by spaces, or 0. */
    public static function htmlBlockStart7(string $ln, int $i): int
    {
        $s = substr($ln, $i);
        if (str_starts_with($s, '</')) {
            $n = self::closingTag($s, 0);
        } else {
            $n = self::openTag($s, 0);
            if ($n > 0) {
                $name = substr($s, 1); $k = 0;
                while ($k < strlen($name) && (self::isTagChar($name[$k]) || $name[$k] === '-')) { $k++; }
                if (in_array(strtolower(substr($name, 0, $k)), ['script', 'style', 'pre', 'textarea'], true)) {
                    return 0;
                }
            }
        }
        if ($n === 0 || trim(substr($s, $n), " \t") !== '') {
            return 0;
        }
        return 7;
    }

    /** Whether a line ends an HTML block of type $t. */
    public static function htmlBlockEnds(int $t, string $s): bool
    {
        switch ($t) {
            case 1:
                $l = strtolower($s);
                return str_contains($l, '</script>') || str_contains($l, '</pre>') || str_contains($l, '</style>') || str_contains($l, '</textarea>');
            case 2: return str_contains($s, '-->');
            case 3: return str_contains($s, '?>');
            case 4: return str_contains($s, '>');
            case 5: return str_contains($s, ']]>');
        }
        return false;
    }

    // The scanners of inline HTML (CommonMark's raw HTML) return the length of what they find at $i, or 0.

    private static function tagName(string $s, int $i): int
    {
        $n = strlen($s);
        if ($i >= $n || !self::isLetter($s[$i])) {
            return 0;
        }
        $j = $i + 1;
        while ($j < $n && (self::isTagChar($s[$j]) || $s[$j] === '-')) { $j++; }
        return $j - $i;
    }

    /** Skips whitespace within a tag. */
    private static function skipTagSpace(string $s, int $i): int
    {
        $n = strlen($s);
        while ($i < $n && ($s[$i] === ' ' || $s[$i] === "\t" || $s[$i] === "\n" || $s[$i] === "\r")) { $i++; }
        return $i;
    }

    public static function openTag(string $s, int $i): int
    {
        $len = strlen($s);
        if ($i >= $len || $s[$i] !== '<') {
            return 0;
        }
        $n = self::tagName($s, $i + 1);
        if ($n === 0) {
            return 0;
        }
        $j = $i + 1 + $n;
        while (true) {
            $k = self::skipTagSpace($s, $j);
            if ($k < $len && $s[$k] === '>') {
                return $k + 1 - $i;
            }
            if ($k + 1 < $len && $s[$k] === '/' && $s[$k + 1] === '>') {
                return $k + 2 - $i;
            }
            if ($k === $j) {
                return 0; // attributes need whitespace before them
            }
            // An attribute name.
            if ($k >= $len || !(self::isLetter($s[$k]) || $s[$k] === '_' || $s[$k] === ':')) {
                return 0;
            }
            $k++;
            while ($k < $len && (self::isTagChar($s[$k]) || str_contains('_.:-', $s[$k]))) { $k++; }
            // An optional value.
            $v = self::skipTagSpace($s, $k);
            if ($v < $len && $s[$v] === '=') {
                $v = self::skipTagSpace($s, $v + 1);
                if ($v >= $len) {
                    return 0;
                }
                if ($s[$v] === '"' || $s[$v] === "'") {
                    $e = strpos($s, $s[$v], $v + 1);
                    if ($e === false) {
                        return 0;
                    }
                    $k = $e + 1;
                } else {
                    $e = $v;
                    while ($e < $len && !str_contains(" \t\n\r\"'=<>`", $s[$e])) { $e++; }
                    if ($e === $v) {
                        return 0;
                    }
                    $k = $e;
                }
            }
            $j = $k;
        }
    }

    public static function closingTag(string $s, int $i): int
    {
        if (substr($s, $i, 2) !== '</') {
            return 0;
        }
        $n = self::tagName($s, $i + 2);
        if ($n === 0) {
            return 0;
        }
        $k = self::skipTagSpace($s, $i + 2 + $n);
        return $k < strlen($s) && $s[$k] === '>' ? $k + 1 - $i : 0;
    }

    /**
     * The length of the raw HTML at $i: a tag, a comment, a processing
     * instruction, a declaration or a CDATA section, or 0. $no remembers
     * that a kind found no end after a point, so that unclosed comments
     * repeated do not take quadratic time.
     *
     * @param array{pi: int, comment: int, cdata: int, decl: int} $no
     */
    public static function htmlTag(string $s, int $i, array &$no): int
    {
        $r = substr($s, $i);
        if (strlen($r) < 2) {
            return 0;
        }
        if ($r[1] === '/') {
            return self::closingTag($s, $i);
        }
        if ($r[1] === '?') {
            return self::until($s, $i, 2, '?>', $no['pi']);
        }
        if (str_starts_with($r, '<!--')) {
            if (str_starts_with($r, '<!-->')) {
                return 5;
            }
            if (str_starts_with($r, '<!--->')) {
                return 6;
            }
            return self::until($s, $i, 4, '-->', $no['comment']);
        }
        if (str_starts_with($r, '<![CDATA[')) {
            return self::until($s, $i, 9, ']]>', $no['cdata']);
        }
        if ($r[1] === '!') {
            return strlen($r) > 2 && self::isLetter($r[2]) ? self::until($s, $i, 3, '>', $no['decl']) : 0;
        }
        return self::openTag($s, $i);
    }

    /** The length up to and including $end, searched from $i + $skip, or 0; $none notes the offset after which there is none. */
    private static function until(string $s, int $i, int $skip, string $end, int &$none): int
    {
        if ($none >= 0 && $i + $skip >= $none) {
            return 0;
        }
        $k = strpos($s, $end, $i + $skip);
        if ($k === false) {
            $none = $i + $skip;
            return 0;
        }
        return $k - $i + strlen($end);
    }

    /** A table's delimiter row: cells of dashes, with a colon at either end for alignment, between pipes; null for none. @return ?list<int> */
    public static function delimiterRow(string $line): ?array
    {
        $line = rtrim($line, " \t");
        if ($line === '') {
            return null;
        }
        $pipes = substr_count($line, '|');
        $s = $line;
        if ($s[0] === '|') {
            $s = substr($s, 1);
        }
        if ($s !== '' && $s[strlen($s) - 1] === '|' && $pipes > 0 && (strlen($line) > 1 || $line[0] !== '|')) {
            $s = substr($s, 0, -1);
        }
        $aligns = [];
        foreach (explode('|', $s) as $part) {
            $part = trim($part, " \t");
            $left = str_starts_with($part, ':');
            $right = str_ends_with($part, ':') && strlen($part) > 1;
            if ($left) { $part = substr($part, 1); }
            if ($right) { $part = substr($part, 0, -1); }
            if ($part === '' || trim($part, '-') !== '') {
                return null;
            }
            $aligns[] = $left && $right ? Block::ALIGN_CENTER : ($left ? Block::ALIGN_LEFT : ($right ? Block::ALIGN_RIGHT : Block::ALIGN_NONE));
        }
        return $aligns === [] ? null : $aligns;
    }

    /** A table row's cells' byte ranges, trimmed: an optional pipe, then cells between pipes, whose \| do not split. @return list<array{0: int, 1: int}> */
    public static function splitRow(string $line): array
    {
        $cells = []; $i = 0; $n = strlen($line);
        if ($i < $n && $line[$i] === '|') {
            $i++;
            while ($i < $n && self::isSpaceOrTab($line[$i])) { $i++; }
        }
        while ($i < $n) {
            $j = $i;
            while ($j < $n && $line[$j] !== '|') {
                if ($line[$j] === '\\' && $j + 1 < $n && self::isEscapable($line[$j + 1])) {
                    $j++;
                }
                $j++;
            }
            $j = min($j, $n);
            $pipe = $j < $n;
            $a = $i; $b = $j;
            while ($a < $b && self::isSpaceOrTab($line[$a])) { $a++; }
            while ($b > $a && self::isSpaceOrTab($line[$b - 1])) { $b--; }
            if ($j > $i || $pipe) {
                $cells[] = [$a, $b];
            }
            if (!$pipe) {
                break;
            }
            $i = $j + 1;
            while ($i < $n && self::isSpaceOrTab($line[$i])) { $i++; }
        }
        return $cells;
    }

    /** Resolves backslash escapes and entities. */
    public static function unescape(string $s): string
    {
        if (!str_contains($s, '\\') && !str_contains($s, '&')) {
            return $s;
        }
        $b = ''; $n = strlen($s);
        for ($i = 0; $i < $n; $i++) {
            $c = $s[$i];
            if ($c === '\\' && $i + 1 < $n && self::isEscapable($s[$i + 1])) {
                $b .= $s[$i + 1];
                $i++;
            } elseif ($c === '&') {
                [$k, $text] = self::entity($s, $i);
                if ($k > 0) {
                    $b .= $text;
                    $i += $k - 1;
                } else {
                    $b .= $c;
                }
            } else {
                $b .= $c;
            }
        }
        return $b;
    }

    /**
     * The entity or numeric character reference at $i, as &amp; or &#35;:
     * its length and the text it stands for, or [0, ''] when none that
     * Markdown reads starts there (mygo's Entity and scanEntity).
     *
     * @return array{0: int, 1: string}
     */
    public static function entity(string $s, int $i = 0): array
    {
        $n = strlen($s);
        $j = $i + 1;
        if ($j < $n && $s[$j] === '#') {
            $j++;
            $hex = $j < $n && ($s[$j] === 'x' || $s[$j] === 'X');
            if ($hex) {
                $j++;
            }
            $start = $j; $v = 0;
            while ($j < $n) {
                $c = $s[$j];
                if (self::isDigit($c)) {
                    $d = ord($c) - 0x30;
                } elseif ($hex && ($l = ord($c) | 0x20) >= 0x61 && $l <= 0x66) {
                    $d = $l - 0x61 + 10;
                } else {
                    break;
                }
                if ($v < 0x110000) {
                    $v = $hex ? $v * 16 + $d : $v * 10 + $d;
                }
                $j++;
            }
            $k = $j - $start;
            if ($k === 0 || $hex && $k > 6 || !$hex && $k > 7 || $j >= $n || $s[$j] !== ';') {
                return [0, ''];
            }
            if ($v === 0 || $v > 0x10FFFF || $v >= 0xD800 && $v <= 0xDFFF) {
                $v = 0xFFFD;
            }
            return [$j + 1 - $i, mb_chr($v, 'UTF-8')];
        }
        $start = $j;
        while ($j < $n && $j - $start <= 32 && (self::isLetter($s[$j]) || self::isDigit($s[$j]))) { $j++; }
        if ($j === $start || $j >= $n || $s[$j] !== ';' || !self::isLetter($s[$start])) {
            return [0, ''];
        }
        $ent = substr($s, $i, $j + 1 - $i);
        $text = html_entity_decode($ent, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // A legacy name's prefix decodes, leaving the rest: &notit; is ¬it;.
        if ($text === $ent || str_ends_with($text, ';') && $ent !== '&semi;') {
            return [0, ''];
        }
        return [$j + 1 - $i, $text];
    }

    /** The offset in $source of byte $j of $text, the literal it reads as: escapes and entities decoded, the rest as it is. */
    public static function literalToSource(string $source, string $text, int $j): int
    {
        if ($source === $text) {
            return min($j, strlen($source));
        }
        $i = 0; $k = 0; $n = strlen($source); $tn = strlen($text);
        while ($i < $n && $k < $j) {
            $c = $source[$i];
            if ($c === '\\' && $i + 1 < $n && self::isEscapable($source[$i + 1]) && $k < $tn && $text[$k] === $source[$i + 1]) {
                $i += 2; $k++;
            } elseif ($c === '&') {
                [$m, $lit] = self::entity($source, $i);
                if ($m > 0 && substr($text, $k, strlen($lit)) === $lit) {
                    $i += $m; $k += strlen($lit);
                    continue;
                }
                $i++; $k++;
            } else {
                $i++; $k++;
            }
        }
        return $i;
    }
}
