<?php
declare(strict_types=1);

namespace MyPHP;

use InvalidArgumentException;

/**
 * The operands of a number that the plural rules of CLDR read (mygo's
 * i18n.Operands; Unicode Technical Standard #35, Part 3, Plural Operand
 * Meanings), of its absolute value as it is written: 1.50 has two visible
 * fraction digits that 1.5 does not have, and in some languages another
 * plural. An integer part or a fraction of more than 18 digits keeps its
 * last 18, plus 10^18, so that it equals no number of the rules, which
 * are smaller, and its remainders by the powers of ten the rules divide
 * by stay right.
 */
final class Operands
{
    private const BIG = 1_000_000_000_000_000_000;

    public function __construct(
        /** The integer digits: 3 for 3.25. */
        public readonly int $i = 0,
        /** The number of visible fraction digits, with trailing zeros: 2 for 1.50. */
        public readonly int $v = 0,
        /** The same without trailing zeros: 1 for 1.50. */
        public readonly int $w = 0,
        /** The visible fraction digits as an integer, with trailing zeros: 50 for 1.50. */
        public readonly int $f = 0,
        /** The same without trailing zeros: 5 for 1.50. */
        public readonly int $t = 0,
        /** The exponent of compact decimal notation, as 6 for "1.2M" written 1.2c6, whose integer digits are then those of 1200000. */
        public readonly int $c = 0,
    ) {}

    /**
     * The operands of $n (mygo's NewOperands): an int, a float as PHP
     * writes it in the fewest digits (1.5, never 1.50), a string of a
     * decimal number, which keeps the fraction digits it writes ("1.50",
     * "-3", "1.2c6" or "1.2e6" in CLDR's compact notation), or Operands.
     * A minus sign is ignored. Throws for anything else.
     */
    public static function of(int|float|string|self $n): self
    {
        if ($n instanceof self) {
            return $n;
        }
        if (is_int($n)) {
            if ($n === PHP_INT_MIN) {
                return new self(223_372_036_854_775_808 + self::BIG); // 2^63, which no int holds, as its last 18 digits
            }
            $a = abs($n);
            return new self($a >= self::BIG ? $a % self::BIG + self::BIG : $a);
        }
        if (is_float($n)) {
            if (!is_finite($n)) {
                throw new InvalidArgumentException('i18n: ' . (is_nan($n) ? 'NAN' : ($n > 0 ? 'INF' : '-INF')) . ' is not a finite number'); // spelled out: PHP 8.5 warns of a NAN made a string
            }
            return self::parse(self::shortest($n));
        }
        return self::parse($n);
    }

    /** $f in the fewest digits that read back as it, without an exponent (Go's strconv.FormatFloat(f, 'f', -1, 64)). */
    public static function shortest(float $f): string
    {
        $s = var_export($f, true); // the shortest that reads back, as serialize_precision -1 writes it
        if (!preg_match('/^(-?)(\d+)(?:\.(\d+))?E([+-]\d+)$/i', $s, $m)) {
            return str_ends_with($s, '.0') ? substr($s, 0, -2) : $s;
        }
        [$sign, $int, $frac, $exp] = [$m[1], $m[2], $m[3] ?? '', (int)$m[4]];
        $digits = $int . $frac; $point = strlen($int) + $exp;
        if ($point <= 0) {
            $out = '0.' . str_repeat('0', -$point) . $digits;
        } elseif ($point >= strlen($digits)) {
            $out = $digits . str_repeat('0', $point - strlen($digits));
        } else {
            $out = substr($digits, 0, $point) . '.' . substr($digits, $point);
        }
        $out = ltrim($out, '0');
        $out = $out === '' || $out[0] === '.' ? "0$out" : $out;
        return $sign . (str_contains($out, '.') ? rtrim(rtrim($out, '0'), '.') : $out);
    }

    /** Reads a decimal number: an optional sign, digits, an optional fraction, and an optional exponent of compact notation (mygo's parseOperands). */
    private static function parse(string $s): self
    {
        $num = trim($s);
        if (str_starts_with($num, '-')) { $num = substr($num, 1); }
        if (str_starts_with($num, '+')) { $num = substr($num, 1); }
        $exp = 0;
        if (($i = strcspn($num, 'ceCE')) < strlen($num)) {
            $e = substr($num, $i + 1);
            if (!ctype_digit($e) || (int)$e > 100) {
                throw new InvalidArgumentException("i18n: \"$s\" is not a decimal number");
            }
            [$num, $exp] = [substr($num, 0, $i), (int)$e];
        }
        $point = strpos($num, '.');
        [$int, $frac] = $point === false ? [$num, ''] : [substr($num, 0, $point), substr($num, $point + 1)];
        $digits = fn(string $d) => $d === '' || ctype_digit($d);
        if ($int === '' && $point === false || $point !== false && $frac === '' || !$digits($int) || !$digits($frac)) {
            throw new InvalidArgumentException("i18n: \"$s\" is not a decimal number");
        }
        if ($exp > 0) { // the exponent moves the point to the right
            $shift = min($exp, strlen($frac));
            $int .= substr($frac, 0, $shift) . str_repeat('0', $exp - $shift);
            $frac = substr($frac, $shift);
        }
        $trimmed = rtrim($frac, '0');
        return new self(self::value($int), strlen($frac), strlen($trimmed), self::value($frac), self::value($trimmed), $exp);
    }

    /** The value of decimal digits, or of their last 18 plus 10^18 when they have more significant ones (mygo's digitsValue). */
    private static function value(string $d): int
    {
        $d = ltrim($d, '0');
        if (strlen($d) > 18) {
            return (int)substr($d, -18) + self::BIG;
        }
        return $d === '' ? 0 : (int)$d;
    }
}
