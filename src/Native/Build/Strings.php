<?php
declare(strict_types=1);

namespace MyPHP\Native\Build;

use MyPHP\Native\Plist;
use RuntimeException;

/**
 * Reads a .strings file, as an app ships an InfoPlist.strings among its
 * resources (mygo's parseStrings): the text format Xcode writes, "key" =
 * "value"; in UTF-8 or UTF-16 with a byte order mark, or a property list of
 * strings in XML.
 */
final class Strings
{
    /** @var list<string> the characters of the text */
    private array $s;
    private int $i = 0, $line = 1;

    /** @return array<string, string> @throws RuntimeException naming what is wrong and where */
    public static function parse(string $b): array
    {
        if (str_starts_with($b, "\xFF\xFE") || str_starts_with($b, "\xFE\xFF")) {
            $b = (string)mb_convert_encoding(substr($b, 2), 'UTF-8', $b[0] === "\xFE" ? 'UTF-16BE' : 'UTF-16LE');
        } elseif (str_starts_with($b, 'bplist')) {
            throw new RuntimeException('a binary property list: write it as text');
        }
        if (str_starts_with($b, "\xEF\xBB\xBF")) { $b = substr($b, 3); }
        $t = trim($b);
        if (str_starts_with($t, '<?xml') || str_starts_with($t, '<plist')) {
            return self::xml($t);
        }
        $p = new self;
        $p->s = mb_str_split($b);
        return $p->read();
    }

    /** A property list of a dictionary of strings. @return array<string, string> */
    private static function xml(string $t): array
    {
        $v = Plist::parse($t); // the text is UTF-8 by now, whatever its declaration names
        if (!is_array($v)) {
            throw new RuntimeException('a property list of a dictionary');
        }
        foreach ($v as $k => $s) {
            if (!is_string($s)) { throw new RuntimeException("$k: only strings"); }
        }
        return array_map('strval', $v);
    }

    /** "key" = "value"; or "key"; for the key itself, with /* *\/ and // comments, keys and values quoted or of letters, digits and _$+/:.-. @return array<string, string> */
    private function read(): array
    {
        $out = [];
        while (true) {
            $this->space();
            if ($this->i === count($this->s)) {
                return $out;
            }
            $key = $this->word();
            $value = $key;
            $this->space();
            if ($this->next('=')) {
                $this->space();
                $value = $this->word();
                $this->space();
            }
            if (!$this->next(';')) {
                throw $this->want('a ; after ' . json_encode($key, JSON_UNESCAPED_UNICODE));
            }
            $out[$key] = $value;
        }
    }

    private function want(string $what): RuntimeException
    {
        return new RuntimeException("line {$this->line}: want $what");
    }

    private function next(string $c): bool
    {
        if (($this->s[$this->i] ?? null) === $c) {
            $this->i++;
            return true;
        }
        return false;
    }

    /** Skips spaces and comments. */
    private function space(): void
    {
        $n = count($this->s);
        while ($this->i < $n) {
            $c = $this->s[$this->i];
            if ($c === "\n") {
                $this->line++; $this->i++;
            } elseif (preg_match('/^\s$/u', $c)) {
                $this->i++;
            } elseif ($c === '/' && ($this->s[$this->i + 1] ?? '') === '/') {
                while ($this->i < $n && $this->s[$this->i] !== "\n") { $this->i++; }
            } elseif ($c === '/' && ($this->s[$this->i + 1] ?? '') === '*') {
                $j = $this->i + 2;
                for (; $j + 1 < $n && !($this->s[$j] === '*' && $this->s[$j + 1] === '/'); $j++) {
                    if ($this->s[$j] === "\n") { $this->line++; }
                }
                if ($j + 1 >= $n) { throw $this->want('the end of a comment'); }
                $this->i = $j + 2;
            } else {
                return;
            }
        }
    }

    /** A key or a value: quoted, or a run of letters, digits and _$+/:.-. */
    private function word(): string
    {
        $n = count($this->s);
        if (!$this->next('"')) {
            $start = $this->i;
            while ($this->i < $n && preg_match('~^[\p{L}\p{N}_$+/:.\-]$~u', $this->s[$this->i])) { $this->i++; }
            if ($this->i === $start) { throw $this->want('a quoted string'); }
            return implode('', array_slice($this->s, $start, $this->i - $start));
        }
        $b = '';
        while (true) {
            if ($this->i === $n) { throw $this->want('the end of a string'); }
            $c = $this->s[$this->i++];
            if ($c === '"') {
                return $b;
            }
            if ($c === "\n") {
                $this->line++;
            } elseif ($c === '\\') {
                if ($this->i === $n) { throw $this->want('the end of a string'); }
                $e = $this->s[$this->i++];
                $c = match ($e) { 'n' => "\n", 't' => "\t", 'r' => "\r", 'a' => "\x07", 'b' => "\x08", 'f' => "\f", 'v' => "\v", default => $e }; // \" \\ \' and others stand for themselves
                if ($e === 'U' || $e === 'u') {
                    $u = $this->hex4($this->i) ?? throw $this->want("four hexadecimal digits after \\$e");
                    $this->i += 4;
                    // A character beyond the BMP is two escapes of its UTF-16 surrogates.
                    if ($u >= 0xD800 && $u <= 0xDBFF && ($this->s[$this->i] ?? '') === '\\' && in_array($this->s[$this->i + 1] ?? '', ['U', 'u'], true)
                        && ($lo = $this->hex4($this->i + 2)) !== null && $lo >= 0xDC00 && $lo <= 0xDFFF) {
                        $u = 0x10000 + (($u - 0xD800) << 10) + ($lo - 0xDC00);
                        $this->i += 6;
                    }
                    $c = mb_chr($u, 'UTF-8') ?: "\u{FFFD}";
                } elseif ($e >= '0' && $e <= '7') {
                    $v = (int)$e;
                    for ($k = 0; $k < 2 && $this->i < $n && $this->s[$this->i] >= '0' && $this->s[$this->i] <= '7'; $k++) { $v = $v * 8 + (int)$this->s[$this->i++]; }
                    $c = mb_chr($v, 'UTF-8');
                }
            }
            $b .= $c;
        }
    }

    /** The four hexadecimal digits at $at, or null. */
    private function hex4(int $at): ?int
    {
        $h = implode('', array_slice($this->s, $at, 4));
        return strlen($h) === 4 && ctype_xdigit($h) ? (int)hexdec($h) : null;
    }
}
