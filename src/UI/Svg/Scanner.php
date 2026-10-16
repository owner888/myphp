<?php
declare(strict_types=1);

namespace MyPHP\UI\Svg;

/** Reads the numbers, names and separators of attribute values (mygo's internal/svg scanner). */
final class Scanner
{
    public int $i = 0;

    public function __construct(public readonly string $s) {}

    public function done(): bool { return $this->i >= strlen($this->s); }

    public function peek(): string { return $this->s[$this->i] ?? ''; }

    public function eat(string $c): bool
    {
        if (($this->s[$this->i] ?? '') === $c) { $this->i++; return true; }
        return false;
    }

    public static function isSpace(string $c): bool { return $c === ' ' || $c === "\t" || $c === "\n" || $c === "\r" || $c === "\f"; }

    public function skipSpace(): void
    {
        $n = strlen($this->s);
        while ($this->i < $n && self::isSpace($this->s[$this->i])) { $this->i++; }
    }

    /** Skips white space with at most one comma in it. */
    public function skipSep(): void
    {
        $this->skipSpace();
        if ($this->eat(',')) { $this->skipSpace(); }
    }

    /** A name of letters, digits, hyphens and underscores. */
    public function ident(): string
    {
        $start = $this->i; $n = strlen($this->s);
        while ($this->i < $n && (ctype_alnum($this->s[$this->i]) || $this->s[$this->i] === '-' || $this->s[$this->i] === '_' || ord($this->s[$this->i]) >= 0x80)) { $this->i++; }
        return substr($this->s, $start, $this->i - $start);
    }

    /** A finite number: an optional sign, digits with an optional fraction, and an optional exponent; null for none. */
    public function number(): ?float
    {
        $s = $this->s; $start = $this->i; $i = $start; $n = strlen($s);
        if ($i < $n && ($s[$i] === '+' || $s[$i] === '-')) { $i++; }
        $digits = 0;
        while ($i < $n && ctype_digit($s[$i])) { $i++; $digits++; }
        if ($i < $n && $s[$i] === '.') {
            $i++;
            while ($i < $n && ctype_digit($s[$i])) { $i++; $digits++; }
        }
        if ($digits === 0) { return null; }
        if ($i < $n && ($s[$i] === 'e' || $s[$i] === 'E')) { // an exponent, unless the e starts a unit such as em
            $j = $i + 1;
            if ($j < $n && ($s[$j] === '+' || $s[$j] === '-')) { $j++; }
            if ($j < $n && ctype_digit($s[$j])) {
                while ($j < $n && ctype_digit($s[$j])) { $j++; }
                $i = $j;
            }
        }
        $v = (float)substr($s, $start, $i - $start);
        if (is_infinite($v) || is_nan($v)) { return null; }
        $this->i = $i;
        return $v;
    }

    /** The numbers of a list separated by white space or commas, up to the first that is not one. @return list<float> */
    public static function numbers(string $s): array
    {
        $out = []; $p = new self($s);
        while (true) {
            $p->skipSep();
            $v = $p->number();
            if ($v === null) { return $out; }
            $out[] = $v;
        }
    }

    /** Parses a whole string as one number, or null. */
    public static function parseNumber(string $v): ?float
    {
        $p = new self(trim($v));
        $n = $p->number();
        return $n !== null && $p->done() ? $n : null;
    }
}
