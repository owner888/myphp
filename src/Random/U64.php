<?php
declare(strict_types=1);

namespace MyPHP\Random;

/**
 * Unsigned 64-bit arithmetic on the bits of PHP's ints, wrapping as Go's
 * uint64 does: PHP's ints are signed, and their sums and products past
 * 63 bits turn into floats, so these work in 16- and 32-bit pieces.
 */
final class U64
{
    private const M16 = 0xffff, M32 = 0xffffffff;

    /** $a + $b and the carry out of the 64 bits. @return array{0: int, 1: int} */
    public static function add(int $a, int $b): array
    {
        $lo = ($a & self::M32) + ($b & self::M32);
        $hi = (($a >> 32) & self::M32) + (($b >> 32) & self::M32) + ($lo >> 32);
        return [(($hi & self::M32) << 32) | ($lo & self::M32), $hi >> 32];
    }

    /** The low 64 bits of $a × $b. */
    public static function mul(int $a, int $b): int
    {
        return self::mulFull($a, $b)[1];
    }

    /** The 128 bits of $a × $b, high and low (Go's bits.Mul64). @return array{0: int, 1: int} */
    public static function mulFull(int $a, int $b): array
    {
        $x = [$a & self::M16, ($a >> 16) & self::M16, ($a >> 32) & self::M16, ($a >> 48) & self::M16];
        $y = [$b & self::M16, ($b >> 16) & self::M16, ($b >> 32) & self::M16, ($b >> 48) & self::M16];
        $r = [];
        $carry = 0;
        for ($k = 0; $k < 8; $k++) {
            $sum = $carry;
            for ($i = max(0, $k - 3); $i <= min(3, $k); $i++) {
                $sum += $x[$i] * $y[$k - $i]; // each below 2^32: a column's sum stays an int
            }
            $r[$k] = $sum & self::M16;
            $carry = $sum >> 16;
        }
        return [$r[4] | $r[5] << 16 | $r[6] << 32 | $r[7] << 48, $r[0] | $r[1] << 16 | $r[2] << 32 | $r[3] << 48];
    }

    /** $a shifted right by $s (1 to 63) bits, zeros coming in. */
    public static function shr(int $a, int $s): int
    {
        return ($a >> $s) & (PHP_INT_MAX >> ($s - 1));
    }

    /** Whether $a < $b, as unsigned. */
    public static function less(int $a, int $b): bool
    {
        return ($a ^ PHP_INT_MIN) < ($b ^ PHP_INT_MIN);
    }

    /** $a mod $b, as unsigned; $b above 0 and below 2^62. */
    public static function mod(int $a, int $b): int
    {
        return $a >= 0 ? $a % $b : (2 * (self::shr($a, 1) % $b) + ($a & 1)) % $b;
    }

    /** $a as a decimal number, as unsigned. */
    public static function string(int $a): string
    {
        return $a >= 0 ? (string)$a : (string)intdiv(self::shr($a, 1), 5) . (string)(self::mod($a, 10));
    }
}
