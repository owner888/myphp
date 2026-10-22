<?php
declare(strict_types=1);

namespace MyPHP\Native\Update;

/**
 * Go's encoding/binary varints: unsigned LEB128 (uvarint), and zigzag
 * signed ones (varint).
 *
 * @internal
 */
final class Varint
{
    public static function u(int $v): string
    {
        $out = '';
        while ($v >= 0x80 || $v < 0) {
            $out .= chr(($v & 0x7f) | 0x80);
            $v = ($v >> 7) & 0x01ffffffffffffff;
        }
        return $out . chr($v);
    }

    public static function s(int $v): string
    {
        return self::u(($v << 1) ^ ($v >> 63));
    }

    /** The uvarint at $at of $b, moving $at past it; null when it is cut short or too long. */
    public static function readU(string $b, int &$at): ?int
    {
        $v = 0;
        for ($shift = 0, $i = $at; $i < strlen($b); $i++, $shift += 7) {
            $c = ord($b[$i]);
            if ($shift >= 63 && $c > 1) {
                return null; // overflows 64 bits
            }
            $v |= ($c & 0x7f) << $shift;
            if ($c < 0x80) {
                $at = $i + 1;
                return $v;
            }
        }
        return null;
    }

    public static function readS(string $b, int &$at): ?int
    {
        $u = self::readU($b, $at);
        return $u === null ? null : (($u >> 1) & PHP_INT_MAX) ^ -($u & 1);
    }
}
